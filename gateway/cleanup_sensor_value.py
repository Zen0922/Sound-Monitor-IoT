import mysql.connector
from datetime import datetime

from common.config import DB_CONFIG, MAINTENANCE


SQL_COUNT_ALL = """
SELECT COUNT(*)
FROM sound_monitor.sensor_value;
"""

SQL_DELETE_UNCHANGED = """
DELETE sv
FROM sound_monitor.sensor_value sv
JOIN (
  SELECT sensor_value_id
  FROM (
    SELECT
      sensor_value_id,
      device_id,
      measured_datetime,
      measured_value,
      LAG(measured_value) OVER (
        PARTITION BY device_id
        ORDER BY measured_datetime, sensor_value_id
      ) AS prev_value
    FROM sound_monitor.sensor_value
  ) t
  WHERE prev_value IS NOT NULL
    AND measured_value = prev_value
) d ON d.sensor_value_id = sv.sensor_value_id;
"""

SQL_DELETE_OLD = """
DELETE
FROM sound_monitor.sensor_value
WHERE measured_datetime < (NOW() - INTERVAL %s DAY);
"""


def log(message: str) -> None:
    now_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    print(f"[{now_str}] {message}")


def get_total_count(cur) -> int:
    cur.execute(SQL_COUNT_ALL)
    row = cur.fetchone()
    return int(row[0]) if row else 0


def main():
    retention_days = int(MAINTENANCE["data_retention_days"])
    if retention_days < 1:
        raise ValueError("MAINTENANCE['data_retention_days'] must be 1 or greater")

    conn = mysql.connector.connect(**DB_CONFIG)
    conn.autocommit = True
    cur = conn.cursor()

    try:
        log("=== cleanup start ===")
        log(f"retention_days = {retention_days}")

        total_before = get_total_count(cur)
        log(f"total_rows_before = {total_before}")

        cur.execute(SQL_DELETE_UNCHANGED)
        deleted_unchanged = cur.rowcount
        log(f"deleted_unchanged_rows = {deleted_unchanged}")

        cur.execute(SQL_DELETE_OLD, (retention_days,))
        deleted_old = cur.rowcount
        log(f"deleted_old_rows = {deleted_old}")

        total_after = get_total_count(cur)
        log(f"total_rows_after = {total_after}")

        deleted_total = total_before - total_after
        log(f"deleted_total_rows = {deleted_total}")

        log("=== cleanup finished ===")

    finally:
        cur.close()
        conn.close()


if __name__ == "__main__":
    main()