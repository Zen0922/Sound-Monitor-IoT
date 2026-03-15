<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../app/lib/response.php';

function request_include_threshold(): bool
{
    $value = isset($_GET['include_threshold']) ? trim((string)$_GET['include_threshold']) : '1';
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function request_device_id(): ?int
{
    $raw = isset($_GET['device_id']) ? trim((string)$_GET['device_id']) : '';

    if ($raw === '') {
        return null;
    }

    if (!ctype_digit($raw) || (int)$raw <= 0) {
        throw new RuntimeException('device_id が不正です。');
    }

    return (int)$raw;
}

function fetch_sensor_list(PDO $pdo, ?int $deviceId, bool $includeThreshold): array
{
    $sql = "
        SELECT
            sd.device_id,
            sd.device_bt_addr,
            sd.device_bt_name,
            sd.device_memo_name,
            sd.unit_name,
            sd.scale_factor,
            sd.display_order
    ";

    if ($includeThreshold) {
        $sql .= ",
            si.setting_value AS noisy_threshold
        ";
    }

    $sql .= "
        FROM sensor_device sd
    ";

    if ($includeThreshold) {
        $sql .= "
            LEFT JOIN setting_info si
                ON si.setting_name = CONCAT('device.', sd.device_id, '.noisy_threshold')
        ";
    }

    $sql .= "
        WHERE 1 = 1
    ";

    if ($deviceId !== null) {
        $sql .= "
            AND sd.device_id = :device_id
        ";
    }

    $sql .= "
        ORDER BY
            sd.display_order ASC,
            sd.device_id ASC
    ";

    $stmt = $pdo->prepare($sql);

    if ($deviceId !== null) {
        $stmt->bindValue(':device_id', $deviceId, PDO::PARAM_INT);
    }

    $stmt->execute();

    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = [
            'device_id' => isset($row['device_id']) ? (int)$row['device_id'] : 0,
            'device_bt_addr' => (string)($row['device_bt_addr'] ?? ''),
            'device_bt_name' => (string)($row['device_bt_name'] ?? ''),
            'device_memo_name' => (string)($row['device_memo_name'] ?? ''),
            'unit_name' => (string)($row['unit_name'] ?? ''),
            'scale_factor' => isset($row['scale_factor']) ? (int)$row['scale_factor'] : 0,
            'display_order' => isset($row['display_order']) ? (int)$row['display_order'] : 0,
            'noisy_threshold' => array_key_exists('noisy_threshold', $row) && $row['noisy_threshold'] !== null
                ? (float)$row['noisy_threshold']
                : null,
        ];
    }

    return $rows;
}

try {
    $deviceId = request_device_id();
    $includeThreshold = request_include_threshold();

    $rows = fetch_sensor_list(db(), $deviceId, $includeThreshold);

    json_ok([
        'count' => count($rows),
        'device_id_filter' => $deviceId,
        'include_threshold' => $includeThreshold,
        'data' => $rows,
    ]);

} catch (RuntimeException $e) {
    json_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[api/sensors/list] ' . $e->getMessage());
    json_error('センサー一覧の取得中にエラーが発生しました。', 500);
}