# 🎧 Sound Monitor IoT

ESP32センサーとRaspberry Piを使用したIoT音量監視システム
IoT sound monitoring system using ESP32 sensors and Raspberry Pi

---

## 🧠 概要 / Overview

複数の音量センサーからデータを収集し、Raspberry Piをゲートウェイとしてデータベースに蓄積、Web UIおよびディスプレイに可視化するシステムです。
This system collects sound data from distributed sensors, stores it via a Raspberry Pi gateway, and visualizes it on a web dashboard and display.

---

## 🏗️ システム構成 / Architecture

```text
[ESP32 Sound Sensor]
        ↓ (BLE)
[Raspberry Pi Gateway]
        ├─ Data Collection (Python)
        ├─ MariaDB
        └─ Web Server (PHP)
        ↓
[Web Dashboard / Kiosk Display]
```

---

## 📁 ディレクトリ構成 / Project Structure

```text
Sound-Monitor-IoT/
├── docs/                            # ドキュメント / Documentation
│   └── （設計資料・画像など）
│
├── firmware/                        # センサーデバイス側
│   └── esp32s3_sound_sensor/        # ESP32用ファームウェア
│       └── （BLE送信・音量測定）
│
├── gateway/                         # Raspberry Pi側（中核）
│   └── （Pythonスクリプト・systemd等）
│       ├── collector / データ収集
│       ├── detector / デバイス検出
│       ├── brightness / 明度制御
│       └── kiosk / 表示制御
│
├── hardware/                        # ハード設計
│   └── enclosure/
│       └── sensor/                  # センサーケース（3Dデータ等）
│
├── web/                             # Webアプリケーション
│   └── （PHP UI・設定画面）
│
├── .gitignore
├── LICENSE
└── README.md
```

---

## 🧩 各フォルダの役割 / Directory Roles

### firmware/

ESP32センサーデバイスのファームウェア
Firmware for ESP32-based sound sensor devices

* 音量取得（マイク）
* BLEでデータ送信

---

### gateway/

Raspberry Pi側の中核処理
Core processing on Raspberry Pi

主な役割：

* BLEスキャン・データ収集
* センサーデバイス検出
* データベース保存
* 明度制御
* kiosk表示制御

---

### web/

可視化および操作UI
Web-based dashboard and control interface

主な役割：

* 音量表示
* センサー管理
* 閾値設定

---

### hardware/

ハードウェア設計データ
Hardware design assets

主な内容：

* センサーケース（3Dプリント用）
* 設置用パーツ

---

### docs/

ドキュメント・画像
Documentation and images

主な用途：

* 構成図
* スクリーンショット
* 設計資料

---

## ⚙️ システム構成（機能単位） / Functional Components

| 機能     | 内容                   |
| ------ | -------------------- |
| センサー   | ESP32 + マイクで音量測定     |
| 通信     | BLE広告パケット            |
| ゲートウェイ | Raspberry Pi         |
| DB     | MariaDB              |
| 表示     | PHP + Chromium kiosk |
| 制御     | systemd              |

---

## 🚀 セットアップ（概要） / Setup (Overview)

※詳細は各ディレクトリ内を参照
See each directory for detailed setup instructions.

---

## 🛠 技術スタック / Tech Stack

* Raspberry Pi OS
* Python 3
* PHP
* MariaDB
* Bluetooth Low Energy (BLE)
* systemd

---

## 📌 今後の予定 / Roadmap

* MQTT対応
  MQTT support

* クラウド連携
  Cloud integration

* UI改善（円形最適化）
  UI improvements (circular optimization)

* 自動アップデート
  Auto update system

---

## 📄 ライセンス / License

MIT License

---

## 👤 Author

Zen
GitHub: https://github.com/Zen0922
