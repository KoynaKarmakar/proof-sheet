<?php
declare(strict_types=1);

namespace App;

/** Request handlers. Every /api route requires a Supabase session token. */
final class Api
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    private ?Supabase $db = null;

    private function db(): Supabase
    {
        return $this->db ??= new Supabase();
    }

    public function handle(string $method, string $path): void
    {
        if ($method === 'GET' && ($path === '/' || $path === '/health')) {
            self::json(['ok' => true, 'service' => 'doc-extractor-api', 'mock' => Env::bool('MOCK_EXTRACTION')]);
            return;
        }

        // ---- public (no session needed)
        if ($method === 'GET' && $path === '/api/auth-config') {
            self::json(['signup' => self::signupEnabled(), 'approval' => self::approvalRequired()]);
            return;
        }
        if ($method === 'POST' && $path === '/api/signup') {
            self::json($this->signup(), 201);
            return;
        }

        // /api/me also answers for accounts still waiting for approval, so the app can say so.
        if ($method === 'GET' && $path === '/api/me') {
            $user = $this->authenticate(false);
            self::json(['id' => $user['id'], 'email' => $user['email'] ?? null,
                'is_admin' => self::isAdmin($user), 'approved' => self::isApproved($user)]);
            return;
        }

        $user = $this->authenticate();
        $uid = $user['id'];
        if ($path === '/api/users' || str_starts_with($path, '/api/users/')) {
            $this->users($method, $path, $user);
            return;
        }
        if ($method === 'GET' && $path === '/api/documents') {
            self::json(['documents' => $this->db()->listDocuments($uid)]);
            return;
        }
        if ($method === 'POST' && $path === '/api/documents') {
            self::json(['document' => $this->upload($uid)], 201);
            return;
        }
        if (preg_match('#^/api/documents/([0-9a-f-]{36})(/process|/excel)?$#', $path, $m)) {
            $id = $m[1];
            $action = $m[2] ?? '';
            match (true) {
                $method === 'GET' && $action === '' => self::json(['document' => $this->show($id, $uid)]),
                $method === 'POST' && $action === '/process' => $this->process($id, $uid),
                $method === 'GET' && $action === '/excel' => $this->excel($id, $uid),
                $method === 'DELETE' && $action === '' => $this->delete($id, $uid),
                default => throw new HttpException(405, 'Method not allowed'),
            };
            return;
        }
        throw new HttpException(404, 'Not found');
    }

    // ------------------------------------------------------------- actions

    private function upload(string $uid): array
    {
        $file = $_FILES['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            throw new HttpException(400, $code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
                ? 'File is too large (max 20 MB)' : 'No file uploaded (send multipart field "file")');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new HttpException(413, 'File is too large (max 20 MB)');
        }
        $bytes = (string) file_get_contents($file['tmp_name']);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        if (!isset(Extractor::SUPPORTED[$mime])) {
            throw new HttpException(415, 'Upload a PDF, PNG, JPG or WEBP file');
        }

        $name = mb_substr(basename((string) $file['name']), 0, 200);
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'document';
        $path = sprintf('%s/%s_%s', $uid, bin2hex(random_bytes(6)), $safe);

        $this->db()->upload($path, $bytes, $mime);
        return $this->db()->insertDocument([
            'user_id' => $uid,
            'filename' => $name,
            'storage_path' => $path,
            'mime_type' => $mime,
            'size_bytes' => strlen($bytes),
            'status' => 'uploaded',
        ]);
    }

    /**
     * Starts extraction and answers right away (202); the work carries on in the background
     * and the app polls the document's status. Long PDFs on Groq's free tier can take many
     * minutes, far longer than an HTTP request should stay open.
     */
    private function process(string $id, string $uid): void
    {
        $doc = $this->db()->getDocument($id, $uid);
        $row = $this->db()->updateDocument($id, $uid, ['status' => 'processing', 'error' => null, 'usage' => null]);

        // Hand the response back now, then keep working.
        self::respondAndContinue(['document' => $row], 202);
        set_time_limit((int) Env::get('PROCESS_TIME_LIMIT_SECONDS', '3600'));

        // If PHP dies mid-way (time limit, memory), never leave the document stuck on "Reading".
        $finished = false;
        register_shutdown_function(function () use (&$finished, $id, $uid) {
            if ($finished) {
                return;
            }
            $err = error_get_last();
            $why = $err && str_contains($err['message'], 'Maximum execution time')
                ? 'Reading took too long and was stopped. Try again, or split the PDF into smaller parts.'
                : 'Reading stopped unexpectedly' . ($err ? ': ' . $err['message'] : '.');
            try {
                $this->db()->updateDocument($id, $uid, ['status' => 'failed', 'error' => mb_substr($why, 0, 1000)]);
            } catch (\Throwable) {
            }
        });

        try {
            $bytes = $this->db()->download($doc['storage_path']);
            $onProgress = function (int $done, int $total) use ($id, $uid) {
                try {
                    $this->db()->updateDocument($id, $uid, ['usage' => ['progress' => ['done' => $done, 'total' => $total]]]);
                } catch (\Throwable) {
                    // progress is cosmetic
                }
            };
            $out = (new Extractor())->extract($bytes, $doc['mime_type'], $doc['filename'], $onProgress);
            $clean = Validator::process($out['extraction'], $out['meta']);
        } catch (\Throwable $e) {
            $finished = true;
            error_log("process $id failed: " . $e->getMessage());
            $this->db()->updateDocument($id, $uid, ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            return;
        }

        $this->db()->updateDocument($id, $uid, [
            'status' => 'completed',
            'document_type' => $clean['document_type'],
            'title' => $clean['title'],
            'extraction' => $clean,
            'raw_extraction' => $out['extraction'],
            'flag_counts' => $clean['flag_counts'],
            'model' => $out['meta']['model'],
            'usage' => ['input_tokens' => $out['meta']['input_tokens'], 'output_tokens' => $out['meta']['output_tokens']],
            'processed_at' => gmdate('c'),
        ]);
        $finished = true;
    }

    /** Sends a complete JSON response and closes the connection, but lets the script keep running. */
    private static function respondAndContinue(array $data, int $status): void
    {
        ignore_user_abort(true);
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($body));
        header('Connection: close');
        echo $body;
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    private function show(string $id, string $uid): array
    {
        $doc = $this->db()->getDocument($id, $uid);
        unset($doc['raw_extraction']);
        $doc['file_url'] = $this->db()->signedUrl($doc['storage_path']);
        return $doc;
    }

    private function excel(string $id, string $uid): void
    {
        $doc = $this->db()->getDocument($id, $uid);
        if (($doc['status'] ?? '') !== 'completed') {
            throw new HttpException(409, 'Document has not been processed yet');
        }
        $bytes = ExcelExporter::build($doc);
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $doc['filename']) ?: 'document';
        $fname = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) . '_extracted.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
    }

    private function delete(string $id, string $uid): void
    {
        $doc = $this->db()->getDocument($id, $uid);
        $this->db()->remove($doc['storage_path']);
        $this->db()->deleteDocument($id, $uid);
        http_response_code(204);
    }

    // ------------------------------------------------------- user management
    // Sign-ups are off in Supabase, so admins (ADMIN_EMAILS) add and remove logins here.

    private function users(string $method, string $path, array $me): void
    {
        if (!self::isAdmin($me)) {
            throw new HttpException(403, 'Only admins can manage users');
        }
        if ($path === '/api/users' && $method === 'GET') {
            $rows = array_map(fn (array $u) => [
                'id' => $u['id'],
                'email' => $u['email'] ?? null,
                'created_at' => $u['created_at'] ?? null,
                'last_sign_in_at' => $u['last_sign_in_at'] ?? null,
                'is_admin' => self::isAdmin($u),
                'approved' => self::isApproved($u),
            ], $this->db()->listUsers());
            usort($rows, fn ($a, $b) => strcmp((string) $a['email'], (string) $b['email']));
            self::json(['users' => $rows]);
            return;
        }
        if ($path === '/api/users' && $method === 'POST') {
            $in = self::body();
            $email = strtolower(trim((string) ($in['email'] ?? '')));
            $password = (string) ($in['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new HttpException(422, 'Enter a valid email address');
            }
            self::checkPassword($password);
            $u = $this->db()->createUser($email, $password, ['approved' => true]);
            self::json(['user' => ['id' => $u['id'] ?? null, 'email' => $u['email'] ?? $email]], 201);
            return;
        }
        if (!preg_match('#^/api/users/([0-9a-f-]{36})$#', $path, $m)) {
            throw new HttpException(404, 'Not found');
        }
        $id = $m[1];
        if ($method === 'PATCH') {
            $in = self::body();
            $attrs = [];
            if (array_key_exists('password', $in)) {
                self::checkPassword((string) $in['password']);
                $attrs['password'] = (string) $in['password'];
            }
            if (array_key_exists('approved', $in)) {
                $attrs['app_metadata'] = ['approved' => (bool) $in['approved']];
            }
            if ($attrs === []) {
                throw new HttpException(422, 'Nothing to change');
            }
            $this->db()->updateUser($id, $attrs);
            self::json(['ok' => true]);
            return;
        }
        if ($method === 'DELETE') {
            if ($id === $me['id']) {
                throw new HttpException(422, "You can't remove your own login");
            }
            // Remove their stored files; their document rows go with the user (on delete cascade).
            foreach ($this->db()->storagePathsForUser($id) as $storagePath) {
                $this->db()->remove($storagePath);
            }
            $this->db()->deleteUser($id);
            http_response_code(204);
            return;
        }
        throw new HttpException(405, 'Method not allowed');
    }

    // ---------------------------------------------------------------- sign-up
    // Email sign-ups come through here. New accounts can use the app straight away unless
    // SIGNUP_REQUIRES_APPROVAL=true, which holds them for an admin first.

    private function signup(): array
    {
        if (!self::signupEnabled()) {
            throw new HttpException(403, 'Sign-up is turned off. Ask an admin to add you.');
        }
        self::rateLimit('signup', 5, 3600);
        $in = self::body();
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $password = (string) ($in['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'Enter a valid email address');
        }
        self::checkPassword($password, 8);
        $approved = !self::approvalRequired() || self::isAdmin(['email' => $email]);
        try {
            $this->db()->createUser($email, $password, ['approved' => $approved]);
        } catch (HttpException $e) {
            if (stripos($e->getMessage(), 'already') !== false) {
                throw new HttpException(409, 'An account with this email already exists. Sign in, or reset your password.');
            }
            throw $e;
        }
        return ['status' => $approved ? 'active' : 'pending'];
    }

    private static function signupEnabled(): bool
    {
        return Env::bool('SIGNUP_ENABLED', true);
    }

    private static function approvalRequired(): bool
    {
        return Env::bool('SIGNUP_REQUIRES_APPROVAL', false);
    }

    /**
     * Approved = an admin, or flagged approved by the backend (admin-added users, approved
     * sign-ups). Accounts created any other way - e.g. a first "Continue with Google" - have
     * no flag. Only matters when SIGNUP_REQUIRES_APPROVAL=true; otherwise everyone is approved.
     */
    private static function isApproved(array $user): bool
    {
        return self::isAdmin($user)
            || ($user['app_metadata']['approved'] ?? null) === true
            || !self::approvalRequired();
    }

    /** Small per-IP limit for public endpoints (file based, fine for a single server). */
    private static function rateLimit(string $bucket, int $max, int $seconds): void
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ip = trim(explode(',', $ip)[0]);
        $file = sys_get_temp_dir() . '/rl_' . $bucket . '_' . md5($ip);
        $now = time();
        $hits = array_filter((array) json_decode((string) @file_get_contents($file), true), fn ($t) => $t > $now - $seconds);
        if (count($hits) >= $max) {
            throw new HttpException(429, 'Too many attempts. Try again later.');
        }
        $hits[] = $now;
        @file_put_contents($file, json_encode(array_values($hits)));
    }

    private static function isAdmin(array $user): bool
    {
        $admins = array_filter(array_map(fn ($e) => strtolower(trim($e)), explode(',', (string) Env::get('ADMIN_EMAILS', ''))));
        return in_array(strtolower((string) ($user['email'] ?? '')), $admins, true);
    }

    private static function checkPassword(string $password, int $min = 6): void
    {
        if (mb_strlen($password) < $min) {
            throw new HttpException(422, "Password must be at least $min characters");
        }
    }

    private static function body(): array
    {
        $data = json_decode((string) file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }

    // ----------------------------------------------------------------- auth

    private function authenticate(bool $requireApproved = true): array
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) {
            throw new HttpException(401, 'Missing access token');
        }
        $user = $this->db()->userFromToken($m[1]);
        if (empty($user['id'])) {
            throw new HttpException(401, 'Invalid session');
        }
        $allowed = Env::get('ALLOWED_EMAILS');
        if ($allowed !== null) {
            $list = array_map(fn($e) => strtolower(trim($e)), explode(',', $allowed));
            if (!in_array(strtolower($user['email'] ?? ''), $list, true) && !self::isAdmin($user)) {
                throw new HttpException(403, 'This account is not allowed to use the app');
            }
        }
        if ($requireApproved && !self::isApproved($user)) {
            throw new HttpException(403, 'Your account is waiting for an admin to approve it.');
        }
        return $user;
    }

    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
