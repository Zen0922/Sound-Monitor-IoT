<?php
declare(strict_types=1);

/**
 * JSONレスポンス出力
 * JSON response helpers
 */

if (!function_exists('json_response')) {
    function json_response(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}

if (!function_exists('json_ok')) {
    function json_ok(array $data = [], int $status = 200): void
    {
        json_response(
            array_merge([
                'ok' => true,
            ], $data),
            $status
        );
    }
}

if (!function_exists('json_error')) {
    function json_error(string $message, int $status = 500, array $extra = []): void
    {
        json_response(
            array_merge([
                'ok' => false,
                'message' => $message,
            ], $extra),
            $status
        );
    }
}