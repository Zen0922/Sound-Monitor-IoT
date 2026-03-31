#!/bin/bash
set -e

export DISPLAY=:0
export XAUTHORITY=/home/sound-monitor-pi/.Xauthority

sleep 8

# 画面制御
xset s off || true
xset -dpms || true
xset s noblank || true

# Chromium起動
exec /usr/bin/chromium \
  --kiosk \
  --noerrdialogs \
  --disable-infobars \
  --disable-session-crashed-bubble \
  --no-first-run \
  --disable-features=TranslateUI \
  http://localhost/sound-monitor/sound-monitor.php