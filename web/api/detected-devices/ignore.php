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

function update_ignore_flag(PDO $pdo, string $deviceBtAddr): void
{
    $stmt = $pdo->prepare(
        '
        UPDATE detected_sensor_device
        SET is_ignored = 1
        WHERE UPPER(TRIM(device_bt_addr)) = UPPER(TRIM(:device_bt_addr))
        '
    );

    $stmt->execute([
        ':device_bt_addr' => $deviceBtAddr,
    ]);

    if ($stmt->rowCount() < 1) {
        throw new OutOfBoundsException('対象機器が検出機器テーブルに見つかりませんでした');
    }
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
        throw new RuntimeException('更新後の機器情報取得に失敗しました');
    }

    return [
        'device_bt_addr' => (string)($row['device_bt_addr'] ?? ''),
        'device_bt_name' => (string)($row['device_bt_name'] ?? ''),
        'detected_first_time' => (string)($row['detected_first_time'] ?? ''),
        'is_ignored' => isset($row['is_ignored']) ? (int)$row['is_ignored'] : 0,
    ];
}

try {
    require_post_method();

    $input = request_json();
    $deviceBtAddr = require_device_bt_addr($input);

    $pdo = db();
    $pdo->beginTransaction();

    update_ignore_flag($pdo, $deviceBtAddr);
    $device = find_detected_device($pdo, $deviceBtAddr);

    $pdo->commit();

    json_ok([
        'message' => '機器を登録しない対象に設定しました',
        'device' => $device,
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

    error_log('[api/detected-devices/ignore] ' . $e->getMessage());

    json_error('機器の無視設定に失敗しました', 500, [
        'error' => $e->getMessage(),
    ]);
}