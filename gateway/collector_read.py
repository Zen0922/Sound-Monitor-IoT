#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import asyncio
import struct
import time
import subprocess
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path
from typing import Dict, Optional

from bleak import BleakClient, BleakScanner
from bleak.exc import BleakError

# ---------------------------------------------------------
# common/ を使えるようにする
# このスクリプトがプロジェクト直下にあり、common/ が同階層にある想定
# ---------------------------------------------------------
import sys

CURRENT_DIR = Path(__file__).resolve().parent
if str(CURRENT_DIR) not in sys.path:
    sys.path.insert(0, str(CURRENT_DIR))

from common import config
from common.db import get_db_connection

# =========================================================
# 設定読込
# =========================================================

BLE_CONFIG = config.BLE
COLLECTOR_CONFIG = config.COLLECTOR

SOUND_LEVEL_UUID = BLE_CONFIG["sound_level_uuid"]
BASELINE_UUID = BLE_CONFIG["baseline_uuid"]

CONNECT_TIMEOUT_SEC = COLLECTOR_CONFIG["connect_timeout_sec"]
RECONNECT_WAIT_SEC = COLLECTOR_CONFIG["reconnect_wait_sec"]
SAVE_INTERVAL_SEC = COLLECTOR_CONFIG["save_interval_sec"]
SAVE_MODE = COLLECTOR_CONFIG["save_mode"]

AUX1_UUID = "c0de0004-3b3a-4f2c-9a3a-1b2c3d4e5001"
AUX2_UUID = "c0de0005-3b3a-4f2c-9a3a-1b2c3d4e5001"

SQL_GET_DEVICES = """
SELECT device_id, device_bt_addr, device_bt_name, scale_factor
FROM sensor_device
ORDER BY device_id
"""

SQL_UPDATE_ADDR = """
UPDATE sensor_device
SET device_bt_addr = %s
WHERE device_id = %s
"""

SQL_INSERT_VALUE = """
INSERT INTO sensor_value
(device_id, measured_datetime, recorded_datetime, value_type, measured_value)
VALUES (%s, %s, %s, %s, %s)
"""

SCAN_ADDR_TIMEOUT_SEC = 6.0
SCAN_NAME_TIMEOUT_SEC = 8.0
OFFLINE_BACKOFF_MIN_SEC = 5
OFFLINE_BACKOFF_MAX_SEC = 120
QUEUE_MAXSIZE = 4000
CONNECT_LOCK = asyncio.Lock()
DEVICE_REFRESH_SEC = 30.0
STARTUP_CLEANUP_WAIT_SEC = 3.0


def now():
    return datetime.now()


def parse_u16(payload: bytes) -> int:
    if len(payload) < 2:
        raise ValueError(f"payload too short: {payload!r}")
    return struct.unpack_from("<H", payload, 0)[0]


def format_level_value(rel10: int, scale_factor: int) -> str:
    if SAVE_MODE == "raw":
        return str(rel10)

    sf = int(scale_factor)
    if sf == 0:
        return str(rel10)

    return str(rel10 / sf)


def format_baseline_value(raw_u16: int) -> str:
    return str(raw_u16 / 10.0)


def format_passthrough(raw_u16: int) -> str:
    return str(raw_u16)


@dataclass
class DeviceRow:
    device_id: int
    addr: str
    name: str
    scale_factor: int


@dataclass
class DevState:
    offline_backoff: float = OFFLINE_BACKOFF_MIN_SEC
    next_try_monotonic: float = 0.0
    next_save_monotonic: dict = field(default_factory=lambda: {
        "level": 0.0,
        "baseline": 0.0,
        "aux1": 0.0,
        "aux2": 0.0,
    })


class DB:
    def __init__(self):
        self.conn = get_db_connection()

    def close(self):
        try:
            self.conn.close()
        except Exception:
            pass

    def load_devices(self) -> list[DeviceRow]:
        with self.conn.cursor() as cur:
            cur.execute(SQL_GET_DEVICES)
            rows = cur.fetchall()

        return [
            DeviceRow(
                int(did),
                str(addr or ""),
                str(name or ""),
                int(sf or 1),
            )
            for did, addr, name, sf in rows
        ]

    def update_addr(self, device_id: int, addr: str):
        with self.conn.cursor() as cur:
            cur.execute(SQL_UPDATE_ADDR, (addr, device_id))

    def insert_value(self, device_id: int, t: datetime, value_type: str, value_text: str):
        with self.conn.cursor() as cur:
            cur.execute(SQL_INSERT_VALUE, (device_id, t, t, value_type, value_text))


