<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../app/lib/response.php';

const VIEW_NAME = 'view_most_recent_value';

function request_limit(): int
{
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
    return max(1, min(1000, $limit));
}

function request_offset(): int
{
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    return max(0, $offset);
}

function fetch_current_sensor_values(PDO $pdo, int $limit, int $offset): array
{
    $view = str_replace('`', '``', VIEW_NAME);

    $sql = "
        SELECT
            device_id,
            max_recorded_datetime,
            sensor_value_id,
            measured_value,
            device_memo_name,
            display_order
        FROM `{$view}`
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = [
            'device_id'             => isset($row['device_id']) ? (int)$row['device_id'] : 0,
            'display_order'         => isset($row['display_order']) ? (int)$row['display_order'] : 0,
            'max_recorded_datetime' => isset($row['max_recorded_datetime']) ? (string)$row['max_recorded_datetime'] : '',
            'sensor_value_id'       => isset($row['sensor_value_id']) ? (int)$row['sensor_value_id'] : 0,
            'measured_value'        => is_null($row['measured_value'] ?? null) ? null : (float)$row['measured_value'],
            'device_memo_name'      => is_null($row['device_memo_name'] ?? null) ? '' : trim((string)$row['device_memo_name']),
        ];
    }

    return $rows;
}

try {
    $limit = request_limit();
    $offset = request_offset();

    $rows = fetch_current_sensor_values(db(), $limit, $offset);

    json_ok([
        'count' => count($rows),
        'limit' => $limit,
        'offset' => $offset,
        'data' => $rows,
    ]);

} catch (Throwable $e) {
    error_log('[api/sensors/get-current] ' . $e->getMessage());
    json_error('最新値の取得中にエラーが発生しました。', 500);
}