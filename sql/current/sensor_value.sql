*************************** 1. row ***************************
       Table: sensor_value
Create Table: CREATE TABLE `sensor_value` (
  `sensor_value_id` bigint(20) NOT NULL AUTO_INCREMENT COMMENT 'センサー値の通し番号',
  `device_id` int(11) NOT NULL COMMENT 'デバイスID',
  `measured_datetime` datetime NOT NULL COMMENT '計測日時',
  `recorded_datetime` datetime NOT NULL COMMENT '記録日時',
  `value_type` text NOT NULL COMMENT '値の種類',
  `measured_value` text NOT NULL COMMENT '計測値（生値）',
  PRIMARY KEY (`sensor_value_id`),
  KEY `idx_sv_device_time` (`device_id`,`measured_datetime`,`sensor_value_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5646768 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
