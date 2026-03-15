<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../app/lib/response.php';
require_once __DIR__ . '/../../app/lib/request.php';

function require_post_method(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('POSTのみ受け付けます');
    }
}

function require_device_id(array $input): int
{
    $deviceId = (int)($input['device_id'] ?? 0);

    if ($deviceId <= 0) {
        throw new RuntimeException('device_id は必須です');
    }

    return $deviceId;
}

function find_device_for_delete(PDO $pdo, int $deviceId): array
{
    $stmt = $pdo->prepare(
        '
        SELECT
            device_id,
            device_bt_addr,
            device_bt_name,
            device_memo_name
        FROM sensor_device
        WHERE device_id = :device_id
        LIMIT 1
        '
    );

    $stmt->execute([
        ':device_id' => $deviceId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        throw new OutOfBoundsException('削除対象の機器が見つかりませんでした');
    }

    return $row;
}

function delete_device_settings_if_exists(PDO $pdo, int $deviceId): void
{
    try {
        $stmt = $pdo->prepare(
            'DELETE FROM setting WHERE device_id = :device_id'
        );

        $stmt->execute([
            ':device_id' => $deviceId,
        ]);
    } catch (Throwable $e) {
        /**
         * setting テーブルが存在しない構成でも、
         * sensor_device の削除は継続する
         */
    }
}

function delete_sensor_device(PDO $pdo, int $deviceId): void
{
    $stmt = $pdo->prepare(
        '
        DELETE FROM sensor_device
        WHERE device_id = :device_id
        LIMIT 1
        '
    );

    $stmt->execute([
        ':device_id' => $deviceId,
    ]);

    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('機器の削除に失敗しました');
    }
}

function reorder_sensor_devices(PDO $pdo): void
{
    $rows = $pdo->query(
        '
        SELECT device_id
        FROM sensor_device
        ORDER BY display_order ASC, device_id ASC
        '
    )->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        '
        UPDATE sensor_device
        SET display_order = :display_order
        WHERE device_id = :device_id
        '
    );

    $order = 1;

    foreach ($rows as $row) {
        $stmt->execute([
            ':display_order' => $order,
            ':device_id' => (int)$row['device_id'],
        ]);
        $order++;
    }
}

try {
    require_post_method();

    $input = request_json();
    $deviceId = require_device_id($input);

    $pdo = db();
    $pdo->beginTransaction();

    $device = find_device_for_delete($pdo, $deviceId);

    delete_device_settings_if_exists($pdo, $deviceId);
    delete_sensor_device($pdo, $deviceId);
    reorder_sensor_devices($pdo);

    $pdo->commit();

    json_ok([
        'message' => '機器を削除しました',
        'deleted_device' => [
            'device_id' => (int)$device['device_id'],
            'device_bt_addr' => (string)($device['device_bt_addr'] ?? ''),
            'device_bt_name' => (string)($device['device_bt_name'] ?? ''),
            'device_memo_name' => (string)($device['device_memo_name'] ?? ''),
        ],
    ]);

} catch (OutOfBoundsException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_error($e->getMessage(), 404);

} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_error($e->getMessage(), 400);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[api/sensors/delete] ' . $e->getMessage());

    json_error('機器削除に失敗しました', 500, [
        'error' => $e->getMessage(),
    ]);
}