<?php
declare(strict_types=1);

// SSE
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// 監視するファイル（必要に応じて追加）
$files = [
  __DIR__ . '/../sound-monitor.php',   // あなたのHTMLのパスに合わせて変更
//  __DIR__ . '/../app.js',       // JS分離しているなら
  __FILE__,                     // この監視スクリプト自体
];

// 最終更新時刻（最大値）を返す
$lastMtime = function() use ($files): int {
  $m = 0;
  foreach ($files as $f) {
    if (is_file($f)) $m = max($m, filemtime($f));
  }
  return $m;
};

$prev = $lastMtime();

// 接続維持（最大30分で終了→ブラウザが自動再接続）
$deadline = time() + 1800;

while (time() < $deadline) {
  clearstatcache(true);
  $now = $lastMtime();

  if ($now > $prev) {
    $prev = $now;
    echo "event: changed\n";
    echo "data: {\"mtime\":{$now}}\n\n";
    @ob_flush();
    @flush();
    break; // 1回通知したら終了（ブラウザ側でリロードするので）
  }

  // CPUを食わないように少し待機（リロードではない）
  usleep(300000); // 0.3秒
}

// タイムアウト時も一応通知（再接続を促す）
echo "event: ping\n";
echo "data: {}\n\n";
@ob_flush();
@flush();

?>