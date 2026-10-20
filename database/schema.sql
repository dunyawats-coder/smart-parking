-- Smart Parking - โครงสร้างฐานข้อมูล (schema only, ไม่มีข้อมูลจริงของใคร)
-- ใช้ได้กับ MySQL 8 / MariaDB 10.4+   นำเข้า: mysql -u root -p < database/schema.sql
CREATE DATABASE IF NOT EXISTS `smartparking` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `smartparking`;
SET NAMES utf8mb4;
SET time_zone = "+00:00";

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `camera_logs`
--

CREATE TABLE `camera_logs` (
  `id` int(11) NOT NULL,
  `slot_id` int(11) NOT NULL,
  `camera_id` varchar(50) NOT NULL,
  `event` enum('vehicle_enter','vehicle_exit','manual_capture') NOT NULL,
  `image_filename` varchar(255) NOT NULL,
  `image_path` varchar(500) NOT NULL,
  `captured_at` datetime DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Table structure for table `parking_alerts`
--

CREATE TABLE `parking_alerts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `alert_type` varchar(50) NOT NULL,
  `severity` enum('info','warning','critical') NOT NULL DEFAULT 'info',
  `message` varchar(500) NOT NULL,
  `slot_id` int(11) DEFAULT NULL,
  `dedupe_key` varchar(190) DEFAULT NULL COMMENT 'Used by application to prevent duplicate alerts',
  `status` enum('unread','read','resolved') NOT NULL DEFAULT 'unread',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `acknowledged_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `parking_hourly_stats`
--

CREATE TABLE `parking_hourly_stats` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `hour_start` datetime NOT NULL COMMENT 'Start of local hour, e.g. 2026-10-20 09:00:00',
  `entry_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `exit_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `slot_usage_json` longtext DEFAULT NULL COMMENT 'Optional JSON object: slot number -> entry count',
  `generated_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Table structure for table `parking_predictions`
--

CREATE TABLE `parking_predictions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `target_hour` datetime NOT NULL COMMENT 'Hour being forecast, rounded down to the hour',
  `predicted_entries` decimal(8,2) NOT NULL DEFAULT 0.00,
  `predicted_exits` decimal(8,2) NOT NULL DEFAULT 0.00,
  `method` varchar(60) NOT NULL DEFAULT 'same_weekday_hour_average',
  `sample_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `confidence` enum('insufficient_data','low','medium','high') NOT NULL DEFAULT 'insufficient_data',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Table structure for table `parking_sessions`
--

CREATE TABLE `parking_sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `slot_id` int(11) NOT NULL,
  `vehicle_plate` varchar(30) DEFAULT NULL,
  `checkin_time` datetime NOT NULL,
  `checkout_time` datetime DEFAULT NULL,
  `duration_minutes` int(11) NOT NULL DEFAULT 0,
  `fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('parking','completed') NOT NULL DEFAULT 'parking',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `camera_captured` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Table structure for table `parking_slots`
--

CREATE TABLE `parking_slots` (
  `id` int(11) NOT NULL,
  `slot_number` int(11) NOT NULL,
  `status` enum('available','reserved','occupied') NOT NULL DEFAULT 'available',
  `distance_cm` decimal(6,2) DEFAULT NULL,
  `camera_id` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('promptpay','cash','other') NOT NULL DEFAULT 'promptpay',
  `payment_status` enum('pending','paid','failed') NOT NULL DEFAULT 'pending',
  `qr_reference` varchar(100) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Table structure for table `payment_qr`
--

CREATE TABLE `payment_qr` (
  `id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `qr_reference` varchar(100) NOT NULL,
  `promptpay_id` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `qr_image_path` varchar(500) DEFAULT NULL,
  `qr_status` enum('generated','pending','paid','expired','cancelled') NOT NULL DEFAULT 'generated',
  `created_at` datetime DEFAULT current_timestamp(),
  `expired_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Table structure for table `payment_slips`
--

CREATE TABLE `payment_slips` (
  `id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `slip_filename` varchar(255) NOT NULL,
  `slip_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `STATUS` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `slot_id` int(11) NOT NULL,
  `reserve_date` date NOT NULL,
  `reserve_time` time NOT NULL,
  `STATUS` enum('pending','reserved','completed','cancelled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `slip_image` varchar(255) DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fullname` varchar(100) DEFAULT NULL,
  `role` varchar(20) DEFAULT 'user'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- Stand-in structure for view `v_parking_hourly_traffic`
-- (See below for the actual view)
--
CREATE TABLE `v_parking_hourly_traffic` (
`hour_start` varchar(24)
,`event_type` varchar(13)
,`vehicle_count` bigint(21)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_parking_slot_usage`
-- (See below for the actual view)
--
CREATE TABLE `v_parking_slot_usage` (
`slot_id` int(11)
,`slot_number` int(11)
,`total_sessions` bigint(21)
,`sessions_last_24h` decimal(22,0)
,`last_entry_time` datetime
,`last_exit_time` datetime
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_parking_traffic_events`
-- (See below for the actual view)
--
CREATE TABLE `v_parking_traffic_events` (
`session_id` int(11)
,`slot_id` int(11)
,`slot_number` int(11)
,`event_type` varchar(13)
,`event_time` datetime
);

-- --------------------------------------------------------

--
-- Structure for view `v_parking_hourly_traffic`
--
DROP TABLE IF EXISTS `v_parking_hourly_traffic`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_parking_hourly_traffic`  AS SELECT date_format(`v_parking_traffic_events`.`event_time`,'%Y-%m-%d %H:00:00') AS `hour_start`, `v_parking_traffic_events`.`event_type` AS `event_type`, count(0) AS `vehicle_count` FROM `v_parking_traffic_events` GROUP BY date_format(`v_parking_traffic_events`.`event_time`,'%Y-%m-%d %H:00:00'), `v_parking_traffic_events`.`event_type` ;

-- --------------------------------------------------------

--
-- Structure for view `v_parking_slot_usage`
--
DROP TABLE IF EXISTS `v_parking_slot_usage`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_parking_slot_usage`  AS SELECT `psl`.`id` AS `slot_id`, `psl`.`slot_number` AS `slot_number`, count(`ps`.`id`) AS `total_sessions`, sum(case when `ps`.`checkin_time` >= current_timestamp() - interval 24 hour then 1 else 0 end) AS `sessions_last_24h`, max(`ps`.`checkin_time`) AS `last_entry_time`, max(`ps`.`checkout_time`) AS `last_exit_time` FROM (`parking_slots` `psl` left join `parking_sessions` `ps` on(`ps`.`slot_id` = `psl`.`id`)) GROUP BY `psl`.`id`, `psl`.`slot_number` ;

-- --------------------------------------------------------

--
-- Structure for view `v_parking_traffic_events`
--
DROP TABLE IF EXISTS `v_parking_traffic_events`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v_parking_traffic_events`  AS SELECT `ps`.`id` AS `session_id`, `ps`.`slot_id` AS `slot_id`, `psl`.`slot_number` AS `slot_number`, 'vehicle_enter' AS `event_type`, `ps`.`checkin_time` AS `event_time` FROM (`parking_sessions` `ps` left join `parking_slots` `psl` on(`psl`.`id` = `ps`.`slot_id`)) WHERE `ps`.`checkin_time` is not null union all select `ps`.`id` AS `session_id`,`ps`.`slot_id` AS `slot_id`,`psl`.`slot_number` AS `slot_number`,'vehicle_exit' AS `event_type`,`ps`.`checkout_time` AS `event_time` from (`parking_sessions` `ps` left join `parking_slots` `psl` on(`psl`.`id` = `ps`.`slot_id`)) where `ps`.`checkout_time` is not null  ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `camera_logs`
--
ALTER TABLE `camera_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_camera_logs_slot` (`slot_id`),
  ADD KEY `idx_camera_logs_event` (`event`),
  ADD KEY `idx_camera_logs_date` (`captured_at`);

--
-- Indexes for table `parking_alerts`
--
ALTER TABLE `parking_alerts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_parking_alerts_dedupe_key` (`dedupe_key`),
  ADD KEY `idx_parking_alerts_status_created` (`status`,`created_at`),
  ADD KEY `idx_parking_alerts_type_created` (`alert_type`,`created_at`);

--
-- Indexes for table `parking_hourly_stats`
--
ALTER TABLE `parking_hourly_stats`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_parking_hourly_stats_hour` (`hour_start`),
  ADD KEY `idx_parking_hourly_stats_generated` (`generated_at`);

--
-- Indexes for table `parking_predictions`
--
ALTER TABLE `parking_predictions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_parking_predictions_target_hour` (`target_hour`),
  ADD KEY `idx_parking_predictions_created` (`created_at`);

--
-- Indexes for table `parking_sessions`
--
ALTER TABLE `parking_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sessions_user` (`user_id`),
  ADD KEY `idx_sessions_slot` (`slot_id`),
  ADD KEY `idx_sessions_status` (`status`);

--
-- Indexes for table `parking_slots`
--
ALTER TABLE `parking_slots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slot_number` (`slot_number`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payments_session` (`session_id`),
  ADD KEY `idx_payments_status` (`payment_status`);

--
-- Indexes for table `payment_qr`
--
ALTER TABLE `payment_qr`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `qr_reference` (`qr_reference`),
  ADD KEY `idx_payment_qr_payment` (`payment_id`);

--
-- Indexes for table `payment_slips`
--
ALTER TABLE `payment_slips`
  ADD PRIMARY KEY (`id`),
  ADD KEY `payment_id` (`payment_id`);

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_res_slot` (`slot_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `camera_logs`
--
ALTER TABLE `camera_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=186;

--
-- AUTO_INCREMENT for table `parking_alerts`
--
ALTER TABLE `parking_alerts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `parking_hourly_stats`
--
ALTER TABLE `parking_hourly_stats`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=841;

--
-- AUTO_INCREMENT for table `parking_predictions`
--
ALTER TABLE `parking_predictions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `parking_sessions`
--
ALTER TABLE `parking_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=315;

--
-- AUTO_INCREMENT for table `parking_slots`
--
ALTER TABLE `parking_slots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `payment_qr`
--
ALTER TABLE `payment_qr`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `payment_slips`
--
ALTER TABLE `payment_slips`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `camera_logs`
--
ALTER TABLE `camera_logs`
  ADD CONSTRAINT `fk_camera_logs_slot` FOREIGN KEY (`slot_id`) REFERENCES `parking_slots` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `parking_sessions`
--
ALTER TABLE `parking_sessions`
  ADD CONSTRAINT `fk_sessions_slot` FOREIGN KEY (`slot_id`) REFERENCES `parking_slots` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_session` FOREIGN KEY (`session_id`) REFERENCES `parking_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `payment_qr`
--
ALTER TABLE `payment_qr`
  ADD CONSTRAINT `fk_qr_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `payment_slips`
--
ALTER TABLE `payment_slips`
  ADD CONSTRAINT `fk_payment_slip` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `fk_res_slot` FOREIGN KEY (`slot_id`) REFERENCES `parking_slots` (`id`) ON DELETE CASCADE;
COMMIT;

