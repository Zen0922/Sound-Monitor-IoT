<?php
declare(strict_types=1);

/**
 * DB接続取得
 * Get PDO connection
 */

require_once __DIR__ . '/../config/database.php';

/**
 * PDO接続を生成
 */
function create_pdo(): PDO
{
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS, $DB_CHARSET;

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $DB_HOST,
        $DB_NAME,
        $DB_CHARSET
    );

    return new PDO(
        $dsn,
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

/**
 * PDO接続取得（シングルトン）
 * 同一リクエスト内で接続を使い回す
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $pdo = create_pdo();
    }

    return $pdo;
}