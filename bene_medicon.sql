-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 11, 2025 at 11:44 AM
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
-- Database: `bene_medicon`
--

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`) VALUES
(1, 'Antibiotic'),
(4, 'Antiseptic'),
(5, 'Injection'),
(6, 'Other'),
(2, 'Pain Reliever'),
(3, 'Vitamins');

-- --------------------------------------------------------

--
-- Table structure for table `disposal_requests`
--

CREATE TABLE `disposal_requests` (
  `id` int(11) NOT NULL,
  `medicine_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `disposal_method` text NOT NULL,
  `disposed_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `disposal_requests`
--

INSERT INTO `disposal_requests` (`id`, `medicine_id`, `staff_id`, `disposal_method`, `disposed_at`) VALUES
(1, 35, 8, 'testing', '2025-10-11 16:56:45'),
(4, 39, 8, 'hi', '2025-10-11 17:20:29');

-- --------------------------------------------------------

--
-- Table structure for table `donation_requests`
--

CREATE TABLE `donation_requests` (
  `id` int(11) NOT NULL,
  `medicine_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `requested_at` datetime DEFAULT current_timestamp(),
  `approved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `donation_requests`
--

INSERT INTO `donation_requests` (`id`, `medicine_id`, `staff_id`, `status`, `requested_at`, `approved_at`) VALUES
(1, 35, 8, 'approved', '2025-09-28 15:54:35', '2025-09-28 15:55:49'),
(2, 36, 8, 'rejected', '2025-09-28 16:00:28', '2025-09-28 16:11:36'),
(3, 36, 8, 'approved', '2025-09-28 19:29:10', '2025-09-28 19:30:13'),
(4, 36, 8, 'rejected', '2025-09-28 20:41:43', '2025-09-28 20:42:27'),
(5, 36, 8, 'pending', '2025-10-11 16:14:43', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `expired_logs`
--

CREATE TABLE `expired_logs` (
  `id` int(11) NOT NULL,
  `medicine_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `type` varchar(50) DEFAULT NULL,
  `batch_date` date DEFAULT NULL,
  `expired_date` date DEFAULT NULL,
  `quantity_at_expiry` int(11) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `recorded_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `expired_logs`
--

INSERT INTO `expired_logs` (`id`, `medicine_id`, `name`, `type`, `batch_date`, `expired_date`, `quantity_at_expiry`, `image`, `recorded_at`) VALUES
(1, 5, 'PROPAN TLC', 'Vitamins', '2025-03-23', '2025-08-23', 50, '1758969991_propantlc_vitamins.png', '2025-08-26 13:23:03'),
(3, 16, 'Isoprpyl Alcohol', 'Antiseptic', '2025-04-03', '2025-08-19', 99, 'greencross_alcohol.jpg', '2025-08-26 13:23:03'),
(4, 18, 'Plaster', 'Other', '2025-08-19', '2025-08-21', 79, 'watsons_plasters.jpg', '2025-08-26 13:23:03'),
(5, 19, 'Flu Vaccine', 'Injection', '2025-08-20', '2025-08-22', 50, '1758969955_influenza_flu_vaccine.png', '2025-08-26 13:23:03'),
(6, 21, 'Alaxan', 'Pain Reliever', '2025-08-20', '2025-08-22', 90, '1758972394_paracetamol_alaxan.jpg', '2025-08-26 13:23:03'),
(7, 22, 'Amoxicilin', 'Antibiotic', '2025-08-24', '2025-08-25', 27, '1758969936_amoxicilin_antibiotic.png', '2025-08-26 13:23:03'),
(8, 24, 'Efficascent Oil', 'Pain Reliever', '2025-08-24', '2025-08-25', 2, '1758969999_efficasent_oil.png', '2025-08-26 13:23:03'),
(16, 22, 'Nystatin', 'Antibiotic', '2025-08-24', '2025-08-29', 31, 'nystatin.png', '2025-09-18 21:41:10'),
(17, 23, 'Ibuprofen', 'Antibiotic', '2025-08-24', '2025-08-26', 77, 'ibuprofen.jpg', '2025-09-18 21:41:10'),
(21, 33, 'Enervon', 'Vitamins', '2022-07-03', '2025-07-03', 8, '1758971814_enervon.jpg', '2025-09-27 19:16:54'),
(22, 34, 'Salonpas', 'Pain Reliever', '2020-03-03', '2022-02-02', 5, '1758972448_salonpas.jpg', '2025-09-27 19:27:28'),
(35, NULL, 'Centrum', 'Vitamins', '2025-09-27', '2025-09-28', 20, '1759027365_Centrum.jpg', '2025-10-02 08:36:55'),
(36, NULL, 'centrum 21', 'Vitamins', '2025-09-27', '2025-09-30', 100, '1759032703_Centrum.jpg', '2025-10-02 08:36:55');

-- --------------------------------------------------------

--
-- Table structure for table `logs`
--

CREATE TABLE `logs` (
  `id` int(11) NOT NULL,
  `user` varchar(100) DEFAULT NULL,
  `action` text DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `logs`
--

INSERT INTO `logs` (`id`, `user`, `action`, `timestamp`) VALUES
(1, 'admin', 'Added new user 122524', '2025-09-06 19:47:33'),
(2, 'admin', 'Deleted user 122524', '2025-09-06 19:47:57'),
(3, 'admin', 'Deleted user test123again', '2025-09-06 20:12:05'),
(4, 'admin', 'Updated user Nicole', '2025-09-06 20:27:32'),
(5, 'admin', 'Deleted user daniel', '2025-09-06 20:39:16'),
(6, 'admin', 'Added new user rongiee as staff', '2025-09-06 21:21:10'),
(7, 'admin', 'Added new user tryonly as staff', '2025-09-06 21:37:57'),
(8, 'admin', 'Added new user sirmark as staff', '2025-09-08 05:54:13'),
(9, 'admin', 'Added new user bomiii as staff', '2025-09-18 14:22:03'),
(10, 'admin', 'Reset password for tryonly', '2025-09-27 09:44:18'),
(11, 'admin', 'Added new user dra_eliza', '2025-09-27 11:58:07'),
(12, 'admin', 'Reset password for dra_eliza', '2025-09-27 12:05:45'),
(13, 'admin', 'Reset password for dra_eliza', '2025-09-27 12:12:45'),
(14, 'admin', 'Added new user meung', '2025-09-27 13:25:18'),
(15, 'admin', 'Added new user habbang', '2025-09-27 13:42:30'),
(16, 'admin', 'Deleted user habbang', '2025-09-27 13:48:10'),
(17, 'admin', 'Added new user habbang', '2025-09-27 13:48:51'),
(18, 'admin', 'Approved donation request for Centrum by Marc', '2025-09-28 07:55:49'),
(19, 'admin', 'Rejected donation request for centrum 21 by Marc', '2025-09-28 08:11:36'),
(20, 'admin', 'Approved donation request for centrum 21 by Marc', '2025-09-28 11:30:13'),
(21, 'admin', 'Rejected donation request for centrum 21 by Marc', '2025-09-28 12:42:27');

-- --------------------------------------------------------

--
-- Table structure for table `medicines`
--

CREATE TABLE `medicines` (
  `id` int(11) NOT NULL,
  `image` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` varchar(100) NOT NULL,
  `batch_date` date NOT NULL,
  `expired_date` date NOT NULL,
  `status` varchar(20) DEFAULT 'active',
  `removed_on` datetime DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 100,
  `created_at` datetime DEFAULT current_timestamp(),
  `last_updated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `medicines`
--

INSERT INTO `medicines` (`id`, `image`, `name`, `type`, `batch_date`, `expired_date`, `status`, `removed_on`, `quantity`, `created_at`, `last_updated`) VALUES
(5, '1758981943_paracetamol_biogesic.png', 'PARACETAMOL Biogesic', 'Pain Reliever', '2023-09-11', '2025-09-11', 'inactive', '2025-09-18 21:41:10', 50, '2025-09-11 16:09:45', '2025-10-06 10:43:42'),
(15, '1758969982_unilab_decolgen.jpg', 'UNILAB Decolgen', 'Pain Reliever', '2025-04-03', '2025-04-04', 'inactive', '2025-09-18 21:41:10', 100, '2025-09-11 16:09:45', '2025-10-06 10:43:42'),
(16, '1758969991_propantlc_vitamins.png', 'PROPAN TLC vitamins', 'Vitamins', '2025-04-03', '2025-08-19', 'inactive', '2025-09-18 21:41:10', 99, '2025-09-11 16:09:45', '2025-10-06 10:43:42'),
(18, '1758969999_efficasent_oil.png', 'Efficascent Oil', 'Pain Reliever', '2025-08-19', '2025-08-21', 'inactive', '2025-09-18 21:41:10', 79, '2025-09-11 16:09:45', '2025-10-06 10:43:42'),
(19, '1758969955_influenza_flu_vaccine.png', 'Flu Vaccine', 'Injection', '2025-08-20', '2025-08-22', 'inactive', '2025-09-18 21:41:10', 50, '2025-09-11 16:09:45', '2025-10-06 10:43:42'),
(21, '1758969948_DIATABS.jpg', 'Diatabs', 'Pain Reliever', '2025-08-20', '2025-08-22', 'inactive', '2025-09-18 21:41:10', 90, '2025-09-11 16:09:45', '2025-10-06 10:43:42'),
(22, '1758969936_amoxicilin_antibiotic.png', 'Amoxicilin', 'Antibiotic', '2025-08-24', '2025-08-29', 'inactive', '2025-09-18 21:41:10', 31, '2025-09-11 16:09:45', '2025-10-06 10:43:42'),
(33, '1758971814_enervon.jpg', 'Enervon', 'Vitamins', '2022-07-03', '2025-07-03', 'inactive', '2025-09-27 19:16:54', 9, '2025-09-27 19:16:54', '2025-10-06 10:43:42'),
(34, '1758972448_salonpas.jpg', 'Salonpas', 'Pain Reliever', '2020-03-03', '2026-11-18', 'inactive', '2025-09-27 19:27:28', 5, '2025-09-27 19:27:28', '2025-10-11 17:16:47'),
(35, '1759027365_Centrum.jpg', 'Centrum', 'Vitamins', '2025-09-27', '2025-11-28', 'disposed', '2025-10-02 08:36:55', 20, '2025-09-27 23:30:21', '2025-10-11 16:56:45'),
(36, '1759032703_Centrum.jpg', 'centrum 21', 'Vitamins', '2025-09-27', '2026-10-03', 'inactive', '2025-10-02 08:36:55', 150, '2025-09-27 23:35:13', '2025-10-11 15:30:51'),
(39, '1760174289_1bd0cf98d703da08846a7d386b4598cb.1000x563x1.jpg', 'BENE', 'Other', '2025-10-11', '2025-11-11', 'disposed', NULL, 100, '2025-10-11 17:18:09', '2025-10-11 17:20:29');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `message`, `is_read`, `created_at`) VALUES
(1, 1, 'A user attempted password reset but needs admin assistance.', 1, '2025-09-18 16:15:45'),
(2, 7, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-18 16:15:45'),
(3, 9, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-18 16:15:45'),
(4, 1, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-27 09:00:05'),
(5, 7, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-27 09:00:05'),
(6, 9, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-27 09:00:05'),
(7, 1, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-27 09:12:28'),
(8, 7, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-27 09:12:28'),
(9, 9, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-27 09:12:28'),
(10, 1, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-27 09:12:36'),
(11, 7, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-27 09:12:36'),
(12, 9, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-27 09:12:36'),
(13, 1, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-27 09:12:39'),
(14, 7, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-27 09:12:39'),
(15, 9, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-27 09:12:39'),
(16, 1, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-27 09:14:40'),
(17, 7, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-27 09:14:41'),
(18, 9, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-27 09:14:41'),
(19, 1, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-27 12:12:03'),
(20, 7, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-27 12:12:03'),
(21, 9, 'A user attempted password reset but needs admin assistance.', 0, '2025-09-27 12:12:03'),
(22, 1, 'Marc requested donation for medicine \"Centrum\".', 0, '2025-09-28 07:54:35'),
(23, 7, 'Marc requested donation for medicine \"Centrum\".', 0, '2025-09-28 07:54:35'),
(24, 9, 'Marc requested donation for medicine \"Centrum\".', 0, '2025-09-28 07:54:35'),
(25, 8, 'Your donation request for \"Centrum\" has been approved by admin.', 0, '2025-09-28 07:55:49'),
(26, 1, 'Marc requested donation for medicine \"centrum 21\".', 1, '2025-09-28 08:00:28'),
(27, 7, 'Marc requested donation for medicine \"centrum 21\".', 1, '2025-09-28 08:00:28'),
(28, 9, 'Marc requested donation for medicine \"centrum 21\".', 0, '2025-09-28 08:00:28'),
(29, 8, 'Your donation request for \"centrum 21\" was rejected by admin.', 0, '2025-09-28 08:11:36'),
(30, 1, 'Marc requested donation for medicine \"centrum 21\".', 0, '2025-09-28 11:29:10'),
(31, 7, 'Marc requested donation for medicine \"centrum 21\".', 0, '2025-09-28 11:29:10'),
(32, 9, 'Marc requested donation for medicine \"centrum 21\".', 0, '2025-09-28 11:29:10'),
(33, 8, 'Your donation request for \"centrum 21\" has been approved by admin.', 0, '2025-09-28 11:30:13'),
(34, 1, 'Marc requested donation for medicine \"centrum 21\".', 0, '2025-09-28 12:41:43'),
(35, 7, 'Marc requested donation for medicine \"centrum 21\".', 0, '2025-09-28 12:41:43'),
(36, 9, 'Marc requested donation for medicine \"centrum 21\".', 0, '2025-09-28 12:41:43'),
(37, 8, 'Your donation request for \"centrum 21\" was rejected by admin.', 0, '2025-09-28 12:42:27'),
(38, 1, 'Marc requested donation for medicine \"centrum 21\".', 0, '2025-10-11 08:14:43'),
(39, 7, 'Marc requested donation for medicine \"centrum 21\".', 0, '2025-10-11 08:14:43'),
(40, 9, 'Marc requested donation for medicine \"centrum 21\".', 0, '2025-10-11 08:14:43');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `security_question` varchar(255) NOT NULL,
  `security_answer` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'staff',
  `dob` date DEFAULT NULL,
  `force_password_change` tinyint(1) DEFAULT 1,
  `password_changed` tinyint(1) DEFAULT 0,
  `force_security_setup` tinyint(1) DEFAULT 0,
  `profile_pic` varchar(255) DEFAULT 'default.png'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `security_question`, `security_answer`, `password`, `created_at`, `last_login`, `role`, `dob`, `force_password_change`, `password_changed`, `force_security_setup`, `profile_pic`) VALUES
(1, 'medicon25', '', '', '$2y$10$S8VqTRG12Xnnb/Dvv08KhOtuKDz9Lz.V78LBpSXt4M./N5ZiurkhS', '2025-03-20 10:42:23', '2025-09-28 21:32:53', 'admin', NULL, 1, 0, 0, 'default.png'),
(7, 'frenz', '', '', '$2y$10$Y1BxaTrl/uMLNdjjAdPt/etGMpFBXVxaOYS2Vea10R3yKbBOygr.W', '2025-04-04 13:23:57', '2025-10-11 16:59:00', 'admin', NULL, 1, 0, 0, 'default.png'),
(8, 'Marc', '', '', '$2y$10$gVOqI03KMq5FHpykr1KEIuWM3yKzhFGWohyvq16QR8gdcMymVWDcy', '2025-04-04 13:29:00', '2025-10-11 17:16:20', 'staff', NULL, 1, 0, 0, 'avatar3.jpg'),
(9, 'Nicole', '', '', '$2y$10$vPz5i0uEcvO7yhrHG2TDOulXgYz4epyHonZu/SbpLWJcN.jfUVGxS', '2025-04-05 11:29:02', '2025-09-08 01:17:25', 'admin', NULL, 1, 0, 0, 'default.png'),
(10, 'test123', 'What is your favorite color?', '$2y$10$UC0VzgPQiy0jBe.pL2IbNevp5yjXFJnR2bB2z9ktiZBiiBqFK8KLy', '$2y$10$Ap/q4kJJtax5E9O.l3DXHObA3vB1tQBwPQF6IHL9on97hOXjMAqam', '2025-04-10 13:37:32', '2025-09-28 18:53:46', 'staff', '2002-05-15', 0, 0, 0, 'default.png'),
(17, 'rongiee', '', '', '$2y$10$2aVXj4BtukWN3fTfIjNJSu14uOccje/2qIiwR8PXDRo9qxiHyDW3O', '2025-09-06 21:21:10', '2025-09-07 05:46:36', 'staff', NULL, 0, 0, 0, 'default.png'),
(18, 'tryonly', 'What city were you born in?', '$2y$10$1XGXBWuEsFyVtR1.Aw2ttuvHGg9yPZnl98Q6friLxBYzyuSShQ5Va', '$2y$10$WHGHqDl7IeiaalR4D7hx8.ULm3FR3P1k8Bo2xBHiRyG/v5P8542ku', '2025-09-06 21:37:57', '2025-09-27 22:04:49', 'staff', NULL, 0, 0, 0, 'avatar2.jpg'),
(19, 'sirmark', '', '', '$2y$10$qD/o3B3n8UguzmD7CzPEOOLFGc6HFc/HbCP70Z3948UAgSbWCoOPe', '2025-09-08 05:54:13', '2025-09-08 13:54:43', 'staff', NULL, 1, 0, 0, 'default.png'),
(20, 'bomii', 'What is your pet\'s name?', '$2y$10$APEBvYTdjpljG/g/OvTO6eTCcSwDegDgeW0fvS.MKOQn6xJ4lszN6', '$2y$10$obGPCAW00NVIAOw./UNX3OhtZxxkiCxvL821/6T/Dblh6cFgjTw3G', '2025-09-18 14:22:03', '2025-09-18 22:22:54', 'staff', NULL, 0, 0, 0, 'default.png'),
(21, 'dra_eliza', 'What city were you born in?', '$2y$10$R/kE.JhZ.C17SWhHtCqQ.uDK7tIdhigccr7vD9/NuPMrP9m0/6296', '$2y$10$SSebLK7XZPvJoP.oPIFsHe/U5xQXiP4NSF6Sg5DSf9jc0BYXju.TC', '2025-09-27 11:58:07', '2025-09-27 21:22:04', 'staff', NULL, 0, 0, 0, 'avatar3.jpg'),
(22, 'meung', 'What is your favorite color?', '$2y$10$n85QtsjIyTsSuhsNUHeTVu52eWn1N9bvUdTPJDKVXZoiyLLbxdct2', '$2y$10$AKqwOHDvb44BJxy34Sgh/u.xqpV33jvPzbXIh88YWiKJGG9Q91.rW', '2025-09-27 13:25:18', '2025-09-27 21:38:23', 'staff', NULL, 0, 0, 0, 'avatar1.jpg'),
(24, 'habbang', 'What is your favorite color?', '$2y$10$z.APn0FA9p5RznFa9Ryn9eMsFqT6ZuBtnocXu3nDjVLFqhxk2.Q/i', '$2y$10$.NtSLtOPxLZqNQypPWhsIuYttAXILBZ99XHlxqggiKwbN32chAn.q', '2025-09-27 13:48:51', '2025-09-27 21:50:01', 'staff', NULL, 0, 0, 0, 'avatar1.jpg');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `disposal_requests`
--
ALTER TABLE `disposal_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `medicine_id` (`medicine_id`),
  ADD KEY `staff_id` (`staff_id`);

--
-- Indexes for table `donation_requests`
--
ALTER TABLE `donation_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `medicine_id` (`medicine_id`),
  ADD KEY `staff_id` (`staff_id`);

--
-- Indexes for table `expired_logs`
--
ALTER TABLE `expired_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `logs`
--
ALTER TABLE `logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `medicines`
--
ALTER TABLE `medicines`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `username_2` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `disposal_requests`
--
ALTER TABLE `disposal_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `donation_requests`
--
ALTER TABLE `donation_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `expired_logs`
--
ALTER TABLE `expired_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `logs`
--
ALTER TABLE `logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `medicines`
--
ALTER TABLE `medicines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `disposal_requests`
--
ALTER TABLE `disposal_requests`
  ADD CONSTRAINT `disposal_requests_ibfk_1` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `disposal_requests_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `donation_requests`
--
ALTER TABLE `donation_requests`
  ADD CONSTRAINT `donation_requests_ibfk_1` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `donation_requests_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
