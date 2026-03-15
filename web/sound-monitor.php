<?php
if (isset($_GET['qr']) && $_GET['qr'] === '1') {
  $text = isset($_GET['text']) ? (string)$_GET['text'] : '';
  $text = trim($text);

  if ($text === '' || strlen($text) > 2048) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "invalid qr text";
    exit;
  }

  $python = <<<'PY'
import io
import sys
import qrcode

text = sys.stdin.read()
qr = qrcode.QRCode(
    version=None,
    error_correction=qrcode.constants.ERROR_CORRECT_M,
    box_size=8,
    border=2,
)
qr.add_data(text)
qr.make(fit=True)
img = qr.make_image(fill_color="black", back_color="white")
buf = io.BytesIO()
img.save(buf, format="PNG")
sys.stdout.buffer.write(buf.getvalue())
PY;

  $cmd = 'python3 -c ' . escapeshellarg($python);
  $descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
  ];

  $process = @proc_open($cmd, $descriptorSpec, $pipes);
  if (!is_resource($process)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "failed to start qr generator";
    exit;
  }

  fwrite($pipes[0], $text);
  fclose($pipes[0]);

  $png = stream_get_contents($pipes[1]);
  fclose($pipes[1]);

  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[2]);

  $exitCode = proc_close($process);

  if ($exitCode !== 0 || $png === false || $png === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "qr generation failed\n";
    if ($stderr) {
      echo $stderr;
    }
    exit;
  }

  header('Content-Type: image/png');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  echo $png;
  exit;
}

function pick_private_ipv4(array $candidates): string
{
  foreach ($candidates as $candidate) {
    $candidate = trim((string)$candidate);
    if ($candidate === '') {
      continue;
    }
    if (!filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
      continue;
    }
    if (
      preg_match('/^(10\.|127\.|169\.254\.|172\.(1[6-9]|2\d|3[0-1])\.|192\.168\.)/', $candidate)
      || $candidate === '0.0.0.0'
    ) {
      return $candidate;
    }
  }

  foreach ($candidates as $candidate) {
    $candidate = trim((string)$candidate);
    if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
      return $candidate;
    }
  }

  return '';
}

$serverName = (string)($_SERVER['SERVER_NAME'] ?? '');
$httpHost = (string)($_SERVER['HTTP_HOST'] ?? '');
$serverAddr = (string)($_SERVER['SERVER_ADDR'] ?? '');
$hostName = (string)gethostname();
$resolvedHostIp = $hostName !== '' ? (string)@gethostbyname($hostName) : '';

$localHost = $hostName !== '' ? $hostName : 'raspberrypi';
if (strpos($localHost, '.') === false) {
  $localHost .= '.local';
}
if (stripos($serverName, '.local') !== false) {
  $localHost = preg_replace('/:\d+$/', '', $serverName);
}
if (stripos($httpHost, '.local') !== false) {
  $localHost = preg_replace('/:\d+$/', '', $httpHost);
}

$serverIp = pick_private_ipv4([$serverAddr, $resolvedHostIp]);
?>
<!doctype html>
<html lang="ja">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Sound Monitor</title>
  <link rel="stylesheet" href="./css/sound-monitor.css">
</head>

