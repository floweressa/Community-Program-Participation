-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 12, 2026 at 08:58 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `community_program_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `attendance_id` int(11) NOT NULL,
  `participation_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `status` enum('present','absent') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`attendance_id`, `participation_id`, `attendance_date`, `status`, `created_at`) VALUES
(3, 5, '2026-04-27', 'present', '2026-04-27 02:54:05'),
(4, 6, '2026-04-27', 'absent', '2026-04-27 02:54:05'),
(10, 12, '2026-05-12', 'present', '2026-05-12 06:43:05');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `log_id` int(10) UNSIGNED NOT NULL,
  `performed_by` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `table_name` varchar(100) NOT NULL,
  `record_id` int(10) UNSIGNED DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`log_id`, `performed_by`, `action`, `table_name`, `record_id`, `details`, `ip_address`, `created_at`) VALUES
(1, 1, 'CREATE', 'users', 11, NULL, NULL, '2026-04-27 10:29:40'),
(2, 1, 'CREATE', 'users', 12, NULL, NULL, '2026-04-27 10:32:33'),
(3, 1, 'DELETE', 'users', 5, NULL, NULL, '2026-05-12 10:16:18'),
(4, 1, 'DELETE', 'users', 24, NULL, NULL, '2026-05-12 10:16:25'),
(5, 1, 'DELETE', 'users', 23, NULL, NULL, '2026-05-12 11:07:17'),
(6, 1, 'DELETE', 'users', 21, NULL, NULL, '2026-05-12 11:07:21'),
(7, 1, 'DELETE', 'users', 10, NULL, NULL, '2026-05-12 11:12:11'),
(8, 1, 'CREATE', 'users', 27, NULL, NULL, '2026-05-12 14:22:20'),
(9, 1, 'DELETE', 'users', 27, NULL, NULL, '2026-05-12 14:27:25'),
(10, 1, 'DELETE', 'users', 26, NULL, NULL, '2026-05-12 14:27:29'),
(11, 1, 'DELETE', 'users', 25, NULL, NULL, '2026-05-12 14:27:32');

-- --------------------------------------------------------

--
-- Table structure for table `auth_tokens`
--

CREATE TABLE `auth_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `token_type` varchar(50) NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `otp_plain` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `participants`
--

CREATE TABLE `participants` (
  `participant_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `age` int(11) DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `address` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `participants`
--

INSERT INTO `participants` (`participant_id`, `user_id`, `age`, `gender`, `address`) VALUES
(3, 11, 20, 'Female', 'Chico Atis Drive Baliwasan, Zamboanga City'),
(4, 12, 41, 'Female', 'Leonard Aragoncillo St. Canelar, Zamboanga City'),
(5, 13, 16, 'Male', 'Maasin Karagasan, Zamboanga City'),
(20, 28, 19, 'Female', 'Callejon Drive Guiwan, Zamboanga City');

-- --------------------------------------------------------

--
-- Table structure for table `participation`
--

