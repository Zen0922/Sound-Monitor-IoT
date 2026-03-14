# gateway/common/config.py

DB_CONFIG = {
    "host": "localhost",
    "user": "your_user",
    "password": "your_password",
    "database": "sound_monitor",
}

BLE = {
    "scan_prefix": "RP-SoundSensor-",
    "sound_service_uuid": "c0de0001-3b3a-4f2c-9a3a-1b2c3d4e5001",
    "sound_level_uuid":   "c0de0002-3b3a-4f2c-9a3a-1b2c3d4e5001",
    "baseline_uuid":      "c0de0003-3b3a-4f2c-9a3a-1b2c3d4e5001",
}

DETECTOR = {
    "scan_timeout_sec": 4.0,
    "scan_interval_sec": 5.0,
}

COLLECTOR = {
    "connect_timeout_sec": 10,
    "reconnect_wait_sec": 3,
    "save_interval_sec": 1.0,
    "save_mode": "scaled",   # "raw" or "scaled"
}