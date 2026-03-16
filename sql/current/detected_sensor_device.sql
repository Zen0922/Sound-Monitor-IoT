*************************** 1. row ***************************
       Table: detected_sensor_device
Create Table: CREATE TABLE `detected_sensor_device` (
  `device_bt_addr` text NOT NULL,
  `device_bt_name` text DEFAULT NULL,
  `detected_first_time` datetime NOT NULL,
  `is_ignored` tinyint(1) NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_detected_sensor_device_addr` (`device_bt_addr`(17))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