CREATE TABLE `participation` (
  `participation_id` int(11) NOT NULL,
  `participant_id` int(11) NOT NULL,
  `program_id` int(11) NOT NULL,
  `participation_date` date NOT NULL,
  `status` enum('registered','completed','cancelled') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `participation`
--

INSERT INTO `participation` (`participation_id`, `participant_id`, `program_id`, `participation_date`, `status`, `created_at`) VALUES
(5, 5, 5, '2026-04-27', 'registered', '2026-04-27 02:49:13'),
(6, 4, 5, '2026-04-27', 'registered', '2026-04-27 02:50:40'),
(12, 20, 13, '2026-05-12', 'registered', '2026-05-12 06:40:27');

-- --------------------------------------------------------

--
-- Table structure for table `programs`
--

CREATE TABLE `programs` (
  `program_id` int(11) NOT NULL,
  `program_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `location` varchar(150) DEFAULT NULL,
  `status` enum('upcoming','ongoing','completed','cancelled') DEFAULT 'upcoming',
  `max_participants` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `programs`
--

INSERT INTO `programs` (`program_id`, `program_name`, `description`, `start_date`, `end_date`, `start_time`, `end_time`, `location`, `status`, `max_participants`, `created_by`, `created_at`) VALUES
(5, 'Linis Kanal Program', 'A community activity that focuses on cleaning canals and drainage systems to prevent flooding, improve sanitation, and keep the surroundings safe and healthy.', '2026-05-01', '2026-05-01', '07:00:00', '16:00:00', 'Barangay Guiwan', 'completed', 20, 2, '2026-04-27 01:59:15'),
(8, 'Community Gardening Project', 'A project that encourages residents to grow vegetables and plants together, promoting food security, teamwork, and sustainable living.', '2026-04-29', '2026-04-30', '10:00:00', '15:00:00', 'Near Sto. Nino Chapel', 'cancelled', 20, 2, '2026-04-27 02:03:18'),
(10, 'Anti-Drug Awareness Program', 'Educates residents, especially the youth, about the dangers of illegal drugs and promotes a drug-free community.', '2026-06-01', '2026-06-01', '08:00:00', '16:00:00', 'Barangay Hall of Divisoria', 'upcoming', NULL, 3, '2026-05-12 05:05:14'),
(13, 'Digital Literacy Program', 'Teaches basic computer and internet skills to help community members adapt to modern technology.', '2026-05-12', '2026-05-13', '07:00:00', '16:00:00', 'Barangay Hall of Putik', 'ongoing', 15, 3, '2026-05-12 06:38:30');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','staff','participant') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `verification_token` varchar(64) DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `first_name`, `middle_name`, `last_name`, `email`, `username`, `password`, `role`, `created_at`, `is_verified`, `is_active`, `verification_token`, `token_expires_at`) VALUES
(1, 'Admin', NULL, 'User', 'admin@bayanihan.org', NULL, '$2y$10$QgGbFbfCqtvKxZ3Buqqpeeb1nZyXv4bP8Esx98iDdeLurqBNc6r7i', 'admin', '2026-04-18 23:21:54', 0, 1, NULL, NULL),
(2, 'Juan', 'Flores', 'Cruz', 'juan.cruz@bayanihan.org', NULL, '$2y$10$KZPfTlMwLVfHcRBdkSC.POeH6sVtxhjS2y7DQuAK4eIxxjQR2G/zu', 'staff', '2026-04-18 23:21:54', 0, 1, NULL, NULL),
(3, 'Maria', 'Santos', 'Reyes', 'maria.reyes@bayanihan.org', NULL, '$2y$10$PRj3g8gFwYd9fg91IUCHAOxFg9C6z6Aq.bXOs4HjgYcSK7lG.3zVC', 'staff', '2026-04-18 23:21:54', 0, 1, NULL, NULL),
(4, 'Carlos', 'Lopez', 'Garcia', 'carlos.garcia@bayanihan.org', NULL, '$2y$10$xTkh2OTEJ3PappFBgur7WeC3Xq.YBi1ukD8VBbEJTijtDnniRSnna', 'staff', '2026-04-18 23:21:54', 0, 1, NULL, NULL),
(11, 'Liberty', 'Villajuan', 'Libre', 'liberty.libre@gmail.com', NULL, '$2y$10$eBkHLnqJUWSYiJMRCL6zZ.FbcYveUFXQNyweYbEfcs5paQlpvm2Km', 'participant', '2026-04-27 02:29:40', 0, 1, NULL, NULL),
(12, 'Angel', 'Aragoncillo', 'Rojas', 'angel.rojas@gmail.com', NULL, '$2y$10$xcbzkQ.Xh0epYpLsrRG1KuIdmM.aVDIsjAgKSm1SuhcVZvPKLwieS', 'participant', '2026-04-27 02:32:33', 0, 1, NULL, NULL),
(13, 'Marc Roland', 'Dagayloan', 'De Zena', 'marc.dezena@gmail.com', NULL, '$2y$10$vD57tMm7hxz/kwUb65DtEumoVjksqMX8rZ4X.bjl5hG3Idd/hAR/.', 'participant', '2026-04-27 02:35:25', 0, 1, NULL, NULL),
(28, 'Jessa', 'Abayle', 'Flores', 'jessafloweres@gmail.com', NULL, '$2y$10$NsJMDjru3bMowOqR4EuKlubvcCNMJqviTYslOVMpQi2pa1dp.MNYy', 'participant', '2026-05-12 06:39:14', 1, 1, NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`attendance_id`),
  ADD KEY `participation_id` (`participation_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_performed_by` (`performed_by`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_table_name` (`table_name`);

--
-- Indexes for table `auth_tokens`
--
ALTER TABLE `auth_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `participants`
--
ALTER TABLE `participants`
  ADD PRIMARY KEY (`participant_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `participation`
--
ALTER TABLE `participation`
  ADD PRIMARY KEY (`participation_id`),
  ADD KEY `participant_id` (`participant_id`),
  ADD KEY `program_id` (`program_id`);

--
-- Indexes for table `programs`
--
ALTER TABLE `programs`
  ADD PRIMARY KEY (`program_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `attendance_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `auth_tokens`
--
ALTER TABLE `auth_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `participants`
--
ALTER TABLE `participants`
  MODIFY `participant_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `participation`
--
ALTER TABLE `participation`
  MODIFY `participation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `programs`
--
ALTER TABLE `programs`
  MODIFY `program_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`participation_id`) REFERENCES `participation` (`participation_id`) ON DELETE CASCADE;

--
-- Constraints for table `auth_tokens`
--
ALTER TABLE `auth_tokens`
  ADD CONSTRAINT `auth_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `participants`
--
ALTER TABLE `participants`
  ADD CONSTRAINT `participants_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `participation`
--
ALTER TABLE `participation`
  ADD CONSTRAINT `participation_ibfk_1` FOREIGN KEY (`participant_id`) REFERENCES `participants` (`participant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `participation_ibfk_2` FOREIGN KEY (`program_id`) REFERENCES `programs` (`program_id`) ON DELETE CASCADE;

--
-- Constraints for table `programs`
--
ALTER TABLE `programs`
  ADD CONSTRAINT `programs_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