async def find_device(addr: str, name: str):
    addr = (addr or "").strip()
    name = (name or "").strip()

    if addr:
        d = await BleakScanner.find_device_by_address(addr, timeout=SCAN_ADDR_TIMEOUT_SEC)
        if d:
            return d

    if name:
        def match_name(device, adv):
            n = device.name or adv.local_name or ""
            return n == name

        d = await BleakScanner.find_device_by_filter(match_name, timeout=SCAN_NAME_TIMEOUT_SEC)
        if d:
            return d

    return None


def should_try(st: DevState) -> bool:
    return time.monotonic() >= st.next_try_monotonic


def schedule_try(st: DevState, sec: float):
    st.next_try_monotonic = time.monotonic() + sec


def should_save(st: DevState, value_type: str) -> bool:
    if SAVE_INTERVAL_SEC <= 0:
        return True
    return time.monotonic() >= st.next_save_monotonic[value_type]


def schedule_next_save(st: DevState, value_type: str):
    if SAVE_INTERVAL_SEC <= 0:
        st.next_save_monotonic[value_type] = time.monotonic()
    else:
        st.next_save_monotonic[value_type] = time.monotonic() + float(SAVE_INTERVAL_SEC)


def enqueue_value(out_q: asyncio.Queue, device_id: int, t: datetime, value_type: str, value_text: str):
    try:
        out_q.put_nowait((device_id, t, value_type, value_text))
    except asyncio.QueueFull:
        print(f"[QUEUE-WARN] full. dropped id={device_id} type={value_type} value={value_text}")


def dump_services(client: BleakClient, device_id: int):
    try:
        services = client.services
        for service in services:
            print(f"[SERVICE] id={device_id} uuid={service.uuid}")
            for char in service.characteristics:
                props = ",".join(getattr(char, "properties", [])) if getattr(char, "properties", None) else "?"
                print(f"  [CHAR] uuid={char.uuid} props={props}")
    except Exception as e:
        print(f"[SERVICE-WARN] id={device_id} {type(e).__name__}: {e}")


def make_handler(loop, out_q, dev, st, value_type):
    def _handler(_, data: bytearray):
        try:
            raw_u16 = parse_u16(bytes(data))

            if value_type == "level":
                value_text = format_level_value(raw_u16, dev.scale_factor)
            elif value_type == "baseline":
                value_text = format_baseline_value(raw_u16)
            else:
                value_text = format_passthrough(raw_u16)

            if should_save(st, value_type):
                schedule_next_save(st, value_type)
                loop.call_soon_threadsafe(
                    enqueue_value,
                    out_q,
                    dev.device_id,
                    now(),
                    value_type,
                    value_text,
                )
                print(f"[NOTIFY] id={dev.device_id} type={value_type} value={value_text}")

        except Exception as e:
            print(f"[NOTIFY-WARN] id={dev.device_id} type={value_type} {type(e).__name__}: {e}")

    return _handler


async def remove_bluez_cache(addr: str):
    addr = (addr or "").strip()
    if not addr:
        return

    def _run():
        return subprocess.run(
            ["bluetoothctl", "remove", addr],
            capture_output=True,
            text=True,
            timeout=15,
        )

    try:
        proc = await asyncio.to_thread(_run)
        stdout = (proc.stdout or "").strip()
        stderr = (proc.stderr or "").strip()

        if proc.returncode == 0:
            print(f"[BT-REMOVE] addr={addr} ok {stdout}")
        else:
            print(f"[BT-REMOVE-WARN] addr={addr} rc={proc.returncode} stdout={stdout} stderr={stderr}")

    except Exception as e:
        print(f"[BT-REMOVE-WARN] addr={addr} {type(e).__name__}: {e}")


async def startup_cleanup_known_devices(rows: list[DeviceRow]):
    seen = set()

    for row in rows:
        addr = (row.addr or "").strip()
        if not addr or addr in seen:
            continue

        seen.add(addr)
        print(f"[STARTUP-CLEANUP] id={row.device_id} name={row.name} addr={addr}")
        await remove_bluez_cache(addr)


