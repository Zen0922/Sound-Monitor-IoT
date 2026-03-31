# 🎧 Sound Monitor IoT

![Platform](https://img.shields.io/badge/platform-Raspberry%20Pi-blue)
![Language](https://img.shields.io/badge/language-Python%20%7C%20PHP-green)
![License](https://img.shields.io/badge/license-MIT-lightgrey)

Raspberry Pi + BLE センサーを用いて、環境音を収集・可視化する IoT システムです。
An IoT system that collects and visualizes environmental sound using Raspberry Pi and BLE sensors.

音量に応じてディスプレイの明るさを自動制御し、円形ディスプレイに表示します。
The display brightness is automatically adjusted based on sound levels and shown on a circular display.

---

## 🖥️ デモイメージ / Demo

（スクリーンショットを追加予定）
(Screenshots will be added here)

---

## 🧠 特徴 / Features

* 🔊 BLEセンサーによる音量収集
  Sound level collection via BLE sensors

* 📊 リアルタイム可視化（円形UI）
  Real-time visualization (circular UI)

* 💡 音量に応じた自動明度制御
  Automatic brightness control based on sound level

* 🖥 Chromium kiosk による専用表示
  Dedicated display using Chromium kiosk mode

* 🔄 systemd による自動起動・安定運用
  Automatic startup and stable operation with systemd

* 🧩 マイクロサービス構成
  Microservice-based architecture

---

## 🏗️ システム構成 / Architecture

```text
[ESP32 / BLE Sensors]
        ↓
[Raspberry Pi (Collector + DB)]
        ↓
[PHP Web UI]
        ↓
[Chromium Kiosk Display]
```

---

## 📁 ディレクトリ構成 / Project Structure

```text
Sound-Monitor-IoT/
├── pi/
│   ├── scripts/          # 起動スクリプト / Startup scripts
│   │   └── start_kiosk.sh
│   │
│   ├── systemd/          # systemdサービス定義 / Service definitions
│   │   ├── kiosk.service
│   │   ├── sound-monitor.service
│   │   ├── sound-monitor-detector.service
│   │   └── display-brightness.service
│   │
│   └── README.md
│
├── ble/                  # BLE収集 / BLE collector
│   └── collector_read.py
│
├── server/               # Webアプリ / Web application
│   └── php/
│       └── sound-monitor.php
│
└── docs/                 # ドキュメント / Documentation
```

---

## ⚙️ systemdサービス一覧 / Services

| Service                        | 説明 (JP)          | Description (EN)              |
| ------------------------------ | ---------------- | ----------------------------- |
| kiosk.service                  | Chromium kiosk起動 | Launch Chromium in kiosk mode |
| sound-monitor.service          | BLEセンサー収集        | Collect BLE sensor data       |
| sound-monitor-detector.service | センサーデバイス検出       | Detect BLE devices            |
| display-brightness.service     | 明度制御             | Control display brightness    |

---

## 🚀 セットアップ / Setup

### ① リポジトリ取得 / Clone repository

```bash
git clone https://github.com/Zen0922/Sound-Monitor-IoT.git
cd Sound-Monitor-IoT
```

---

### ② 実行権限付与 / Grant execute permission

```bash
chmod +x pi/scripts/start_kiosk.sh
```

---

### ③ systemdへ配置 / Install services

```bash
sudo cp pi/systemd/*.service /etc/systemd/system/
```

---

### ④ リロード / Reload systemd

```bash
sudo systemctl daemon-reload
```

---

### ⑤ 有効化 / Enable services

```bash
sudo systemctl enable kiosk.service
sudo systemctl enable sound-monitor.service
sudo systemctl enable sound-monitor-detector.service
sudo systemctl enable display-brightness.service
```

---

### ⑥ 起動 / Start services

```bash
sudo systemctl start kiosk.service
sudo systemctl start sound-monitor.service
sudo systemctl start sound-monitor-detector.service
sudo systemctl start display-brightness.service
```

---

## 🔍 ログ確認 / Logs

```bash
journalctl -u kiosk.service -f
```

---

## 🛠 技術スタック / Tech Stack

* Raspberry Pi OS
* Python 3
* MariaDB / MySQL
* PHP
* Chromium

---

## 📌 今後の予定 / Roadmap

* 自動アップデート機能 / Auto update system
* MQTT対応 / MQTT support
* クラウド連携 / Cloud integration
* UI改善（円形最適化） / UI improvements (circular optimization)

---

## 📄 ライセンス / License

MIT License
