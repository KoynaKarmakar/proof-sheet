<?php
declare(strict_types=1);

namespace App;

/** Reads config from real env vars, falling back to a local .env file. */
final class Env
{
    private static ?array $file = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') {
            return $v;
        }
        if (self::$file === null) {
            self::$file = [];
            $path = dirname(__DIR__) . '/.env';
            if (is_file($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$k, $val] = explode('=', $line, 2);
                    self::$file[trim($k)] = trim(trim($val), "\"'");
                }
            }
        }
        $v = self::$file[$key] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    public static function require(string $key): string
    {
        $v = self::get($key);
        if ($v === null) {
            throw new HttpException(500, "Server misconfigured: $key is not set");
        }
        return $v;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        return $v === null ? $default : in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }
}
