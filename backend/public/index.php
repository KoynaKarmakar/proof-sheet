<?php
declare(strict_types=1);

use App\Api;
use App\Env;
use App\HttpException;

require dirname(__DIR__) . '/vendor/autoload.php';

set_time_limit((int) (Env::get('LLM_TIMEOUT_SECONDS', '300')) + 60);
ignore_user_abort(true); // keep extracting even if the browser tab is closed mid-request

// ---------------------------------------------------------------- CORS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = array_filter(array_map('trim', explode(',', Env::get('ALLOWED_ORIGINS', 'http://localhost:3000'))));
$originOk = in_array('*', $allowed, true) || in_array($origin, $allowed, true)
    // allow this project's Vercel preview deployments, e.g. https://doc-extractor-git-x-team.vercel.app
    || (($pat = Env::get('ALLOWED_ORIGIN_REGEX')) && @preg_match($pat, $origin));
if ($origin !== '' && $originOk) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Expose-Headers: Content-Disposition');
    header('Access-Control-Max-Age: 86400');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// -------------------------------------------------------------- dispatch
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';

try {
    (new Api())->handle($method, $path);
} catch (HttpException $e) {
    Api::json(['error' => $e->getMessage()], $e->status);
} catch (\Throwable $e) {
    error_log((string) $e);
    Api::json(['error' => 'Internal server error'], 500);
}