class ManagedDevice:
    def __init__(self, row: DeviceRow):
        self.row = row
        self.state = DevState()
        self.stop_event = asyncio.Event()
        self.task: Optional[asyncio.Task] = None
        self.client: Optional[BleakClient] = None


async def device_worker(db: DB, managed: ManagedDevice, out_q: asyncio.Queue):
    dev = managed.row
    st = managed.state

    while not managed.stop_event.is_set():
        if not should_try(st):
            await asyncio.sleep(0.2)
            continue

        try:
            async with CONNECT_LOCK:
                d = await find_device(dev.addr, dev.name)

            if not d:
                wait = st.offline_backoff
                st.offline_backoff = min(st.offline_backoff * 2, OFFLINE_BACKOFF_MAX_SEC)
                schedule_try(st, wait)
                print(f"[OFFLINE] id={dev.device_id} name={dev.name} retry_in={int(wait)}s")
                continue

            db.update_addr(dev.device_id, d.address)
            dev.addr = d.address

            async with CONNECT_LOCK:
                client = BleakClient(d, timeout=CONNECT_TIMEOUT_SEC)
                managed.client = client
                await client.connect()

            print(f"[CONNECTED] id={dev.device_id} name={dev.name} addr={dev.addr}")

            st.offline_backoff = OFFLINE_BACKOFF_MIN_SEC
            schedule_try(st, 0.0)

            for key in ("level", "baseline", "aux1", "aux2"):
                schedule_next_save(st, key)

            dump_services(client, dev.device_id)

            services = client.services
            chars = {
                "level": services.get_characteristic(SOUND_LEVEL_UUID),
                "baseline": services.get_characteristic(BASELINE_UUID),
                "aux1": services.get_characteristic(AUX1_UUID),
                "aux2": services.get_characteristic(AUX2_UUID),
            }

            for key, char in chars.items():
                print(f"[DEBUG] id={dev.device_id} {key}_char={char}")

            if chars["level"] is None:
                raise BleakError(f"level characteristic not found: {SOUND_LEVEL_UUID}")

            loop = asyncio.get_running_loop()
            handlers = {k: make_handler(loop, out_q, dev, st, k) for k in chars}
            started = []

            try:
                await client.start_notify(chars["level"], handlers["level"])
                started.append(chars["level"])

                for key in ("baseline", "aux1", "aux2"):
                    if chars[key] is not None:
                        await client.start_notify(chars[key], handlers[key])
                        started.append(chars[key])

                active = [k for k, v in chars.items() if v is not None]
                print(f"[SUBSCRIBED] id={dev.device_id} active={','.join(active)}")

                while client.is_connected and not managed.stop_event.is_set():
                    await asyncio.sleep(1.0)

            finally:
                for char in started:
                    try:
                        await client.stop_notify(char)
                    except Exception:
                        pass

                try:
                    if client.is_connected:
                        await client.disconnect()
                except Exception:
                    pass

                managed.client = None
                print(f"[DISCONNECTED] id={dev.device_id} name={dev.name}")

        except asyncio.CancelledError:
            raise

        except (EOFError, BleakError, asyncio.TimeoutError, OSError) as e:
            wait = max(float(RECONNECT_WAIT_SEC), st.offline_backoff)
            st.offline_backoff = min(st.offline_backoff * 2, OFFLINE_BACKOFF_MAX_SEC)
            schedule_try(st, wait)
            managed.client = None
            print(f"[WARN] id={dev.device_id} {type(e).__name__}: {e} -> retry_in={int(wait)}s")

        except Exception as e:
            wait = max(float(RECONNECT_WAIT_SEC), st.offline_backoff)
            st.offline_backoff = min(st.offline_backoff * 2, OFFLINE_BACKOFF_MAX_SEC)
            schedule_try(st, wait)
            managed.client = None
            print(f"[ERR] id={dev.device_id} {type(e).__name__}: {e} -> retry_in={int(wait)}s")

    print(f"[STOPPED] id={dev.device_id} name={dev.name}")