<body>
  <div class="wrap">
    <div class="stage">
      <canvas id="viz" width="800" height="800"></canvas>

      <div class="center-panel">
        <button id="btnNoisy" class="center-btn btn-noisy" type="button" aria-label="基準音レベルセット">
          <span class="content">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
              <path fill="currentColor" d="M3 9v6h4l5 4V5L7 9H3z" />
              <path fill="currentColor" d="M14.3 12c0-1.87-1.08-3.48-2.65-4.26v8.52c1.57-.78 2.65-2.39 2.65-4.26z" />
              <path d="M16.3 5.1C18.6 7.2 19.7 9.5 19.7 12c0 2.5-1.1 4.8-3.4 6.9"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none" />
              <path d="M18.6 2.4C21.5 5.2 22.8 8.2 22.8 12c0 3.8-1.3 6.8-4.2 9.6"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none" />
            </svg>
            <span class="label">基準音レベルセット</span>
            <span class="sub">うるさいと思ったら押してください</span>
          </span>
        </button>

        <button id="btnPair" class="center-btn btn-pair" type="button" aria-label="新しい機器を接続">
          <span class="content">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
              <path fill="currentColor" d="M12.7 12l4.6-4.6a1 1 0 0 0 0-1.4L13.41 2.1A1 1 0 0 0 11.7 2.8v6.78L8.41 6.3A1 1 0 0 0 7 7.71L11.29 12L7 16.29a1 1 0 0 0 1.41 1.41l3.29-3.29v6.79a1 1 0 0 0 1.71.7l3.89-3.9a1 1 0 0 0 0-1.4L12.7 12Zm1-6.79 1.47 1.49-1.47 1.47V5.21Zm0 13.58v-2.96l1.47 1.47-1.47 1.49Z" />
            </svg>
            <span class="label">機器接続</span>
          </span>
        </button>

        <button id="btnSettings" class="center-btn btn-settings" type="button" aria-label="設定画面">
          <span class="content">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
              <path fill="currentColor" d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.2 7.2 0 0 0-1.63-.94l-.36-2.54a.5.5 0 0 0-.5-.42h-3.84a.5.5 0 0 0-.5.42l-.36 2.54c-.57.23-1.11.54-1.63.94l-2.39-.96a.5.5 0 0 0-.6.22L2.7 8.84a.5.5 0 0 0 .12.64l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94L2.82 14.52a.5.5 0 0 0-.12.64l1.92 3.32a.5.5 0 0 0 .6.22l2.39-.96c.5.39 1.05.71 1.63.94l.36 2.54a.5.5 0 0 0 .5.42h3.84a.5.5 0 0 0 .5-.42l.36-2.54c.57-.23 1.12-.55 1.63-.94l2.39.96a.5.5 0 0 0 .6-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58ZM12 15.5A3.5 3.5 0 1 1 12 8.5a3.5 3.5 0 0 1 0 7Z" />
            </svg>
            <span class="label">設定</span>
          </span>
        </button>
      </div>

      <div id="settingsOverlay" class="settings-overlay" aria-hidden="true">
        <div>
          <h2>設定画面アクセス</h2>
          <p>スマートフォンまたはPCで開いて設定できます</p>

          <div class="qr-box">
            <img id="settingsQrImage" alt="設定画面のQRコード">
          </div>

          <div class="qr-url-list">
            <div class="qr-url-item">
              <span id="settingsUrlLocal" class="qr-url-value"></span>
            </div>

            <div class="qr-or">または</div>

            <div class="qr-url-item">
              <span id="settingsUrlIp" class="qr-url-value"></span>
            </div>
          </div>

          <div class="note">画面タップ、または60秒後に元へ戻ります</div>
        </div>
      </div>

      <div id="pairingOverlay" class="pairing-overlay" aria-hidden="true">
        <div class="pairing-dialog" role="dialog" aria-modal="true" aria-labelledby="pairingDialogTitle">
          <div class="pairing-dialog-header">
            <div>
              <h2 id="pairingDialogTitle" class="pairing-dialog-title">接続する機器を選択</h2>
              <p class="pairing-dialog-subtitle">新しく検出した機器を登録できます</p>
            </div>
            <button id="btnPairingClose" class="pairing-close" type="button" aria-label="閉じる">×</button>
          </div>

          <div id="pairingList" class="pairing-list"></div>

          <div class="pairing-footer">登録しない機器は無視できます。無視した機器は以後この画面に表示されません</div>
        </div>
      </div>

      <div id="toast" class="toast"></div>
    </div>
  </div>

  <script>
  window.SOUND_MONITOR_CONFIG = {
    SERVER_LOCAL_HOST: <?php echo json_encode($localHost, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    SERVER_IP: <?php echo json_encode($serverIp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
  };
</script>
<script src="./js/sound-monitor.js"></script>

</body>

</html>