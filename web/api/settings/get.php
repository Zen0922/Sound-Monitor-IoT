<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../app/lib/response.php';

function build_setting_name(string $type, ?string $deviceIdRaw): string
{
    return match ($type) {
        'ring_start_angle_deg' => 'ring.start_angle_deg',
        'device_noisy_threshold' => build_device_threshold_setting_name($deviceIdRaw),
        default => throw new RuntimeException('未対応の type です。'),
    };
}

function build_device_threshold_setting_name(?string $deviceIdRaw): string
{
    if ($deviceIdRaw === null || $deviceIdRaw === '') {
        throw new RuntimeException('device_id が指定されていません。');
    }

    if (!ctype_digit($deviceIdRaw) || (int)$deviceIdRaw <= 0) {
        throw new RuntimeException('device_id が不正です。');
    }

    return 'device.' . (int)$deviceIdRaw . '.noisy_threshold';
}

try {
    $type = trim((string)($_GET['type'] ?? ''));
    $deviceIdRaw = isset($_GET['device_id']) ? trim((string)$_GET['device_id']) : null;

    if ($type === '') {
        json_error('type が指定されていません。', 400);
    }

    $settingName = build_setting_name($type, $deviceIdRaw);

    $stmt = db()->prepare(
        '
        SELECT
            setting_name,
            setting_value
        FROM setting_info
        WHERE setting_name = :setting_name
        LIMIT 1
        '
    );

    $stmt->execute([
        ':setting_name' => $settingName,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    json_ok([
        'found' => $row !== false,
        'type' => $type,
        'device_id' => ($deviceIdRaw !== null && $deviceIdRaw !== '') ? (int)$deviceIdRaw : null,
        'setting_name' => $settingName,
        'setting_value' => $row['setting_value'] ?? null,
    ]);

} catch (RuntimeException $e) {
    json_error($e->getMessage(), 400);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}