*************************** 1. row ***************************
       Table: sensor_device
Create Table: CREATE TABLE `sensor_device` (
  `device_id` int(11) NOT NULL AUTO_INCREMENT,
  `device_bt_addr` text NOT NULL COMMENT 'デバイスのBT ADDR',
  `device_bt_name` text NOT NULL COMMENT 'デバイス名（bluetooth）',
  `device_memo_name` text NOT NULL COMMENT 'デバイス名（メモ）',
  `unit_name` text NOT NULL COMMENT '単位',
  `scale_factor` int(11) NOT NULL COMMENT '倍数',
  `display_order` int(11) NOT NULL COMMENT '表示順序',
  PRIMARY KEY (`device_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
