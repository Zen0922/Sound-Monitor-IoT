<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../app/lib/response.php';
require_once __DIR__ . '/../../app/lib/request.php';

function normalize_angle(float $deg): string
{
    $value = fmod($deg, 360.0);

    if ($value < 0) {
        $value += 360.0;
    }

    return format_decimal_1(round($value, 1));
}

function normalize_threshold(float $value): string
{
    $value = round($value, 1);

    if ($value < 0) {
        throw new RuntimeException('値は0以上で指定してください。');
    }

    return format_decimal_1($value);
}

function format_decimal_1(float $value): string
{
    return ((int)$value == $value)
        ? (string)(int)$value
        : (string)$value;
}

function build_setting_name_from_input(array $input): string
{
    $type = trim((string)($input['type'] ?? ''));

    return match ($type) {
        'ring_start_angle_deg' => 'ring.start_angle_deg',
        'device_noisy_threshold' => build_device_threshold_setting_name_from_input($input),
        '' => build_raw_setting_name_from_input($input),
        default => throw new RuntimeException('未対応の type です。'),
    };
}

function build_device_threshold_setting_name_from_input(array $input): string
{
    $deviceId = (int)($input['device_id'] ?? 0);

    if ($deviceId <= 0) {
        throw new RuntimeException('device_id が不正です。');
    }

    return 'device.' . $deviceId . '.noisy_threshold';
}

function build_raw_setting_name_from_input(array $input): string
{
    $settingName = trim((string)($input['setting_name'] ?? ''));

    if ($settingName === '') {
        throw new RuntimeException('type または setting_name を指定してください。');
    }

    return $settingName;
}

function normalize_setting_value(string $settingName, mixed $rawValue): string
{
    if ($settingName === 'ring.start_angle_deg') {
        if (!is_numeric($rawValue)) {
            throw new RuntimeException('リング開始角度は数値で指定してください。');
        }

        return normalize_angle((float)$rawValue);
    }

    if (preg_match('/^device\.\d+\.noisy_threshold$/', $settingName) === 1) {
        if (!is_numeric($rawValue)) {
            throw new RuntimeException('基準音レベルは数値で指定してください。');
        }

        return normalize_threshold((float)$rawValue);
    }

    return trim((string)$rawValue);
}

try {
    $input = request_json();

    if (!array_key_exists('setting_value', $input)) {
        json_error('setting_value を指定してください。', 400);
    }

    $settingName = build_setting_name_from_input($input);
    $settingValue = normalize_setting_value($settingName, $input['setting_value']);

    $stmt = db()->prepare(
        '
        INSERT INTO setting_info (
            setting_name,
            setting_value
        ) VALUES (
            :setting_name,
            :setting_value
        )
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value)
        '
    );

    $stmt->execute([
        ':setting_name' => $settingName,
        ':setting_value' => $settingValue,
    ]);

    json_ok([
        'setting_name' => $settingName,
        'setting_value' => $settingValue,
    ]);

} catch (RuntimeException $e) {
    json_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('[api/settings/update] ' . $e->getMessage());
    json_error('設定更新中にエラーが発生しました。', 500);
}