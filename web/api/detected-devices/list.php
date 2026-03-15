<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../app/lib/response.php';

function fetch_detected_devices(PDO $pdo): array
{
    $sql = '
        SELECT
            d.device_bt_addr,
            d.device_bt_name,
            d.detected_first_time,
            d.is_ignored
        FROM detected_sensor_device d
        LEFT JOIN sensor_device s
            ON UPPER(TRIM(s.device_bt_addr)) = UPPER(TRIM(d.device_bt_addr))
        WHERE
            d.is_ignored = 0
            AND s.device_id IS NULL
        ORDER BY
            d.detected_first_time ASC,
            d.device_bt_addr ASC
    ';

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];

    foreach ($rows as $row) {
        $result[] = [
            'device_bt_addr' => (string)($row['device_bt_addr'] ?? ''),
            'device_bt_name' => (string)($row['device_bt_name'] ?? ''),
            'detected_first_time' => (string)($row['detected_first_time'] ?? ''),
            'is_ignored' => isset($row['is_ignored']) ? (int)$row['is_ignored'] : 0,
        ];
    }

    return $result;
}

try {
    $rows = fetch_detected_devices(db());

    json_ok([
        'has_pending' => count($rows) > 0,
        'pending_count' => count($rows),
        'data' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('[api/detected-devices/list] ' . $e->getMessage());

    json_error('検出機器一覧の取得に失敗しました', 500, [
        'error' => $e->getMessage(),
    ]);
}