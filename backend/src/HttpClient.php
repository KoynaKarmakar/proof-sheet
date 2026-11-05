<?php
declare(strict_types=1);

namespace App;

/** Thin cURL wrapper. Returns [status, body, headers]. */
final class HttpClient
{
    /**
     * @param array<string,string> $headers
     * @return array{0:int,1:string}
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 60): array
    {
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            throw new HttpException(502, "Upstream request failed: $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return [$status, (string) $resp];
    }

    /** @return array<mixed> */
    public static function json(string $method, string $url, array $headers = [], mixed $payload = null, int $timeout = 60): array
    {
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
        [$status, $body] = self::request($method, $url, $headers, $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES), $timeout);
        $data = $body === '' ? [] : json_decode($body, true);
        if ($status >= 400) {
            $msg = is_array($data) ? ($data['message'] ?? $data['msg'] ?? $data['error_description'] ?? $data['error']['message'] ?? $data['error'] ?? $body) : $body;
            throw new HttpException($status >= 500 ? 502 : $status, is_string($msg) ? $msg : json_encode($msg));
        }
        return is_array($data) ? $data : [];
    }
}
