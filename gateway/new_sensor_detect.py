import asyncio
from datetime import datetime

from bleak import BleakScanner

from common.config import BLE, DETECTOR
from common.db import get_db_connection

SQL_LOAD_KNOWN = "SELECT device_bt_addr FROM detected_sensor_device"

SQL_INSERT_IGNORE = """
INSERT IGNORE INTO detected_sensor_device
(device_bt_addr, device_bt_name, detected_first_time)
VALUES (%s, %s, %s)
"""


def now_local():
    return datetime.now()


class Detector:
    def __init__(self):
        self.conn = get_db_connection()
        self.known_addrs: set[str] = set()
        self._load_known()

    def close(self):
        try:
            self.conn.close()
        except Exception:
            pass

    def _load_known(self):
        with self.conn.cursor() as cur:
            cur.execute(SQL_LOAD_KNOWN)
            for (addr,) in cur.fetchall():
                if addr:
                    self.known_addrs.add(addr)
        print(f"[init] known_addrs={len(self.known_addrs)}")

    def insert_first_time_only(self, addr: str, name: str):
        if addr in self.known_addrs:
            return False

        with self.conn.cursor() as cur:
            cur.execute(SQL_INSERT_IGNORE, (addr, name, now_local()))
            inserted = (cur.rowcount == 1)

        if inserted:
            self.known_addrs.add(addr)

        return inserted

    async def scan_once(self):
        found_latest: dict[str, str] = {}

        def cb(device, adv):
            name = device.name or adv.local_name or ""
            if not name.startswith(BLE["scan_prefix"]):
                return
            found_latest[device.address] = name

        scanner = BleakScanner(detection_callback=cb)
        await scanner.start()
        try:
            await asyncio.sleep(DETECTOR["scan_timeout_sec"])
        finally:
            await scanner.stop()

        inserted_count = 0
        for addr, name in found_latest.items():
            if self.insert_first_time_only(addr, name):
                inserted_count += 1
                print(f"[NEW] {addr} / {name}")
            else:
                print(f"[SKIP] {addr} / {name}")

        print(f"[scan] matched={len(found_latest)} new_inserted={inserted_count}")

    async def run_forever(self):
        while True:
            try:
                await self.scan_once()
            except Exception as e:
                print(f"[error] {type(e).__name__}: {e}")

            await asyncio.sleep(DETECTOR["scan_interval_sec"])


async def main():
    det = Detector()
    try:
        await det.run_forever()
    finally:
        det.close()


if __name__ == "__main__":
    asyncio.run(main())