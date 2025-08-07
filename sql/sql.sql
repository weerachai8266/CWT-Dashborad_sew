-- phpMyAdmin SQL Dump
-- version 5.1.1deb5ubuntu1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Aug 05, 2025 at 11:14 AM
-- Server version: 8.0.42-0ubuntu0.22.04.2
-- PHP Version: 8.1.2-1ubuntu2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `automotive`
--

-- --------------------------------------------------------

--
-- Table structure for table `break_times`
--

CREATE TABLE `break_times` (
  `id` int NOT NULL,
  `break_name` varchar(50) DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `duration_minutes` int DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `item`
--

CREATE TABLE `item` (
  `id` int NOT NULL,
  `item` varchar(30) NOT NULL,
  `name` varchar(255) NOT NULL,
  `model` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `nickname` varchar(30) NOT NULL,
  `color` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qc_3rd`
--

CREATE TABLE `qc_3rd` (
  `id` int NOT NULL,
  `item` varchar(255) NOT NULL,
  `qty` int NOT NULL DEFAULT '1',
  `status` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qc_fb`
--

CREATE TABLE `qc_fb` (
  `id` int NOT NULL,
  `item` varchar(255) NOT NULL,
  `qty` int NOT NULL DEFAULT '1',
  `status` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qc_fc`
--

CREATE TABLE `qc_fc` (
  `id` int NOT NULL,
  `item` varchar(255) NOT NULL,
  `qty` int NOT NULL DEFAULT '1',
  `status` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qc_issue`
--

CREATE TABLE `qc_issue` (
  `id` int NOT NULL,
  `issue` varchar(255) NOT NULL,
  `status` int NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qc_ng`
--

CREATE TABLE `qc_ng` (
  `id` int NOT NULL,
  `part` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `detail` varchar(255) NOT NULL,
  `lot` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `process` varchar(10) NOT NULL,
  `qty` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qc_rb`
--

CREATE TABLE `qc_rb` (
  `id` int NOT NULL,
  `item` varchar(255) NOT NULL,
  `qty` int NOT NULL DEFAULT '1',
  `status` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qc_rc`
--

CREATE TABLE `qc_rc` (
  `id` int NOT NULL,
  `item` varchar(255) NOT NULL,
  `qty` int NOT NULL DEFAULT '1',
  `status` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qc_sub`
--

CREATE TABLE `qc_sub` (
  `id` int NOT NULL,
  `item` varchar(255) NOT NULL,
  `qty` int NOT NULL DEFAULT '1',
  `status` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sewing_3rd`
--

CREATE TABLE `sewing_3rd` (
  `id` int NOT NULL,
  `item` varchar(255) NOT NULL,
  `qty` int NOT NULL DEFAULT '1',
  `status` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sewing_fb`
--

CREATE TABLE `sewing_fb` (
  `id` int NOT NULL,
  `item` varchar(255) NOT NULL,
  `qty` int NOT NULL DEFAULT '1',
  `status` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sewing_fc`
--

CREATE TABLE `sewing_fc` (
  `id` int NOT NULL,
  `item` varchar(255) NOT NULL,
  `qty` int NOT NULL DEFAULT '1',
  `status` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sewing_lot`
--

CREATE TABLE `sewing_lot` (
  `id` int NOT NULL,
  `lot` varchar(100) NOT NULL,
  `model` varchar(100) NOT NULL,
  `color` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sewing_man_act`
--

CREATE TABLE `sewing_man_act` (
  `id` int NOT NULL,
  `shift` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `thour` float NOT NULL,
  `fc_act` int NOT NULL DEFAULT '0',
  `fb_act` int NOT NULL DEFAULT '0',
  `rc_act` int NOT NULL DEFAULT '0',
  `rb_act` int NOT NULL DEFAULT '0',
  `3rd_act` int NOT NULL DEFAULT '0',
  `sub_act` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sewing_man_plan`
--

CREATE TABLE `sewing_man_plan` (
  `id` int NOT NULL,
  `fc_plan` int NOT NULL DEFAULT '0',
  `fb_plan` int NOT NULL DEFAULT '0',
  `rc_plan` int NOT NULL DEFAULT '0',
  `rb_plan` int NOT NULL DEFAULT '0',
  `3rd_plan` int NOT NULL DEFAULT '0',
  `sub_plan` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sewing_productivity_plan`
--

CREATE TABLE `sewing_productivity_plan` (
  `id` int NOT NULL,
  `fc` float NOT NULL,
  `fb` float NOT NULL,
  `rc` float NOT NULL,
  `rb` float NOT NULL,
  `3rd` float NOT NULL,
  `sub` float NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sewing_rb`
--

CREATE TABLE `sewing_rb` (
  `id` int NOT NULL,
  `item` varchar(255) NOT NULL,
  `qty` int NOT NULL DEFAULT '1',
  `status` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sewing_rc`
--

CREATE TABLE `sewing_rc` (
  `id` int NOT NULL,
  `item` varchar(255) NOT NULL,
  `qty` int NOT NULL DEFAULT '1',
  `status` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sewing_sub`
--

CREATE TABLE `sewing_sub` (
  `id` int NOT NULL,
  `item` varchar(255) NOT NULL,
  `qty` int NOT NULL DEFAULT '1',
  `status` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sewing_target`
--

CREATE TABLE `sewing_target` (
  `id` int NOT NULL,
  `fc` int NOT NULL,
  `fb` int NOT NULL,
  `rc` int NOT NULL,
  `rb` int NOT NULL,
  `3rd` int NOT NULL,
  `sub` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `break_times`
--
ALTER TABLE `break_times`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_break_times_active` (`is_active`),
  ADD KEY `idx_break_times_time_range` (`start_time`,`end_time`);

--
-- Indexes for table `item`
--
ALTER TABLE `item`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `qc_3rd`
--
ALTER TABLE `qc_3rd`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `qc_fb`
--
ALTER TABLE `qc_fb`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `qc_fc`
--
ALTER TABLE `qc_fc`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_qc_fc_created_at` (`created_at`);

--
-- Indexes for table `qc_issue`
--
ALTER TABLE `qc_issue`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_issue` (`issue`);

--
-- Indexes for table `qc_ng`
--
ALTER TABLE `qc_ng`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_process_created_at` (`process`,`created_at`),
  ADD KEY `idx_qc_ng_created_at` (`created_at`);

--
-- Indexes for table `qc_rb`
--
ALTER TABLE `qc_rb`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `qc_rc`
--
ALTER TABLE `qc_rc`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `qc_sub`
--
ALTER TABLE `qc_sub`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_qc_sub_created_at` (`created_at`);

--
-- Indexes for table `sewing_3rd`
--
ALTER TABLE `sewing_3rd`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sewing_fb`
--
ALTER TABLE `sewing_fb`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sewing_fc`
--
ALTER TABLE `sewing_fc`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sewing_fc_created_at` (`created_at`);

--
-- Indexes for table `sewing_lot`
--
ALTER TABLE `sewing_lot`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sewing_man_act`
--
ALTER TABLE `sewing_man_act`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sewing_man_plan`
--
ALTER TABLE `sewing_man_plan`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sewing_productivity_plan`
--
ALTER TABLE `sewing_productivity_plan`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sewing_rb`
--
ALTER TABLE `sewing_rb`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sewing_rc`
--
ALTER TABLE `sewing_rc`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sewing_sub`
--
ALTER TABLE `sewing_sub`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sewing_subass_created_at` (`created_at`),
  ADD KEY `idx_sewing_sub_created_at` (`created_at`);

--
-- Indexes for table `sewing_target`
--
ALTER TABLE `sewing_target`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `break_times`
--
ALTER TABLE `break_times`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `item`
--
ALTER TABLE `item`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qc_3rd`
--
ALTER TABLE `qc_3rd`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qc_fb`
--
ALTER TABLE `qc_fb`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qc_fc`
--
ALTER TABLE `qc_fc`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qc_issue`
--
ALTER TABLE `qc_issue`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qc_ng`
--
ALTER TABLE `qc_ng`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qc_rb`
--
ALTER TABLE `qc_rb`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qc_rc`
--
ALTER TABLE `qc_rc`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qc_sub`
--
ALTER TABLE `qc_sub`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sewing_3rd`
--
ALTER TABLE `sewing_3rd`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sewing_fb`
--
ALTER TABLE `sewing_fb`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sewing_fc`
--
ALTER TABLE `sewing_fc`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sewing_lot`
--
ALTER TABLE `sewing_lot`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sewing_man_act`
--
ALTER TABLE `sewing_man_act`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sewing_man_plan`
--
ALTER TABLE `sewing_man_plan`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sewing_productivity_plan`
--
ALTER TABLE `sewing_productivity_plan`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sewing_rb`
--
ALTER TABLE `sewing_rb`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sewing_rc`
--
ALTER TABLE `sewing_rc`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sewing_sub`
--
ALTER TABLE `sewing_sub`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sewing_target`
--
ALTER TABLE `sewing_target`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
