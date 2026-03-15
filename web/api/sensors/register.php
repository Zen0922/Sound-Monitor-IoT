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

function require_device_bt_addr(array $input): string
{
    $deviceBtAddr = trim((string)($input['device_bt_addr'] ?? ''));

    if ($deviceBtAddr === '') {
        throw new RuntimeException('device_bt_addr は必須です');
    }

    return $deviceBtAddr;
}

function find_detected_device(PDO $pdo, string $deviceBtAddr): array
{
    $stmt = $pdo->prepare(
        '
        SELECT
            device_bt_addr,
            device_bt_name,
            detected_first_time,
            is_ignored
        FROM detected_sensor_device
        WHERE UPPER(TRIM(device_bt_addr)) = UPPER(TRIM(:device_bt_addr))
        LIMIT 1
        '
    );

    $stmt->execute([
        ':device_bt_addr' => $deviceBtAddr,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        throw new OutOfBoundsException('対象機器が検出機器テーブルに見つかりませんでした');
    }

    return $row;
}

function ensure_not_ignored(array $detected): void
{
    if ((int)($detected['is_ignored'] ?? 0) === 1) {
        throw new DomainException('この機器は無視対象です');
    }
}

function find_existing_sensor_device(PDO $pdo, string $deviceBtAddr): ?array
{
    $stmt = $pdo->prepare(
        '
        SELECT
            device_id,
            device_bt_addr,
            device_bt_name,
            device_memo_name,
            unit_name,
            scale_factor,
            display_order
        FROM sensor_device
        WHERE UPPER(TRIM(device_bt_addr)) = UPPER(TRIM(:device_bt_addr))
        LIMIT 1
        '
    );

    $stmt->execute([
        ':device_bt_addr' => $deviceBtAddr,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return ($row === false) ? null : $row;
}

function delete_detected_device(PDO $pdo, string $deviceBtAddr): void
{
    $stmt = $pdo->prepare(
        '
        DELETE FROM detected_sensor_device
        WHERE UPPER(TRIM(device_bt_addr)) = UPPER(TRIM(:device_bt_addr))
        '
    );

    $stmt->execute([
        ':device_bt_addr' => $deviceBtAddr,
    ]);
}

function get_next_display_order(PDO $pdo): int
{
    $row = $pdo->query(
        'SELECT COALESCE(MAX(display_order), 0) AS max_display_order FROM sensor_device'
    )->fetch(PDO::FETCH_ASSOC);

    return ((int)($row['max_display_order'] ?? 0)) + 1;
}

function insert_sensor_device(PDO $pdo, string $deviceBtAddr, array $detected): int
{
    $deviceBtName = trim((string)($detected['device_bt_name'] ?? ''));
    $deviceMemoName = ($deviceBtName !== '') ? $deviceBtName : $deviceBtAddr;

    $stmt = $pdo->prepare(
        '
        INSERT INTO sensor_device (
            device_bt_addr,
            device_bt_name,
            device_memo_name,
            unit_name,
            scale_factor,
            display_order
        ) VALUES (
            :device_bt_addr,
            :device_bt_name,
            :device_memo_name,
            :unit_name,
            :scale_factor,
            :display_order
        )
        '
    );

    $stmt->execute([
        ':device_bt_addr'   => $deviceBtAddr,
        ':device_bt_name'   => $deviceBtName,
        ':device_memo_name' => $deviceMemoName,
        ':unit_name'        => 'dB',
        ':scale_factor'     => 10,
        ':display_order'    => get_next_display_order($pdo),
    ]);

    return (int)$pdo->lastInsertId();
}

function find_sensor_device_by_id(PDO $pdo, int $deviceId): array
{
    $stmt = $pdo->prepare(
        '
        SELECT
            device_id,
            device_bt_addr,
            device_bt_name,
            device_memo_name,
            unit_name,
            scale_factor,
            display_order
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
        throw new RuntimeException('登録後の機器情報取得に失敗しました');
    }

    return $row;
}

try {
    require_post_method();

    $input = request_json();
    $deviceBtAddr = require_device_bt_addr($input);

    $pdo = db();
    $pdo->beginTransaction();

    $detected = find_detected_device($pdo, $deviceBtAddr);
    ensure_not_ignored($detected);

    $existing = find_existing_sensor_device($pdo, $deviceBtAddr);

    if ($existing !== null) {
        delete_detected_device($pdo, $deviceBtAddr);
        $pdo->commit();

        json_ok([
            'message' => '既に登録済みのため、検出機器一覧から削除しました',
            'already_registered' => true,
            'device' => $existing,
        ]);
    }

    $newDeviceId = insert_sensor_device($pdo, $deviceBtAddr, $detected);
    delete_detected_device($pdo, $deviceBtAddr);
    $created = find_sensor_device_by_id($pdo, $newDeviceId);

    $pdo->commit();

    json_ok([
        'message' => '機器を登録しました',
        'already_registered' => false,
        'device' => $created,
    ]);

} catch (OutOfBoundsException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_error($e->getMessage(), 404);

} catch (DomainException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_error($e->getMessage(), 409);

} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_error($e->getMessage(), 400);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[api/sensors/register] ' . $e->getMessage());

    json_error('機器登録に失敗しました', 500, [
        'error' => $e->getMessage(),
    ]);
}