# ============================================================
# データベース接続設定
# Database connection settings
# ============================================================

DB_CONFIG = {
    # データベースサーバーのホスト名
    # Database server hostname
    "host": "localhost",

    # データベース接続ユーザー名
    # Database user name
    "user": "CHANGE_ME",

    # データベース接続パスワード
    # Database user password
    "password": "CHANGE_ME",

    # 使用するデータベース名
    # Database name
    "database": "sound_monitor",
}


# ============================================================
# BLEセンサー関連設定
# BLE sensor settings
# ============================================================

BLE = {
    # スキャン対象とするBLEデバイス名のプレフィックス
    # BLE device name prefix used for scanning
    "scan_prefix": "RP-SoundSensor-",

    # 音センサーが使用するBLEサービスUUID
    # BLE service UUID used by the sound sensor
    "sound_service_uuid": "c0de0001-3b3a-4f2c-9a3a-1b2c3d4e5001",

    # 音量レベルを取得するCharacteristic UUID
    # Characteristic UUID for sound level value
    "sound_level_uuid": "c0de0002-3b3a-4f2c-9a3a-1b2c3d4e5001",

    # ベースライン値を取得するCharacteristic UUID
    # Characteristic UUID for baseline value
    "baseline_uuid": "c0de0003-3b3a-4f2c-9a3a-1b2c3d4e5001",
}


# ============================================================
# センサーデバイス検出スクリプト設定
# Sensor detector settings
# ============================================================

DETECTOR = {
    # 1回のBLEスキャン時間（秒）
    # BLE scan duration for one scan cycle (seconds)
    "scan_timeout_sec": 4.0,

    # スキャン実行間隔（秒）
    # Interval between scan cycles (seconds)
    "scan_interval_sec": 5.0,
}


# ============================================================
# センサーデータ収集スクリプト設定
# Sensor data collector settings
# ============================================================

COLLECTOR = {
    # BLE接続タイムアウト（秒）
    # Timeout for BLE connection (seconds)
    "connect_timeout_sec": 10,

    # 切断後の再接続待機時間（秒）
    # Waiting time before reconnecting after disconnect (seconds)
    "reconnect_wait_sec": 3,

    # センサーデータ保存間隔（秒）
    # Minimum interval for saving sensor values (seconds)
    #
    # 0 を指定すると、受信した通知をすべて保存します
    # Set to 0 to save every received notification
    "save_interval_sec": 1.0,

    # measured_value の保存方式
    # Storage mode for measured_value
    #
    # raw    : rel10整数値をそのまま保存
    # raw    : Store raw rel10 integer value
    #
    # scaled : rel10 / 10 の値を保存（おおよそのdB表現）
    # scaled : Store rel10 / 10 (approximate dB representation)
    "save_mode": "scaled",
}


# ============================================================
# メンテナンス処理設定
# Maintenance settings
# ============================================================

MAINTENANCE = {
    # センサーデータの保持日数
    # Number of days to retain sensor data
    #
    # この日数より古い measured_datetime のデータを削除します
    # Delete rows whose measured_datetime is older than this number of days
    "data_retention_days": 4,
}
