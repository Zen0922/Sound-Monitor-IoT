<?php
declare(strict_types=1);

/**
 * JSONリクエスト取得
 * Read JSON request body
 */

if (!function_exists('request_json')) {
    function request_json(): array
    {
        $raw = file_get_contents('php://input');

        if ($raw === false) {
            throw new RuntimeException('リクエスト本文の読み取りに失敗しました。');
        }

        if ($raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new RuntimeException('JSON形式のPOSTデータを送信してください。');
        }

        return $data;
    }
}