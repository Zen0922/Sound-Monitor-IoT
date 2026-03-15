#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
display_brightness_control.py

各センサーの最新 level 値をDBから取得し、その最大値を使って
ディスプレイの明るさを制御する。

仕様:
- value_type='level' のみ対象
- device_id ごとの最新 level 値を使用
- 最新値の freshness を判定
- DBに有効な最新値が無い場合は 50% 固定
- 過去48時間の level から動的閾値を再計算
- 極低値を除外して 35パーセンタイルを quiet_level とする
- noise_threshold = quiet_level + margin
- 閾値再計算は 10分ごと
- 現在音量状態は 30秒キャッシュ
- 明るさ判定ループは 10秒ごと
- systemd watchdog / notify 対応

依存:
    pip install mysql-connector-python
"""

import logging
import math
import os
import signal
import socket
import sys
import time
from pathlib import Path

import mysql.connector

# ---------------------------------------------------------
# common/ を使えるようにする
# このスクリプトがプロジェクト直下にあり、common/ が同階層にある想定
# ---------------------------------------------------------
CURRENT_DIR = Path(__file__).resolve().parent
if str(CURRENT_DIR) not in sys.path:
    sys.path.insert(0, str(CURRENT_DIR))

from common import config
from common.db import get_db_connection


# =========================================================
# 設定読み込み
# =========================================================

DISPLAY_CONFIG = config.DISPLAY_BRIGHTNESS

ACTIVE_BRIGHTNESS = DISPLAY_CONFIG["active_brightness"]
DIM_BRIGHTNESS_1 = DISPLAY_CONFIG["dim_brightness_1"]
DIM_BRIGHTNESS_2 = DISPLAY_CONFIG["dim_brightness_2"]
OFF_BRIGHTNESS = DISPLAY_CONFIG["off_brightness"]

DIM_AFTER_SEC_1 = DISPLAY_CONFIG["dim_after_sec_1"]
DIM_AFTER_SEC_2 = DISPLAY_CONFIG["dim_after_sec_2"]
OFF_AFTER_SEC = DISPLAY_CONFIG["off_after_sec"]

POLL_INTERVAL_SEC = DISPLAY_CONFIG["poll_interval_sec"]
DEVICE_FRESHNESS_SEC = DISPLAY_CONFIG["device_freshness_sec"]
CURRENT_SOUND_CACHE_SEC = DISPLAY_CONFIG["current_sound_cache_sec"]

BACKLIGHT_PATH = DISPLAY_CONFIG["backlight_path"]
BACKLIGHT_MAX_FALLBACK = DISPLAY_CONFIG["backlight_max_fallback"]
TARGET_VALUE_TYPE = DISPLAY_CONFIG["target_value_type"]
LOG_LEVEL = DISPLAY_CONFIG["log_level"]

THRESHOLD_LOOKBACK_HOURS = DISPLAY_CONFIG["threshold_lookback_hours"]
LEVEL_FLOOR_FOR_THRESHOLD = DISPLAY_CONFIG["level_floor_for_threshold"]
QUIET_PERCENTILE = DISPLAY_CONFIG["quiet_percentile"]
THRESHOLD_MARGIN = DISPLAY_CONFIG["threshold_margin"]
THRESHOLD_REFRESH_SEC = DISPLAY_CONFIG["threshold_refresh_sec"]
FALLBACK_NOISE_THRESHOLD = DISPLAY_CONFIG["fallback_noise_threshold"]
MIN_NOISE_THRESHOLD = DISPLAY_CONFIG["min_noise_threshold"]
MAX_NOISE_THRESHOLD = DISPLAY_CONFIG["max_noise_threshold"]
MIN_THRESHOLD_SAMPLE_COUNT = DISPLAY_CONFIG["min_threshold_sample_count"]

_running = True


# =========================================================
# ログ
# =========================================================

def setup_logging():
    level = getattr(logging, str(LOG_LEVEL).upper(), logging.INFO)
    logging.basicConfig(
        level=level,
        format="%(asctime)s [%(levelname)s] %(message)s",
    )


# =========================================================
# シグナル
# =========================================================

def signal_handler(signum, frame):
    global _running
    logging.info("received signal %s", signum)
    _running = False


# =========================================================
# systemd notify
# =========================================================

class SystemdNotifier:
    def __init__(self):
        self.notify_socket = os.getenv("NOTIFY_SOCKET")

    def notify(self, message):
        if not self.notify_socket:
            return

        addr = self.notify_socket
        if addr.startswith("@"):
            addr = "\0" + addr[1:]

        sock = socket.socket(socket.AF_UNIX, socket.SOCK_DGRAM)
        try:
            sock.connect(addr)
            sock.sendall(message.encode("utf-8"))
        except Exception as e:
            logging.debug("systemd notify failed: %s", e)
        finally:
            sock.close()

    def ready(self):
        self.notify("READY=1")

    def watchdog(self):
        self.notify("WATCHDOG=1")

    def status(self, text):
        self.notify(f"STATUS={text}")


# =========================================================
# バックライト
# =========================================================

class DisplayBrightness:
    def __init__(self, path):
        self.path = Path(path)
        self.last_percent = None
        self.max_value = self.detect_max()

    def detect_max(self):
        try:
            max_path = self.path.parent / "max_brightness"
            if max_path.exists():
                value = int(max_path.read_text().strip())
                logging.info("max_brightness=%s", value)
                return value
        except Exception as e:
            logging.warning("max_brightness read failed: %s", e)

        return BACKLIGHT_MAX_FALLBACK

    def set_percent(self, percent):
        percent = max(0, min(100, int(percent)))

        if percent == self.last_percent:
            return

        raw = int(round(self.max_value * percent / 100.0))

        try:
            self.path.write_text(str(raw))
            self.last_percent = percent
            logging.info("[brightness] %s%% (raw=%s)", percent, raw)
        except Exception as e:
            logging.error("brightness write failed: %s", e)


# =========================================================
# DB
# =========================================================

def connect_db():
    """
    common/db.py の get_db_connection() を使って接続する。
    """
    conn = get_db_connection()

    cursor = conn.cursor()
    cursor.execute("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED")
    cursor.close()

    return conn


def get_current_sound_status(conn):
    """
    value_type='level' のみを対象に、device_id ごとの最新値を使って状態を返す。

    戻り値:
        fresh_level: float
            fresh な最新値群の最大値。fresh が無ければ 0.0
        has_any_data: bool
            level データが1件でも存在するか
        has_fresh_data: bool
            fresh な最新値が1件以上あるか
        newest_age_sec: int | None
            全 latest レコードの中で最も新しい recorded_datetime の経過秒
    """
    sql = """
        SELECT
            MAX(CASE
                WHEN latest.recorded_datetime >= (NOW() - INTERVAL %s SECOND)
                THEN latest.measured_value
                ELSE NULL
            END) AS fresh_max_value,
            TIMESTAMPDIFF(SECOND, MAX(latest.recorded_datetime), NOW()) AS newest_age_sec,
            COUNT(*) AS latest_count,
            MAX(CASE
                WHEN latest.recorded_datetime >= (NOW() - INTERVAL %s SECOND)
                THEN 1
                ELSE 0
            END) AS has_fresh_data
        FROM (
            SELECT sv.device_id, sv.measured_value, sv.recorded_datetime
            FROM sensor_value sv
            INNER JOIN (
                SELECT device_id, MAX(recorded_datetime) AS max_recorded_datetime
                FROM sensor_value
                WHERE value_type = %s
                GROUP BY device_id
            ) last_sv
              ON sv.device_id = last_sv.device_id
             AND sv.recorded_datetime = last_sv.max_recorded_datetime
             AND sv.value_type = %s
        ) latest
    """

    cursor = conn.cursor()
    cursor.execute(
        sql,
        (
            DEVICE_FRESHNESS_SEC,
            DEVICE_FRESHNESS_SEC,
            TARGET_VALUE_TYPE,
            TARGET_VALUE_TYPE,
        ),
    )
    row = cursor.fetchone()
    cursor.close()

    if not row:
        return 0.0, False, False, None

    fresh_max_value, newest_age_sec, latest_count, has_fresh_data = row

    has_any_data = bool(latest_count and latest_count > 0)
    has_fresh_data = bool(has_fresh_data)

    if not has_any_data:
        return 0.0, False, False, newest_age_sec

    if not has_fresh_data or fresh_max_value is None:
        return 0.0, True, False, newest_age_sec

    try:
        return float(fresh_max_value), True, True, newest_age_sec
    except (TypeError, ValueError):
        logging.warning("invalid fresh_max_value: %r", fresh_max_value)
        return 0.0, True, False, newest_age_sec


def get_level_history_for_threshold(conn):
    """
    動的閾値計算用に、過去THRESHOLD_LOOKBACK_HOURS時間の level を取得する。
    極端な値を避けるため、0以上100以下に絞る。
    """
    sql = """
        SELECT measured_value
        FROM sensor_value
        WHERE value_type = %s
          AND recorded_datetime >= (NOW() - INTERVAL %s HOUR)
          AND measured_value >= 0
          AND measured_value <= 100
    """

    cursor = conn.cursor()
    cursor.execute(sql, (TARGET_VALUE_TYPE, THRESHOLD_LOOKBACK_HOURS))
    rows = cursor.fetchall()
    cursor.close()

    values = []
    for row in rows:
        try:
            values.append(float(row[0]))
        except (TypeError, ValueError):
            continue

    return values


# =========================================================
# 統計
# =========================================================

def percentile(values, p):
    """
    線形補間でパーセンタイルを計算する。
    p: 0..100
    """
    if not values:
        return None

    sorted_values = sorted(values)

    if len(sorted_values) == 1:
        return sorted_values[0]

    p = max(0.0, min(100.0, float(p)))
    rank = (len(sorted_values) - 1) * (p / 100.0)

    lower_index = int(math.floor(rank))
    upper_index = int(math.ceil(rank))

    lower_value = sorted_values[lower_index]
    upper_value = sorted_values[upper_index]

    if lower_index == upper_index:
        return lower_value

    weight = rank - lower_index
    return lower_value + (upper_value - lower_value) * weight


def clamp(value, min_value, max_value):
    return max(min_value, min(max_value, value))


# =========================================================
# 動的閾値
# =========================================================

class DynamicThresholdManager:
    def __init__(self):
        self.current_threshold = FALLBACK_NOISE_THRESHOLD
        self.last_refresh_time = 0
        self.last_quiet_level = None
        self.last_sample_count = 0
        self.last_filtered_sample_count = 0

    def refresh_if_needed(self, conn, force=False):
        now = time.time()

        if not force and (now - self.last_refresh_time) < THRESHOLD_REFRESH_SEC:
            return

        values = get_level_history_for_threshold(conn)
        total_count = len(values)

        filtered_values = [
            v for v in values
            if v >= LEVEL_FLOOR_FOR_THRESHOLD
        ]
        filtered_count = len(filtered_values)

        if filtered_count < MIN_THRESHOLD_SAMPLE_COUNT:
            self.current_threshold = FALLBACK_NOISE_THRESHOLD
            self.last_quiet_level = None
            self.last_sample_count = total_count
            self.last_filtered_sample_count = filtered_count
            self.last_refresh_time = now

            logging.info(
                "[threshold] fallback used: threshold=%.2f total_count=%s filtered_count=%s reason=insufficient_samples",
                self.current_threshold,
                total_count,
                filtered_count,
            )
            return

        quiet_level = percentile(filtered_values, QUIET_PERCENTILE)

        if quiet_level is None:
            self.current_threshold = FALLBACK_NOISE_THRESHOLD
            self.last_quiet_level = None
            self.last_sample_count = total_count
            self.last_filtered_sample_count = filtered_count
            self.last_refresh_time = now

            logging.info(
                "[threshold] fallback used: threshold=%.2f total_count=%s filtered_count=%s reason=no_percentile",
                self.current_threshold,
                total_count,
                filtered_count,
            )
            return

        threshold = quiet_level + THRESHOLD_MARGIN
        threshold = clamp(threshold, MIN_NOISE_THRESHOLD, MAX_NOISE_THRESHOLD)

        self.current_threshold = float(threshold)
        self.last_quiet_level = float(quiet_level)
        self.last_sample_count = total_count
        self.last_filtered_sample_count = filtered_count
        self.last_refresh_time = now

        logging.info(
            "[threshold] recalculated: quiet_level=%.2f threshold=%.2f total_count=%s filtered_count=%s percentile=%.1f floor=%.1f margin=%.1f",
            self.last_quiet_level,
            self.current_threshold,
            self.last_sample_count,
            self.last_filtered_sample_count,
            QUIET_PERCENTILE,
            LEVEL_FLOOR_FOR_THRESHOLD,
            THRESHOLD_MARGIN,
        )


# =========================================================
# 現在音量キャッシュ
# =========================================================

class CurrentSoundCache:
    def __init__(self):
        self.last_refresh_time = 0
        self.cached_level = 0.0
        self.cached_has_any_data = False
        self.cached_has_fresh_data = False
        self.cached_newest_age_sec = None

    def refresh_if_needed(self, conn, force=False):
        now = time.time()

        if not force and (now - self.last_refresh_time) < CURRENT_SOUND_CACHE_SEC:
            return

        level, has_any_data, has_fresh_data, newest_age_sec = get_current_sound_status(conn)

        self.cached_level = level
        self.cached_has_any_data = has_any_data
        self.cached_has_fresh_data = has_fresh_data
        self.cached_newest_age_sec = newest_age_sec
        self.last_refresh_time = now

        logging.info(
            "[sound-cache] refreshed: level=%.1f has_any_data=%s has_fresh_data=%s newest_age_sec=%s",
            self.cached_level,
            self.cached_has_any_data,
            self.cached_has_fresh_data,
            self.cached_newest_age_sec,
        )

    def get(self):
        return (
            self.cached_level,
            self.cached_has_any_data,
            self.cached_has_fresh_data,
            self.cached_newest_age_sec,
        )


# =========================================================
# 明るさ制御
# =========================================================

class BrightnessController:
    def __init__(self):
        self.last_active_time = time.time()

    def mark_activity(self):
        self.last_active_time = time.time()

    def update(self, level, has_any_data, has_fresh_data, noise_threshold):
        """
        DBに値が無い場合:
            50%固定

        DBに値はあるが stale の場合:
            50%固定

        fresh data がある場合:
            threshold 以上なら活動あり
            静寂時間に応じて段階的に減光
        """
        if not has_any_data:
            return ACTIVE_BRIGHTNESS

        if not has_fresh_data:
            return ACTIVE_BRIGHTNESS

        if level >= noise_threshold:
            self.mark_activity()

        elapsed = time.time() - self.last_active_time

        if elapsed < DIM_AFTER_SEC_1:
            return ACTIVE_BRIGHTNESS
        elif elapsed < DIM_AFTER_SEC_2:
            return DIM_BRIGHTNESS_1
        elif elapsed < OFF_AFTER_SEC:
            return DIM_BRIGHTNESS_2
        else:
            return OFF_BRIGHTNESS

    def get_elapsed_sec(self):
        return int(time.time() - self.last_active_time)


# =========================================================
# メイン
# =========================================================

def main():
    setup_logging()

    signal.signal(signal.SIGINT, signal_handler)
    signal.signal(signal.SIGTERM, signal_handler)

    notifier = SystemdNotifier()

    logging.info("display_brightness_control started")
    logging.info(
        "db_host=%s db_name=%s",
        config.DB_CONFIG.get("host"),
        config.DB_CONFIG.get("database"),
    )
    logging.info(
        "config: active=%s%% dim1=%s%% dim2=%s%% off=%s%% "
        "t1=%ss t2=%ss off=%ss poll=%ss freshness=%ss sound_cache=%ss value_type=%s "
        "lookback=%sh percentile=%.1f floor=%.1f margin=%.1f threshold_refresh=%ss",
        ACTIVE_BRIGHTNESS,
        DIM_BRIGHTNESS_1,
        DIM_BRIGHTNESS_2,
        OFF_BRIGHTNESS,
        DIM_AFTER_SEC_1,
        DIM_AFTER_SEC_2,
        OFF_AFTER_SEC,
        POLL_INTERVAL_SEC,
        DEVICE_FRESHNESS_SEC,
        CURRENT_SOUND_CACHE_SEC,
        TARGET_VALUE_TYPE,
        THRESHOLD_LOOKBACK_HOURS,
        QUIET_PERCENTILE,
        LEVEL_FLOOR_FOR_THRESHOLD,
        THRESHOLD_MARGIN,
        THRESHOLD_REFRESH_SEC,
    )

    display = DisplayBrightness(BACKLIGHT_PATH)
    controller = BrightnessController()
    threshold_manager = DynamicThresholdManager()
    sound_cache = CurrentSoundCache()
    conn = None

    display.set_percent(ACTIVE_BRIGHTNESS)

    global _running
    first_ready_sent = False

    while _running:
        try:
            if conn is None:
                conn = connect_db()
                logging.info("DB connected")
                threshold_manager.refresh_if_needed(conn, force=True)
                sound_cache.refresh_if_needed(conn, force=True)

            threshold_manager.refresh_if_needed(conn)
            sound_cache.refresh_if_needed(conn)

            level, has_any_data, has_fresh_data, newest_age_sec = sound_cache.get()
            noise_threshold = threshold_manager.current_threshold

            brightness = controller.update(
                level=level,
                has_any_data=has_any_data,
                has_fresh_data=has_fresh_data,
                noise_threshold=noise_threshold,
            )
            elapsed = controller.get_elapsed_sec()

            display.set_percent(brightness)

            logging.info(
                "[monitor] level=%.1f has_any_data=%s has_fresh_data=%s newest_age_sec=%s "
                "quiet_level=%s threshold=%.2f elapsed=%ss brightness=%s%%",
                level,
                has_any_data,
                has_fresh_data,
                newest_age_sec,
                f"{threshold_manager.last_quiet_level:.2f}" if threshold_manager.last_quiet_level is not None else "None",
                noise_threshold,
                elapsed,
                brightness,
            )

            if not first_ready_sent:
                notifier.ready()
                first_ready_sent = True

            notifier.status(
                f"level={level:.1f}, threshold={noise_threshold:.2f}, brightness={brightness}%, elapsed={elapsed}s"
            )
            notifier.watchdog()

            time.sleep(POLL_INTERVAL_SEC)

        except mysql.connector.Error as e:
            logging.error("DB error: %s", e)
            notifier.status(f"DB error: {e}")

            if conn:
                try:
                    conn.close()
                except Exception:
                    pass
            conn = None

            display.set_percent(ACTIVE_BRIGHTNESS)
            time.sleep(2)

        except Exception as e:
            logging.exception("unexpected error: %s", e)
            notifier.status(f"unexpected error: {e}")

            if conn:
                try:
                    conn.close()
                except Exception:
                    pass
            conn = None

            display.set_percent(ACTIVE_BRIGHTNESS)
            time.sleep(2)

    if conn:
        try:
            conn.close()
        except Exception:
            pass

    logging.info("display_brightness_control stopped")
    return 0


if __name__ == "__main__":
    sys.exit(main())
