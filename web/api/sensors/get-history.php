<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../app/lib/response.php';

const ALLOWED_VALUE_TYPES = ['level', 'baseline', 'aux1', 'aux2'];

function request_hours(): int
{
    $hours = filter_input(
        INPUT_GET,
        'hours',
        FILTER_VALIDATE_INT,
        ['options' => ['default' => 1, 'min_range' => 1, 'max_range' => 24]]
    );

    if (!is_int($hours)) {
        return 1;
    }

    return $hours;
}

function request_device_id(): ?int
{
    $deviceId = filter_input(INPUT_GET, 'device_id', FILTER_VALIDATE_INT);

    if ($deviceId === false || $deviceId === null) {
        return null;
    }

    return max(1, (int)$deviceId);
}

function request_compact(): bool
{
    $compact = filter_input(INPUT_GET, 'compact', FILTER_VALIDATE_INT);
    return ((int)$compact === 1);
}

function request_value_type(): ?string
{
    $valueType = filter_input(INPUT_GET, 'value_type', FILTER_UNSAFE_RAW);
    $valueType = is_string($valueType) ? trim($valueType) : '';

    if ($valueType === '') {
        return null;
    }

    if (!in_array($valueType, ALLOWED_VALUE_TYPES, true)) {
        json_error(
            'invalid value_type',
            400,
            ['allowed_value_types' => ALLOWED_VALUE_TYPES]
        );
    }

    return $valueType;
}

function build_history_sql(?int $deviceId, ?string $valueType): string
{
    $sql = "
        SELECT
            sv.device_id,
            sv.measured_datetime,
            sv.recorded_datetime,
            sv.value_type,
            sv.measured_value,

            sd.device_bt_addr,
            sd.device_bt_name,
            sd.device_memo_name,
            sd.unit_name,
            sd.scale_factor,
            sd.display_order
        FROM sensor_value sv
        INNER JOIN sensor_device sd
            ON sv.device_id = sd.device_id
        WHERE sv.measured_datetime >= (NOW() - INTERVAL :hours HOUR)
    ";

    if ($deviceId !== null) {
        $sql .= " AND sv.device_id = :device_id ";
    }

    if ($valueType !== null) {
        $sql .= " AND sv.value_type = :value_type ";
    }

    $sql .= "
        ORDER BY
            sd.display_order ASC,
            sv.device_id ASC,
            sv.value_type ASC,
            sv.measured_datetime ASC
    ";

    return $sql;
}

function fetch_history_rows(PDO $pdo, int $hours, ?int $deviceId, ?string $valueType): array
{
    $sql = build_history_sql($deviceId, $valueType);

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':hours', $hours, PDO::PARAM_INT);

    if ($deviceId !== null) {
        $stmt->bindValue(':device_id', $deviceId, PDO::PARAM_INT);
    }

    if ($valueType !== null) {
        $stmt->bindValue(':value_type', $valueType, PDO::PARAM_STR);
    }

    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function group_history_rows(array $rows, bool $compact): array
{
    $devices = [];

    foreach ($rows as $row) {
        $deviceId = (int)$row['device_id'];

        if (!isset($devices[$deviceId])) {
            $devices[$deviceId] = [
                'device_id' => $deviceId,
                'display_order' => isset($row['display_order']) ? (int)$row['display_order'] : 0,
                'device_bt_addr' => (string)($row['device_bt_addr'] ?? ''),
                'device_bt_name' => (string)($row['device_bt_name'] ?? ''),
                'device_memo_name' => (string)($row['device_memo_name'] ?? ''),
                'unit_name' => (string)($row['unit_name'] ?? ''),
                'scale_factor' => isset($row['scale_factor']) ? (int)$row['scale_factor'] : 0,
                'values' => [],
            ];
        }

        if ($compact) {
            $measuredUnix = strtotime((string)$row['measured_datetime']);
            $recordedUnix = strtotime((string)$row['recorded_datetime']);

            $devices[$deviceId]['values'][] = [
                't' => ($measuredUnix === false) ? null : $measuredUnix,
                'r' => ($recordedUnix === false) ? null : $recordedUnix,
                'y' => (string)$row['value_type'],
                'v' => $row['measured_value'],
            ];
        } else {
            $devices[$deviceId]['values'][] = [
                'measured_datetime' => (string)$row['measured_datetime'],
                'recorded_datetime' => (string)$row['recorded_datetime'],
                'value_type' => (string)$row['value_type'],
                'measured_value' => $row['measured_value'],
            ];
        }
    }

    return array_values($devices);
}

try {
    $hours = request_hours();
    $deviceId = request_device_id();
    $compact = request_compact();
    $valueType = request_value_type();

    $now = new DateTimeImmutable();
    $from = $now->modify("-{$hours} hours");

    $rows = fetch_history_rows(db(), $hours, $deviceId, $valueType);
    $devices = group_history_rows($rows, $compact);

    json_ok([
        'hours' => $hours,
        'from' => $from->format('Y-m-d H:i:s'),
        'to' => $now->format('Y-m-d H:i:s'),
        'device_id_filter' => $deviceId,
        'value_type_filter' => $valueType,
        'compact' => $compact,
        'devices' => $devices,
    ]);

} catch (Throwable $e) {
    error_log('[api/sensors/get-history] ' . $e->getMessage());
    json_error('履歴取得中にエラーが発生しました。', 500);
}