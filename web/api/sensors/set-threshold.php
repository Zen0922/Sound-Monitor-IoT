<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/lib/db.php';
require_once __DIR__ . '/../../app/lib/request.php';
require_once __DIR__ . '/../../app/lib/response.php';

function require_post_method(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('POSTで呼び出してください');
    }
}

function normalize_threshold(float $value): string
{
    return number_format(round($value, 1), 1, '.', '');
}

function calculate_thresholds(PDO $pdo, int $lookbackSeconds, float $percentile): array
{
    $sql = "
        WITH recent AS (
            SELECT
                sv.device_id,
                sv.measured_value
            FROM sensor_value sv
            WHERE sv.recorded_datetime >= NOW() - INTERVAL :lookback SECOND
        ),
        ranked AS (
            SELECT
                r.device_id,
                r.measured_value,
                PERCENT_RANK() OVER (
                    PARTITION BY r.device_id
                    ORDER BY r.measured_value
                ) AS pr,
                COUNT(*) OVER (
                    PARTITION BY r.device_id
                ) AS cnt
            FROM recent r
        ),
        p95 AS (
            SELECT
                device_id,
                MIN(measured_value) AS p95_value
            FROM ranked
            WHERE pr >= :percentile
            GROUP BY device_id
        ),
        fallback_single AS (
            SELECT
                device_id,
                MAX(measured_value) AS p95_value
            FROM ranked
            WHERE cnt = 1
            GROUP BY device_id
        ),
        merged AS (
            SELECT device_id, p95_value FROM p95
            UNION ALL
            SELECT fs.device_id, fs.p95_value
            FROM fallback_single fs
            WHERE NOT EXISTS (
                SELECT 1
                FROM p95 p
                WHERE p.device_id = fs.device_id
            )
        )
        SELECT
            m.device_id,
            m.p95_value
        FROM merged m
        ORDER BY m.device_id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lookback', $lookbackSeconds, PDO::PARAM_INT);
    $stmt->bindValue(':percentile', $percentile);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function save_thresholds(PDO $pdo, array $rows): array
{
    $sql = "
        INSERT INTO setting_info (setting_name, setting_value)
        VALUES (:setting_name, :setting_value)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value)
    ";

    $stmt = $pdo->prepare($sql);

    $saved = [];

    foreach ($rows as $row) {

        $deviceId = (int)$row['device_id'];
        $value = (float)$row['p95_value'];

        if ($deviceId <= 0) {
            continue;
        }

        $settingName = "device.{$deviceId}.noisy_threshold";
        $settingValue = normalize_threshold($value);

        $stmt->execute([
            ':setting_name' => $settingName,
            ':setting_value' => $settingValue,
        ]);

        $saved[] = [
            'device_id' => $deviceId,
            'setting_name' => $settingName,
            'setting_value' => (float)$settingValue
        ];
    }

    return $saved;
}

try {

    require_post_method();

    // bodyは必須ではない
    request_json();

    $lookbackSeconds = 30;
    $percentile = 0.95;

    $pdo = db();

    $rows = calculate_thresholds($pdo, $lookbackSeconds, $percentile);

    if (!$rows) {
        json_ok([
            'message' => '過去30秒のデータがありませんでした。',
            'saved_count' => 0,
            'lookback_seconds' => $lookbackSeconds,
            'method' => 'p95',
            'data' => [],
        ]);
    }

    $pdo->beginTransaction();

    $saved = save_thresholds($pdo, $rows);

    $pdo->commit();

    json_ok([
        'message' => '95パーセンタイル方式で基準音レベルを保存しました。',
        'saved_count' => count($saved),
        'lookback_seconds' => $lookbackSeconds,
        'method' => 'p95',
        'data' => $saved,
    ]);

} catch (RuntimeException $e) {

    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_error($e->getMessage(), 400);

} catch (Throwable $e) {

    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[api/sensors/set-threshold] ' . $e->getMessage());

    json_error('基準音レベルの保存中にエラーが発生しました', 500);
}