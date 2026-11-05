<?php
// Tiny on-disk stand-in for Supabase (auth, PostgREST, storage) for local runs and tests,
// so the whole app works on your machine without a Supabase project:
//   php -S 127.0.0.1:54321 tests/mock_supabase.php
// Any email and password logs in (as one demo user). Never expose this to the internet.
declare(strict_types=1);

$dir = sys_get_temp_dir() . '/mock_supabase';
@mkdir("$dir/objects", 0777, true);
$dbFile = "$dir/documents.json";
$rows = is_file($dbFile) ? json_decode(file_get_contents($dbFile), true) : [];
$save = function () use (&$rows, $dbFile) { file_put_contents($dbFile, json_encode($rows)); };
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$body = file_get_contents('php://input');
header('Content-Type: application/json');
// The browser talks to this directly (login, opening the original file), so allow CORS.
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Headers: authorization, apikey, content-type, x-client-info, x-supabase-api-version');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
if ($method === 'OPTIONS') { http_response_code(204); return; }

$user = ['id' => '11111111-1111-1111-1111-111111111111', 'aud' => 'authenticated', 'role' => 'authenticated',
    'email' => 'demo@example.com', 'app_metadata' => ['provider' => 'email'], 'user_metadata' => new stdClass(),
    'created_at' => '2026-01-01T00:00:00Z'];
if ($path === '/auth/v1/token') {
    $in = json_decode($body, true) ?: [];
    if (!empty($in['email'])) { $user['email'] = $in['email']; }
    echo json_encode(['access_token' => 'test-user-token', 'token_type' => 'bearer', 'expires_in' => 86400,
        'expires_at' => time() + 86400, 'refresh_token' => 'test-refresh-token', 'user' => $user]);
    return;
}
if ($path === '/auth/v1/logout') { http_response_code(204); return; }

if ($path === '/auth/v1/user') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($auth !== 'Bearer test-user-token') { http_response_code(401); echo '{"message":"bad jwt"}'; return; }
    echo json_encode($user);
    return;
}

if ($path === '/rest/v1/documents') {
    parse_str($_SERVER['QUERY_STRING'] ?? '', $q);
    $match = function ($r) use ($q) {
        foreach ($q as $k => $v) {
            if (str_starts_with((string) $v, 'eq.') && (string) ($r[$k] ?? '') !== substr($v, 3)) return false;
        }
        return true;
    };
    if ($method === 'POST') {
        $r = json_decode($body, true) + ['id' => sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff)),
            'created_at' => gmdate('c'), 'extraction' => null, 'flag_counts' => null, 'error' => null];
        $rows[] = $r; $save(); http_response_code(201); echo json_encode([$r]); return;
    }
    if ($method === 'GET') { echo json_encode(array_values(array_filter($rows, $match))); return; }
    if ($method === 'PATCH') {
        $out = [];
        foreach ($rows as &$r) { if ($match($r)) { $r = array_merge($r, json_decode($body, true)); $out[] = $r; } }
        $save(); echo json_encode($out); return;
    }
    if ($method === 'DELETE') { $rows = array_values(array_filter($rows, fn($r) => !$match($r))); $save(); echo '[]'; return; }
}

if (preg_match('#^/storage/v1/object/sign/documents/(.+)$#', $path, $m)) {
    if ($method === 'GET') { // the signed link itself: serve the stored file
        $f = "$dir/objects/" . md5(rawurldecode($m[1]));
        if (!is_file($f)) { http_response_code(404); echo '{"message":"not found"}'; return; }
        header('Content-Type: ' . ((new finfo(FILEINFO_MIME_TYPE))->file($f) ?: 'application/octet-stream'));
        readfile($f); return;
    }
    echo json_encode(['signedURL' => '/object/sign/documents/' . $m[1] . '?token=x']); return;
}
if (preg_match('#^/storage/v1/object/documents/(.+)$#', $path, $m)) {
    $f = "$dir/objects/" . md5(rawurldecode($m[1]));
    if ($method === 'POST') { file_put_contents($f, $body); echo '{"Key":"ok"}'; return; }
    if ($method === 'GET') { header('Content-Type: application/octet-stream'); readfile($f); return; }
}
if ($path === '/storage/v1/object/documents' && $method === 'DELETE') { echo '[]'; return; }

http_response_code(404);
echo json_encode(['message' => "mock: no route $method $path"]);
