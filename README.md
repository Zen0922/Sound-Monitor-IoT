# Sound Monitor IoT

[![Platform](https://img.shields.io/badge/platform-Raspberry%20Pi-blue)]()
[![Sensor](https://img.shields.io/badge/sensor-ESP32-green)]()
[![Database](https://img.shields.io/badge/database-MariaDB-orange)]()
[![Language](https://img.shields.io/badge/language-Python%20%7C%20PHP-lightgrey)]()
[![License](https://img.shields.io/badge/license-MIT-blue)]()

ESP32センサーとRaspberry Piを使用したIoT音量監視システム\
IoT sound monitoring system using ESP32 sensors and Raspberry Pi

------------------------------------------------------------------------

# Overview / 概要

This project collects sound level data from distributed sensors and
displays the information on a monitoring dashboard.

このプロジェクトは、複数の音量センサーからデータを収集し、 Raspberry
Piをゲートウェイとしてデータベースに保存し、
Webダッシュボードやモニターに表示するIoTシステムです。

The system is designed for continuous environmental sound monitoring.
環境音量の継続的な監視を目的として設計されています。

------------------------------------------------------------------------

# System Architecture / システム構成

    +----------------------+
    | ESP32 Sound Sensor   |
    | ICS-43434 Microphone |
    +----------+-----------+
               |
               | Bluetooth Low Energy (BLE)
               |
    +----------v-----------+
    | Raspberry Pi Gateway |
    | Collector Service    |
    +----------+-----------+
               |
               | MariaDB
               |
    +----------v-----------+
    | Web Dashboard        |
    +----------+-----------+
               |
               |
    +----------v-----------+
    | Display Monitor      |
    +----------------------+

ESP32センサーがBluetooth Low Energy (BLE)で音量データを送信し、
Raspberry Piがデータを収集してデータベースへ保存します。

ESP32 sensors transmit sound level data via BLE and the Raspberry Pi
stores the data in the database.

------------------------------------------------------------------------

# Features / 主な機能

## Sound sensor network / 音量センサーネットワーク

Multiple ESP32-based sensors measure sound levels.\
ESP32ベースの複数センサーが音量を測定します。

## BLE data collection / BLEデータ収集

Raspberry Pi collects sensor data via Bluetooth Low Energy.\
Raspberry PiがBLEでセンサーデータを収集します。

## Database storage / データベース保存

All measurement data is stored in MariaDB.\
測定データはMariaDBデータベースに保存されます。

## Web dashboard / Webダッシュボード

Users can monitor sound levels using a web browser.\
ブラウザから音量状況を確認できます。

## Display monitor / モニター表示

A dedicated display shows the latest sensor information.\
専用ディスプレイに最新のセンサー情報を表示します。

## Automatic display brightness / 自動明度制御

Display brightness automatically adjusts based on activity.\
活動状況に応じてディスプレイの明るさを自動制御します。

------------------------------------------------------------------------

# Hardware / 使用ハードウェア

## Sensor Device / センサーデバイス

-   Seeed Studio XIAO ESP32S3
-   ICS-43434 MEMS microphone

## Gateway / ゲートウェイ

-   Raspberry Pi 5
-   Raspberry Pi OS

## Display / 表示装置

-   Circular display module (example: Waveshare)

------------------------------------------------------------------------

# Software / 使用ソフトウェア

-   Python
-   PHP
-   MariaDB
-   Bluetooth Low Energy (BLE)
-   systemd

------------------------------------------------------------------------

# Project Structure / ディレクトリ構成

    sound-monitor-iot
    │
    ├ src/           application source code / アプリケーションコード
    ├ config/        configuration files / 設定ファイル
    ├ systemd/       service definitions / systemd設定
    ├ sql/           database schema / DB定義
    ├ scripts/       utility scripts / 運用スクリプト
    └ docs/          documentation / ドキュメント

------------------------------------------------------------------------

# Installation / インストール

## Clone repository / リポジトリ取得

    git clone https://github.com/Zen0922/Sound-Monitor-IoT.git

## Install dependencies / 依存関係インストール

    pip install -r requirements.txt

## Setup database / データベース構築

    mysql < sql/schema.sql

## Start service / サービス起動

    sudo systemctl enable sound-monitor.service
    sudo systemctl start sound-monitor.service

------------------------------------------------------------------------

# Screenshots / 画面例

Add screenshots in the `docs` directory.\
`docs` ディレクトリに画面イメージを追加できます。

Example:

    docs/dashboard.png
    docs/sensor-device.jpg
    docs/system-diagram.png

------------------------------------------------------------------------

# License / ライセンス

This project is licensed under the MIT License.

このプロジェクトはMITライセンスのもとで公開されています。

------------------------------------------------------------------------

# Author / 作者

Zen

GitHub\
https://github.com/Zen0922
