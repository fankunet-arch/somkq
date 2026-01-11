-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- 主机： mhdlmskp2kpxguj.mysql.db
-- 生成日期： 2026-01-12 00:01:00
-- 服务器版本： 8.4.6-6
-- PHP 版本： 8.1.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `mhdlmskp2kpxguj`
--
CREATE DATABASE IF NOT EXISTS `mhdlmskp2kpxguj` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE `mhdlmskp2kpxguj`;

-- --------------------------------------------------------

--
-- 表的结构 `somkq_daily_calibration`
--

DROP TABLE IF EXISTS `somkq_daily_calibration`;
CREATE TABLE `somkq_daily_calibration` (
  `cal_date` date NOT NULL COMMENT '日期，主键 YYYY-MM-DD',
  `monitor_time_ref` time DEFAULT NULL COMMENT '基准监控时间',
  `real_time_ref` time DEFAULT NULL COMMENT '基准实际时间',
  `time_offset_seconds` int DEFAULT '0' COMMENT '误差秒数 (实际 - 监控)',
  `calibration_image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '校准图片文件名',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='每日时间校准表';

-- --------------------------------------------------------

--
-- 表的结构 `somkq_shift_records`
--

DROP TABLE IF EXISTS `somkq_shift_records`;
CREATE TABLE `somkq_shift_records` (
  `id` int UNSIGNED NOT NULL,
  `record_date` date NOT NULL COMMENT '关联日期',
  `staff_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '员工姓名 (快照)',
  `shift_type` enum('am','pm') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '班次：am上午, pm下午',
  `start_time_monitor` time DEFAULT NULL COMMENT '上班时间 (监控时间)',
  `end_time_monitor` time DEFAULT NULL COMMENT '下班时间 (监控时间)',
  `is_end_at_closing` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=至营业结束',
  `is_absent` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=未在岗位出现过',
  `special_tag` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '特殊标记（如：补货、加班等）',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='员工班次记录表';

-- --------------------------------------------------------

--
-- 表的结构 `somkq_shift_videos`
--

DROP TABLE IF EXISTS `somkq_shift_videos`;
CREATE TABLE `somkq_shift_videos` (
  `id` int UNSIGNED NOT NULL,
  `record_id` int UNSIGNED NOT NULL COMMENT '关联班次记录ID',
  `timing_type` enum('start','end') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'start' COMMENT '时机: start上班, end下班',
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '存储在磁盘的物理文件名',
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '原始文件名',
  `file_size` bigint DEFAULT '0' COMMENT '文件大小(字节)',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='班次视频附件表';

--
-- 转储表的索引
--

--
-- 表的索引 `somkq_daily_calibration`
--
ALTER TABLE `somkq_daily_calibration`
  ADD PRIMARY KEY (`cal_date`);

--
-- 表的索引 `somkq_shift_records`
--
ALTER TABLE `somkq_shift_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_date_staff` (`record_date`,`staff_name`);

--
-- 表的索引 `somkq_shift_videos`
--
ALTER TABLE `somkq_shift_videos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_record_id` (`record_id`),
  ADD KEY `idx_record_timing` (`record_id`,`timing_type`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `somkq_shift_records`
--
ALTER TABLE `somkq_shift_records`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `somkq_shift_videos`
--
ALTER TABLE `somkq_shift_videos`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 限制导出的表
--

--
-- 限制表 `somkq_shift_videos`
--
ALTER TABLE `somkq_shift_videos`
  ADD CONSTRAINT `fk_video_record` FOREIGN KEY (`record_id`) REFERENCES `somkq_shift_records` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
