# Sound Monitor IoT
ESP32センサーとRaspberry Piを利用したIoT音量監視システム  
IoT sound monitoring system using ESP32 sensors and Raspberry Pi

---

# Overview / 概要

このプロジェクトは、複数の音量センサーから取得したデータを収集し、  
Raspberry Piをゲートウェイとしてデータベースに保存し、  
Web画面およびモニターに表示するIoTシステムです。

This project collects sound level data from multiple sensors,
stores them in a database via a Raspberry Pi gateway,
and displays them on a web dashboard and monitor.

---

# System Architecture / システム構成
ESP32 Sound Sensor
  ↓ BLE
Raspberry Pi Gateway
  ↓
MariaDB Database
  ↓
Web Dashboard
  ↓
Display Monitor


ESP32センサーがBluetooth Low Energy (BLE) で音量データを送信し、  
Raspberry Piがそれを収集してデータベースへ保存します。

ESP32 sensors send sound level data via Bluetooth Low Energy (BLE),
and the Raspberry Pi collects and stores the data in a database.

---

# Features / 主な機能

### Sensor data collection / センサーデータ収集
BLEを使用して複数の音量センサーからデータを取得します。

Collect sound level data from multiple BLE sensors.

---

### Database storage / データベース保存
MariaDBに測定データを保存します。

Store measurement data in MariaDB.

---

### Web dashboard / Webダッシュボード
ブラウザから最新のセンサーデータを確認できます。

View latest sensor data from a web dashboard.

---

### Display monitor / モニター表示
専用ディスプレイに音量情報を表示します。

Display sound level information on a dedicated monitor.

---

### Automatic brightness control / 明るさ自動制御
環境状況に応じてディスプレイの明るさを自動調整します。

Automatically adjust display brightness depending on activity.

---

# Hardware / 使用ハードウェア

### Sensor Device / センサーデバイス

- XIAO ESP32S3
- ICS-43434 MEMS microphone

---

### Gateway / ゲートウェイ

- Raspberry Pi 5
- Raspberry Pi OS

---

### Display / 表示装置

- Circular display module (Waveshare etc.)

---

# Software / 使用ソフトウェア

- Python
- PHP
- MariaDB
- Bluetooth Low Energy (BLE)
- systemd

---

# Directory Structure / ディレクトリ構成
sound-monitor-iot/
├ src/ アプリケーションコード / application code
├ config/ 設定ファイル / configuration files
├ systemd/ systemdサービス定義 / service definitions
├ sql/ データベース定義 / database schema
├ docs/ ドキュメント / documentation
└ scripts/ 運用スクリプト / utility scripts

---

# License / ライセンス

This project is licensed under the MIT License.

このプロジェクトはMITライセンスのもとで公開されています。

---

# Author / 作者

Zen

GitHub  
https://github.com/Zen0922

