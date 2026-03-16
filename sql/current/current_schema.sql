/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-11.8.3-MariaDB, for debian-linux-gnu (aarch64)
--
-- Host: localhost    Database: sound_monitor
-- ------------------------------------------------------
-- Server version	11.8.3-MariaDB-0+deb13u1 from Debian

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--
-- Table structure for table `detected_sensor_device`
--

DROP TABLE IF EXISTS `detected_sensor_device`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `detected_sensor_device` (
  `device_bt_addr` text NOT NULL,
  `device_bt_name` text DEFAULT NULL,
  `detected_first_time` datetime NOT NULL,
  `is_ignored` tinyint(1) NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_detected_sensor_device_addr` (`device_bt_addr`(17))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sensor_device`
--

DROP TABLE IF EXISTS `sensor_device`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sensor_device` (
  `device_id` int(11) NOT NULL AUTO_INCREMENT,
  `device_bt_addr` text NOT NULL COMMENT 'デバイスのBT ADDR',
  `device_bt_name` text NOT NULL COMMENT 'デバイス名（bluetooth）',
  `device_memo_name` text NOT NULL COMMENT 'デバイス名（メモ）',
  `unit_name` text NOT NULL COMMENT '単位',
  `scale_factor` int(11) NOT NULL COMMENT '倍数',
  `display_order` int(11) NOT NULL COMMENT '表示順序',
  PRIMARY KEY (`device_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sensor_value`
--

DROP TABLE IF EXISTS `sensor_value`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sensor_value` (
  `sensor_value_id` bigint(20) NOT NULL AUTO_INCREMENT COMMENT 'センサー値の通し番号',
  `device_id` int(11) NOT NULL COMMENT 'デバイスID',
  `measured_datetime` datetime NOT NULL COMMENT '計測日時',
  `recorded_datetime` datetime NOT NULL COMMENT '記録日時',
  `value_type` text NOT NULL COMMENT '値の種類',
  `measured_value` text NOT NULL COMMENT '計測値（生値）',
  PRIMARY KEY (`sensor_value_id`),
  KEY `idx_sv_device_time` (`device_id`,`measured_datetime`,`sensor_value_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5646246 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `setting_info`
--

DROP TABLE IF EXISTS `setting_info`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `setting_info` (
  `setting_name` varchar(190) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  PRIMARY KEY (`setting_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary table structure for view `view_most_recent_value`
--

DROP TABLE IF EXISTS `view_most_recent_value`;
/*!50001 DROP VIEW IF EXISTS `view_most_recent_value`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `view_most_recent_value` AS SELECT
 1 AS `device_id`,
  1 AS `device_memo_name`,
  1 AS `display_order`,
  1 AS `max_recorded_datetime`,
  1 AS `sensor_value_id`,
  1 AS `measured_value` */;
SET character_set_client = @saved_cs_client;

--
-- Final view structure for view `view_most_recent_value`
--

/*!50001 DROP VIEW IF EXISTS `view_most_recent_value`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`sound-monitor-pi`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `view_most_recent_value` AS select `D`.`device_id` AS `device_id`,`D`.`device_memo_name` AS `device_memo_name`,`D`.`display_order` AS `display_order`,`B`.`max_recorded_datetime` AS `max_recorded_datetime`,max(`A`.`sensor_value_id`) AS `sensor_value_id`,max(`A`.`measured_value`) AS `measured_value` from ((`sensor_device` `D` join (select `C`.`device_id` AS `device_id`,max(`C`.`recorded_datetime`) AS `max_recorded_datetime` from `sensor_value` `C` where `C`.`value_type` = 'level' group by `C`.`device_id`) `B` on(`B`.`device_id` = `D`.`device_id`)) left join `sensor_value` `A` on(`A`.`device_id` = `B`.`device_id` and `A`.`recorded_datetime` between `B`.`max_recorded_datetime` - interval 5 second and `B`.`max_recorded_datetime` and `A`.`value_type` = 'level')) group by `D`.`device_id`,`D`.`device_memo_name`,`D`.`display_order`,`B`.`max_recorded_datetime` order by `D`.`display_order`,`D`.`device_id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-03-17  1:27:31
