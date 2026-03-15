<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/app/lib/db.php';

try {
    $db = db();
} catch (Throwable $e) {
    exit('DB接続エラー: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!isset($_POST['rows']) || !is_array($_POST['rows'])) {
            throw new RuntimeException('POSTデータ rows がありません。');
        }

        $sql = "
            UPDATE sensor_device
            SET
                device_memo_name = :device_memo_name,
                display_order = :display_order
            WHERE device_id = :device_id
        ";
        $stmt = $db->prepare($sql);

        foreach ($_POST['rows'] as $deviceId => $row) {
            $deviceId = (int)$deviceId;

            if ($deviceId <= 0) {
                continue;
            }

            $deviceMemoName = isset($row['device_memo_name'])
                ? trim((string)$row['device_memo_name'])
                : '';

            $displayOrder = isset($row['display_order']) && $row['display_order'] !== ''
                ? (int)$row['display_order']
                : 9999;

            $stmt->execute([
                ':device_id' => $deviceId,
                ':device_memo_name' => $deviceMemoName,
                ':display_order' => $displayOrder,
            ]);
        }

        header('Location: sensor-settings.php?ok=1');
        exit;
    }

    $devicesStmt = $db->query("
        SELECT
            device_id,
            device_bt_addr,
            device_bt_name,
            device_memo_name,
            display_order
        FROM sensor_device
        ORDER BY display_order ASC, device_id ASC
    ");
    $devices = $devicesStmt->fetchAll(PDO::FETCH_ASSOC);

    $ignoredStmt = $db->query("
        SELECT
            device_bt_addr,
            device_bt_name,
            detected_first_time,
            is_ignored
        FROM detected_sensor_device
        WHERE is_ignored = 1
        ORDER BY detected_first_time ASC, device_bt_addr ASC
    ");
    $ignoredDevices = $ignoredStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    exit('処理エラー: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1, viewport-fit=cover"
>
<title>センサー設定</title>
<link rel="stylesheet" href="css/sensor-settings.css">
</head>
<body>

<?php if (isset($_GET['ok'])): ?>
    <div class="save-toast show" id="saveToast">保存しました</div>
<?php endif; ?>

<div class="page">
    <div class="header">
        <h1 class="title">センサー設定</h1>
        <p class="sub">名前・順番・閾値・リング開始角度を設定し、不要な機器は削除できます。無視した機器の再表示もできます</p>
    </div>

    <form method="post" id="settingsForm">
        <div class="toolbar">
            <button type="submit" class="action-btn save-btn" id="saveBtn">保存</button>
        </div>

        <div class="scroll-area" id="scrollArea">
            <div class="list" id="sensorList">

                <div class="section-card">
                    <h2 class="section-title">リング開始角度</h2>
                    <p class="section-sub">
                        デバイス数に合わせて、いずれかの区切り線が 0° / 90° / 180° / 270° に一致する角度だけ選べます。
                    </p>

                    <div class="info-row">
                        <span class="info-label">開始角度</span>
                        <select class="angle-select" id="ringStartAngle"></select>
                        <div class="angle-help" id="angleHelp">読み込み中...</div>
                    </div>

                    <div class="error-box" id="globalErrorBox"></div>
                </div>

                <div class="section-card">
                    <h2 class="section-title">無視した機器</h2>
                    <p class="section-sub">
                        「登録しない」にした機器の一覧です。再度候補に出したい場合は「無視を解除」を押してください。
                    </p>

                    <?php if (!empty($ignoredDevices)): ?>
                        <div class="ignored-list" id="ignoredList">
                            <?php foreach ($ignoredDevices as $ignored): ?>
                                <div
                                    class="ignored-item"
                                    data-ignored-bt-addr="<?= htmlspecialchars((string)$ignored['device_bt_addr'], ENT_QUOTES, 'UTF-8') ?>"
                                >
                                    <div>
                                        <p class="ignored-name">
                                            <?= htmlspecialchars((string)$ignored['device_bt_name'] !== '' ? (string)$ignored['device_bt_name'] : '名称不明', ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                        <p class="ignored-meta">BTアドレス: <?= htmlspecialchars((string)$ignored['device_bt_addr'], ENT_QUOTES, 'UTF-8') ?></p>
                                        <p class="ignored-meta">初回検出: <?= htmlspecialchars((string)$ignored['detected_first_time'], ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <button
                                        type="button"
                                        class="restore-btn"
                                        data-restore-bt-addr="<?= htmlspecialchars((string)$ignored['device_bt_addr'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-restore-device-name="<?= htmlspecialchars((string)$ignored['device_bt_name'] !== '' ? (string)$ignored['device_bt_name'] : (string)$ignored['device_bt_addr'], ENT_QUOTES, 'UTF-8') ?>"
                                    >無視を解除</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-note" id="ignoredListEmpty">無視している機器はありません。</div>
                    <?php endif; ?>
                </div>

                <?php foreach ($devices as $d): ?>
                    <div class="sensor-card" data-device-id="<?= (int)$d['device_id'] ?>">
                        <div class="card-top">
                            <div class="device-id">ID: <?= (int)$d['device_id'] ?></div>
                            <div class="order-badge">
                                <span class="order-number"><?= (int)$d['display_order'] ?></span>番
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">BTアドレス</span>
                            <div class="info-value mono">
                                <?= htmlspecialchars((string)$d['device_bt_addr'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">元の名前</span>
                            <div class="info-value">
                                <?= htmlspecialchars((string)$d['device_bt_name'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">表示名</span>
                            <input
                                class="name-input"
                                type="text"
                                name="rows[<?= (int)$d['device_id'] ?>][device_memo_name]"
                                value="<?= htmlspecialchars((string)$d['device_memo_name'], ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="255"
                                placeholder="表示名を入力"
                            >
                        </div>

                        <div class="info-row">
                            <span class="info-label">表示順（直接入力）</span>
                            <input
                                class="order-direct-input"
                                type="number"
                                min="1"
                                step="1"
                                value="<?= max(1, (int)$d['display_order']) ?>"
                                placeholder="表示順"
                            >
                        </div>

                        <div class="info-row">
                            <span class="info-label">基準音レベル</span>
                            <div class="threshold-row">
                                <input
                                    class="threshold-input"
                                    type="number"
                                    inputmode="decimal"
                                    step="0.1"
                                    min="0"
                                    data-device-id="<?= (int)$d['device_id'] ?>"
                                    placeholder="例: 8.5"
                                >
                                <div class="current-value-badge" data-current-value="<?= (int)$d['device_id'] ?>">
                                    読込中
                                </div>
                            </div>
                        </div>

                        <div class="card-actions">
                            <button type="button" class="move-btn move-up">↑ 上へ</button>
                            <button type="button" class="move-btn move-down">↓ 下へ</button>
                        </div>

                        <div class="card-actions-secondary">
                            <button
                                type="button"
                                class="delete-btn"
                                data-delete-device-id="<?= (int)$d['device_id'] ?>"
                                data-delete-device-name="<?= htmlspecialchars((string)$d['device_memo_name'] !== '' ? (string)$d['device_memo_name'] : (string)$d['device_bt_name'], ENT_QUOTES, 'UTF-8') ?>"
                            >削除</button>
                        </div>

                        <input
                            class="order-input"
                            type="hidden"
                            name="rows[<?= (int)$d['device_id'] ?>][display_order]"
                            value="<?= (int)$d['display_order'] ?>"
                        >
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </form>
</div>

<script src="js/sensor-settings.js"></script>
</body>
</html>