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
    "data_retention_days": 10,
}


# ============================================================
# ディスプレイ明るさ制御設定
# Display brightness control settings
# ============================================================

DISPLAY_BRIGHTNESS = {
    # 通常時の明るさ（%）
    # Brightness in active state (%)
    "active_brightness": 50,

    # 第1段階減光時の明るさ（%）
    # Brightness in first dim state (%)
    "dim_brightness_1": 20,

    # 第2段階減光時の明るさ（%）
    # Brightness in second dim state (%)
    "dim_brightness_2": 5,

    # 消灯時の明るさ（%）
    # Brightness in off state (%)
    "off_brightness": 0,

    # 無活動から第1段階減光までの時間（秒）
    # Seconds until first dim level after inactivity
    "dim_after_sec_1": 5 * 60,

    # 無活動から第2段階減光までの時間（秒）
    # Seconds until second dim level after inactivity
    "dim_after_sec_2": 15 * 60,

    # 無活動から消灯までの時間（秒）
    # Seconds until off after inactivity
    "off_after_sec": 30 * 60,

    # 明るさ判定ループ間隔（秒）
    # Brightness evaluation loop interval (seconds)
    "poll_interval_sec": 10,

    # 最新値として扱う鮮度の秒数
    # Freshness threshold for latest sensor values (seconds)
    "device_freshness_sec": 30,

    # 現在音量状態のキャッシュ秒数
    # Cache lifetime for current sound status (seconds)
    "current_sound_cache_sec": 30,

    # バックライト制御ファイルのパス
    # Backlight control file path
    "backlight_path": "/sys/class/backlight/10-0045/brightness",

    # max_brightness が取得できない場合の代替値
    # Fallback max brightness when max_brightness cannot be read
    "backlight_max_fallback": 255,

    # 判定対象の value_type
    # Target value_type for sensor_value query
    "target_value_type": "level",

    # ログレベル
    # Logging level
    "log_level": "INFO",

    # 動的閾値計算で参照する過去時間（時間）
    # Lookback window for dynamic threshold calculation (hours)
    "threshold_lookback_hours": 48,

    # 動的閾値計算時に除外しない最小値
    # Minimum level to keep for threshold calculation
    "level_floor_for_threshold": 6.0,

    # quiet_level 算出に使うパーセンタイル
    # Percentile used to calculate quiet_level
    "quiet_percentile": 35.0,

    # quiet_level に加算するマージン
    # Margin added to quiet_level
    "threshold_margin": 4.0,

    # 動的閾値の再計算間隔（秒）
    # Refresh interval for dynamic threshold (seconds)
    "threshold_refresh_sec": 10 * 60,

    # サンプル不足時に使う既定閾値
    # Fallback noise threshold when samples are insufficient
    "fallback_noise_threshold": 15.0,

    # 動的閾値の最小値
    # Minimum allowed dynamic threshold
    "min_noise_threshold": 10.0,

    # 動的閾値の最大値
    # Maximum allowed dynamic threshold
    "max_noise_threshold": 25.0,

    # 動的閾値計算に必要な最小サンプル数
    # Minimum sample count required for threshold calculation
    "min_threshold_sample_count": 100,
}