async def stop_managed_device(managed: ManagedDevice):
    managed.stop_event.set()

    client = managed.client
    if client is not None:
        try:
            if client.is_connected:
                await client.disconnect()
        except Exception:
            pass

    if managed.task is not None:
        try:
            await asyncio.wait_for(managed.task, timeout=10.0)
        except asyncio.TimeoutError:
            managed.task.cancel()
            try:
                await managed.task
            except Exception:
                pass


async def db_writer(db: DB, in_q: asyncio.Queue):
    while True:
        device_id, t, value_type, value_text = await in_q.get()

        try:
            db.insert_value(int(device_id), t, str(value_type), str(value_text))
        except Exception as e:
            print(f"[DB-WARN] id={device_id} type={value_type} {type(e).__name__}: {e} (requeue)")
            await asyncio.sleep(1.0)
            await in_q.put((device_id, t, value_type, value_text))
        finally:
            in_q.task_done()


async def refresh_device_registry(db: DB, out_q: asyncio.Queue, managed_map: Dict[int, ManagedDevice]):
    while True:
        try:
            fresh_rows = db.load_devices()
            fresh_map = {row.device_id: row for row in fresh_rows}
            current_ids = set(managed_map.keys())
            fresh_ids = set(fresh_map.keys())

            removed_ids = sorted(current_ids - fresh_ids)
            added_ids = sorted(fresh_ids - current_ids)
            common_ids = sorted(current_ids & fresh_ids)

            for device_id in removed_ids:
                managed = managed_map.pop(device_id)
                old_addr = managed.row.addr
                old_name = managed.row.name
                print(f"[DEVICE-REMOVED] id={device_id} name={old_name} addr={old_addr}")
                await stop_managed_device(managed)
                await remove_bluez_cache(old_addr)

            for device_id in common_ids:
                managed = managed_map[device_id]
                fresh = fresh_map[device_id]

                row_changed = (
                    managed.row.addr != fresh.addr
                    or managed.row.name != fresh.name
                    or managed.row.scale_factor != fresh.scale_factor
                )

                if row_changed:
                    old_addr = managed.row.addr
                    print(
                        f"[DEVICE-CHANGED] id={device_id} "
                        f"name:{managed.row.name}->{fresh.name} "
                        f"addr:{managed.row.addr}->{fresh.addr} "
                        f"scale:{managed.row.scale_factor}->{fresh.scale_factor}"
                    )
                    await stop_managed_device(managed)

                    if old_addr and old_addr != fresh.addr:
                        await remove_bluez_cache(old_addr)

                    new_managed = ManagedDevice(fresh)
                    new_managed.task = asyncio.create_task(device_worker(db, new_managed, out_q))
                    managed_map[device_id] = new_managed

            for device_id in added_ids:
                fresh = fresh_map[device_id]
                print(f"[DEVICE-ADDED] id={device_id} name={fresh.name} addr={fresh.addr}")
                managed = ManagedDevice(fresh)
                managed.task = asyncio.create_task(device_worker(db, managed, out_q))
                managed_map[device_id] = managed

        except Exception as e:
            print(f"[REFRESH-WARN] {type(e).__name__}: {e}")

        await asyncio.sleep(DEVICE_REFRESH_SEC)


async def main():
    db = DB()
    q: asyncio.Queue = asyncio.Queue(maxsize=QUEUE_MAXSIZE)
    managed_map: Dict[int, ManagedDevice] = {}

    try:
        initial = db.load_devices()

        if not initial:
            print("[INFO] sensor_device が空です。登録後に起動してください。")
        else:
            print(f"[STARTUP] cleanup target devices={len(initial)}")
            await startup_cleanup_known_devices(initial)
            print(f"[STARTUP] waiting {STARTUP_CLEANUP_WAIT_SEC:.1f}s before scan/connect")
            await asyncio.sleep(STARTUP_CLEANUP_WAIT_SEC)

        writer_task = asyncio.create_task(db_writer(db, q))

        for row in initial:
            managed = ManagedDevice(row)
            managed.task = asyncio.create_task(device_worker(db, managed, q))
            managed_map[row.device_id] = managed

        refresh_task = asyncio.create_task(refresh_device_registry(db, q, managed_map))
        await asyncio.gather(writer_task, refresh_task)

    finally:
        for managed in list(managed_map.values()):
            try:
                await stop_managed_device(managed)
            except Exception:
                pass

        db.close()


if __name__ == "__main__":
    asyncio.run(main())