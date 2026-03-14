# gateway/common/db.py

import mysql.connector
from common.config import DB_CONFIG


def get_db_connection():
    conn = mysql.connector.connect(**DB_CONFIG)
    conn.autocommit = True
    return conn