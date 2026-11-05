<?php
declare(strict_types=1);

namespace App;

/**
 * Server-side Supabase access (PostgREST + Storage) using the service-role key.
 * The service key bypasses RLS, so every query here filters by user_id explicitly.
 */
final class Supabase
{
    private string $url;
    private string $secretKey;
    private string $bucket;

    public function __construct()
    {
        $this->url = rtrim(Env::require('SUPABASE_URL'), '/');
        $this->secretKey = Env::require('SUPABASE_SECRET_KEY');
        $this->bucket = Env::get('SUPABASE_BUCKET', 'documents');
    }

    /** Server-side calls: the secret key (sb_secret_...) goes in the apikey header only. */
    private function headers(array $extra = []): array
    {
        return array_merge(['apikey' => $this->secretKey], $extra);
    }

    // ------------------------------------------------------------------ auth
    /** Validates a user's access token by asking Supabase Auth who it belongs to. */
    public function userFromToken(string $token): array
    {
        [$status, $body] = HttpClient::request('GET', "{$this->url}/auth/v1/user", [
            'apikey' => $this->secretKey,
            'Authorization' => "Bearer $token",
        ], null, 15);
        if ($status !== 200) {
            throw new HttpException(401, 'Invalid or expired session');
        }
        return json_decode($body, true) ?: [];
    }

    // ------------------------------------------------------------- documents
    public function insertDocument(array $row): array
    {
        $res = HttpClient::json('POST', "{$this->url}/rest/v1/documents", $this->headers(['Prefer' => 'return=representation']), $row);
        return $res[0] ?? [];
    }

    public function updateDocument(string $id, string $userId, array $patch): array
    {
        $q = http_build_query(['id' => "eq.$id", 'user_id' => "eq.$userId"]);
        $res = HttpClient::json('PATCH', "{$this->url}/rest/v1/documents?$q", $this->headers(['Prefer' => 'return=representation']), $patch);
        return $res[0] ?? [];
    }

    public function getDocument(string $id, string $userId): array
    {
        $q = http_build_query(['id' => "eq.$id", 'user_id' => "eq.$userId", 'select' => '*']);
        $res = HttpClient::json('GET', "{$this->url}/rest/v1/documents?$q", $this->headers());
        if (!$res) {
            throw new HttpException(404, 'Document not found');
        }
        return $res[0];
    }

    public function listDocuments(string $userId): array
    {
        $q = http_build_query([
            'user_id' => "eq.$userId",
            'select' => 'id,filename,mime_type,size_bytes,status,document_type,title,flag_counts,error,created_at,processed_at,usage',
            'order' => 'created_at.desc',
        ]);
        return HttpClient::json('GET', "{$this->url}/rest/v1/documents?$q", $this->headers());
    }

    /** @return list<string> storage paths of every file a user uploaded */
    public function storagePathsForUser(string $userId): array
    {
        $q = http_build_query(['user_id' => "eq.$userId", 'select' => 'storage_path']);
        return array_column(HttpClient::json('GET', "{$this->url}/rest/v1/documents?$q", $this->headers()), 'storage_path');
    }

    public function deleteDocument(string $id, string $userId): void
    {
        $q = http_build_query(['id' => "eq.$id", 'user_id' => "eq.$userId"]);
        HttpClient::json('DELETE', "{$this->url}/rest/v1/documents?$q", $this->headers());
    }

    // ----------------------------------------------------------- user admin
    /** @return list<array> */
    public function listUsers(): array
    {
        $res = HttpClient::json('GET', "{$this->url}/auth/v1/admin/users?per_page=1000", $this->headers());
        return $res['users'] ?? [];
    }

    /** Creates a confirmed user. app_metadata can only be set server-side, so users can't change it. */
    public function createUser(string $email, string $password, array $appMetadata = []): array
    {
        $body = ['email' => $email, 'password' => $password, 'email_confirm' => true];
        if ($appMetadata !== []) {
            $body['app_metadata'] = $appMetadata;
        }
        return HttpClient::json('POST', "{$this->url}/auth/v1/admin/users", $this->headers(), $body);
    }

    /** @param array $attrs e.g. ['password' => ...] or ['app_metadata' => ['approved' => true]] */
    public function updateUser(string $id, array $attrs): array
    {
        return HttpClient::json('PUT', "{$this->url}/auth/v1/admin/users/$id", $this->headers(), $attrs);
    }

    public function deleteUser(string $id): void
    {
        HttpClient::json('DELETE', "{$this->url}/auth/v1/admin/users/$id", $this->headers());
    }

    // --------------------------------------------------------------- storage
    public function upload(string $path, string $bytes, string $mime): void
    {
        [$status, $body] = HttpClient::request('POST', "{$this->url}/storage/v1/object/{$this->bucket}/" . $this->encodePath($path),
            $this->headers(['Content-Type' => $mime, 'x-upsert' => 'true']), $bytes, 120);
        if ($status >= 400) {
            throw new HttpException(502, "Storage upload failed: $body");
        }
    }

    public function download(string $path): string
    {
        [$status, $body] = HttpClient::request('GET', "{$this->url}/storage/v1/object/{$this->bucket}/" . $this->encodePath($path), $this->headers(), null, 120);
        if ($status >= 400) {
            throw new HttpException(502, 'Could not read the stored file');
        }
        return $body;
    }

    public function signedUrl(string $path, int $seconds = 3600): ?string
    {
        try {
            $res = HttpClient::json('POST', "{$this->url}/storage/v1/object/sign/{$this->bucket}/" . $this->encodePath($path), $this->headers(), ['expiresIn' => $seconds]);
        } catch (HttpException) {
            return null;
        }
        $rel = $res['signedURL'] ?? $res['signedUrl'] ?? null;
        return $rel ? "{$this->url}/storage/v1" . $rel : null;
    }

    public function remove(string $path): void
    {
        try {
            HttpClient::json('DELETE', "{$this->url}/storage/v1/object/{$this->bucket}", $this->headers(), ['prefixes' => [$path]]);
        } catch (HttpException) {
            // best effort; the DB row is the source of truth
        }
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
