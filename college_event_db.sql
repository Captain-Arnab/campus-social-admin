-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 02, 2026 at 01:21 PM
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
-- Database: `exdeos_college_event_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `username`, `password`) VALUES
(1, 'admin', 'password123');

-- --------------------------------------------------------

--
-- Table structure for table `attendees`
--

CREATE TABLE `attendees` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendees`
--

INSERT INTO `attendees` (`id`, `event_id`, `user_id`, `joined_at`) VALUES
(1, 1, 2, '2025-12-19 07:37:59'),
(2, 1, 3, '2025-12-19 07:37:59'),
(3, 1, 11, '2025-12-19 07:37:59'),
(4, 2, 1, '2025-12-19 07:37:59'),
(5, 2, 5, '2025-12-19 07:37:59'),
(6, 2, 14, '2025-12-19 07:37:59'),
(7, 3, 5, '2025-12-19 07:37:59'),
(8, 3, 15, '2025-12-19 07:37:59'),
(9, 3, 19, '2025-12-19 07:37:59'),
(10, 6, 20, '2025-12-19 07:37:59'),
(11, 6, 18, '2025-12-19 07:37:59'),
(12, 7, 6, '2025-12-19 07:37:59'),
(13, 9, 7, '2025-12-19 07:37:59'),
(14, 9, 11, '2025-12-19 07:37:59'),
(15, 13, 18, '2025-12-19 07:37:59'),
(16, 14, 1, '2025-12-19 07:37:59'),
(17, 15, 2, '2025-12-19 07:37:59'),
(18, 15, 9, '2025-12-19 07:37:59'),
(19, 3, 21, '2025-12-20 05:36:58'),
(20, 9, 21, '2025-12-20 05:41:52'),
(21, 7, 21, '2025-12-20 06:25:53'),
(22, 8, 21, '2025-12-20 07:56:25'),
(23, 14, 21, '2025-12-20 09:56:41'),
(24, 15, 21, '2026-01-10 08:02:54'),
(25, 7, 24, '2026-01-12 01:47:23'),
(26, 13, 24, '2026-01-12 01:47:26');

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `event_date` datetime NOT NULL,
  `category` varchar(50) NOT NULL,
  `venue` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected','hold') DEFAULT 'pending',
  `organizer_id` int(11) NOT NULL,
  `banners` text DEFAULT NULL,
  `interest_count` int(11) DEFAULT 0,
  `hold_reason` text DEFAULT NULL,
  `reschedule_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `events`
--

INSERT INTO `events` (`id`, `title`, `description`, `event_date`, `category`, `venue`, `status`, `organizer_id`, `banners`, `interest_count`, `hold_reason`, `reschedule_date`, `created_at`) VALUES
(1, 'Cyber Hack 2026', 'A 24-hour national level hackathon focused on cybersecurity.', '2026-01-20 09:00:00', 'IT/Tech', 'Computer Lab A', 'approved', 1, '[\"hack.jpg\"]', 245, NULL, NULL, '2025-12-19 07:37:59'),
(2, 'Musical Fest Night', 'An evening of soulful melodies and rock bands.', '2026-02-14 18:30:00', 'Cultural', 'Main Stage Auditorium', 'approved', 10, '[]', 562, NULL, NULL, '2025-12-19 07:37:59'),
(3, 'College Premier League', 'Intra-college cricket tournament for all departments.', '2026-03-05 08:00:00', 'Sports', 'Main Field', 'approved', 3, '[\"foot.jpg\"]', 315, NULL, NULL, '2025-12-19 07:37:59'),
(4, 'AI Trends Seminar', 'Deep dive into current trends of Artificial Intelligence.', '2025-12-28 10:30:00', 'IT/Tech', 'Conference Hall B', 'pending', 11, '[]', 58, NULL, NULL, '2025-12-19 07:37:59'),
(5, 'Charity Marathon 5K', 'Run to raise funds for local orphanage education.', '2026-01-15 06:30:00', 'Social', 'College Track', 'pending', 4, '[\"run.jpg\"]', 120, NULL, NULL, '2025-12-19 07:37:59'),
(6, 'Inter-College Debate', 'Topic: Is Social Media killing genuine relationships?', '2026-01-25 11:00:00', 'Academic', 'Room 102', 'approved', 7, '[]', 135, NULL, NULL, '2025-12-19 07:37:59'),
(7, 'Lens Art Exhibit', 'Photography exhibition showcasing student talent.', '2026-02-01 10:00:00', 'Cultural', 'Art Gallery', 'approved', 5, '[\"photo.jpg\"]', 188, NULL, NULL, '2025-12-19 07:37:59'),
(8, 'Yoga for Mental Health', 'Early morning session for stress relief.', '2026-01-10 07:00:00', 'Sports', 'Gymnasium', 'approved', 8, '[]', 52, NULL, NULL, '2025-12-19 07:37:59'),
(9, 'Python Sprint', 'Fast-paced coding contest for beginners.', '2026-03-12 14:00:00', 'IT/Tech', 'Lab B', 'approved', 1, '[]', 210, NULL, NULL, '2025-12-19 07:37:59'),
(10, 'Stand-up Night 2026', 'Get ready for a night full of laughter.', '2026-02-20 19:00:00', 'Entertainment', 'Student Cafe', 'pending', 17, '[\"laugh.jpg\"]', 370, NULL, NULL, '2025-12-19 07:37:59'),
(11, 'Science & Innovation Expo', 'Working models from various science departments.', '2026-04-05 10:00:00', 'Academic', 'Central Atrium', 'pending', 18, '[]', 85, NULL, NULL, '2025-12-19 07:37:59'),
(12, 'Dance Face-off', 'Classical vs Western dance battle.', '2026-05-15 17:00:00', 'Cultural', 'Open Air Theater', 'rejected', 2, '[\"dance.jpg\"]', 710, NULL, NULL, '2025-12-19 07:37:59'),
(13, 'Career in FinTech', 'Guest lecture by industry experts.', '2026-01-12 11:00:00', 'Academic', 'Lecture Hall 1', 'approved', 13, '[]', 95, NULL, NULL, '2025-12-19 07:37:59'),
(14, 'Gaming Arena', 'Counter-Strike and Valorant tournament.', '2026-03-20 10:00:00', 'Entertainment', 'IT Block', 'approved', 11, '[\"gaming.jpg\"]', 420, NULL, NULL, '2025-12-19 07:37:59'),
(15, 'Blood Donation Camp', 'Save a life today.', '2026-01-18 09:00:00', 'Social', 'Medical Unit', 'approved', 4, '[]', 160, NULL, NULL, '2025-12-19 07:37:59'),
(16, 'TEST', 'TEST', '2025-12-12 12:55:00', 'IT/Tech', 'TEST', 'approved', 21, '[]', 0, NULL, NULL, '2025-12-20 06:27:05'),
(17, 'TESTING EVENT', 'TESTING', '2025-12-24 15:00:00', 'Sports', 'TEST LOCATION', 'approved', 21, '[]', 0, NULL, NULL, '2025-12-20 09:33:21'),
(18, 'TEST', 'TEST', '2025-12-24 15:03:00', 'IT/Tech', 'TEST', 'approved', 21, '[]', 0, NULL, NULL, '2025-12-20 09:33:56'),
(19, 'TEST', 'TEST', '2025-12-24 15:03:00', 'IT/Tech', 'TEST', 'approved', 21, '[]', 0, NULL, NULL, '2025-12-20 09:35:29'),
(20, 'Inter College cricket match', 'College cricket match', '2025-12-26 13:00:00', 'Sports', 'Eden Gardens', 'approved', 21, '[\"evt_1766229059_0_scaled_1000108374.jpg\"]', 0, NULL, NULL, '2025-12-20 11:10:59'),
(21, 'Sports Meetup', 'Sports Tournament Gathering', '2025-12-23 12:00:00', 'Sports', 'Main Maidan', 'approved', 22, '[]', 0, NULL, NULL, '2025-12-20 12:05:22'),
(22, 'TEST', 'TEST', '2025-12-30 16:00:00', 'IT/Tech', 'TEST', 'rejected', 21, '[\"evt_1766747679_0_banner_1766747656566.png\"]', 0, NULL, NULL, '2025-12-26 11:14:39'),
(23, 'TEST', 'TEST', '2025-12-30 16:00:00', 'IT/Tech', 'TEST', 'rejected', 21, '[\"evt_1766747691_0_banner_1766747656566.png\"]', 0, NULL, NULL, '2025-12-26 11:14:51'),
(24, 'TEST', 'TEST', '2025-12-30 16:00:00', 'IT/Tech', 'TEST', 'rejected', 21, '[\"evt_1766747712_0_banner_1766747656566.png\"]', 0, NULL, NULL, '2025-12-26 11:15:12'),
(25, 'TEST', 'TEST', '2025-12-30 17:00:00', 'Sports', 'TEST', 'approved', 21, '[\"evt_1766748009_0_banner_1766747988255.png\"]', 0, NULL, NULL, '2025-12-26 11:20:09'),
(26, '2025 Graduation Ceremony', 'Join us for an amazing event!', '2026-01-07 16:00:00', 'Cultural', 'Campus Venue, Main Hall', 'rejected', 21, '[\"evt_1767438954_0_event_poster_1767438870062.png\"]', 0, NULL, NULL, '2026-01-03 11:15:54'),
(27, '2025 Graduation Ceremony', 'Join us for an amazing event!', '2026-01-07 16:00:00', 'Cultural', 'Campus Venue, Main Hall', 'approved', 21, '[\"evt_1767439241_0_event_poster_1767438870062.png\"]', 0, NULL, NULL, '2026-01-03 11:20:41'),
(28, 'Python Training Camp', 'Join us for an amazing event!', '2026-01-13 16:00:00', 'IT/Tech', 'Online', 'approved', 21, '[\"evt_1767440986_0_event_poster_1767440913855.png\"]', 0, NULL, NULL, '2026-01-03 11:49:46'),
(29, 'BasketBall Tournament', 'You are cordially invited !!!', '2026-01-30 16:00:00', 'Sports', 'Brocelle Stadium, 123 Anywhere St., Any City', 'hold', 21, '[\"evt_1768031543_0_event_poster_1768031214676.png\"]', 0, 'Not going to happen', NULL, '2026-01-10 07:52:23');

-- --------------------------------------------------------

--
-- Table structure for table `event_status_log`
--

CREATE TABLE `event_status_log` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `admin_type` varchar(50) DEFAULT 'admin',
  `admin_username` varchar(100) NOT NULL,
  `old_status` varchar(50) NOT NULL,
  `new_status` varchar(50) NOT NULL,
  `remarks` text DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `event_status_log`
--

INSERT INTO `event_status_log` (`id`, `event_id`, `admin_type`, `admin_username`, `old_status`, `new_status`, `remarks`, `changed_at`) VALUES
(1, 14, 'subadmin', 'admin', 'approved', 'hold', 'Event put on hold: NA', '2026-01-22 10:03:50'),
(2, 14, 'subadmin', 'admin', 'hold', 'approved', 'Event approved and published', '2026-01-22 10:04:01'),
(3, 29, 'subadmin', 'admin', 'approved', 'approved', 'Event rescheduled: Wants that (From Jan 15, 2026 to Jan 30, 2026)', '2026-01-22 10:04:39'),
(4, 29, 'admin', 'admin', 'approved', 'hold', 'Event put on hold: Not going to happen', '2026-01-22 10:08:10');

-- --------------------------------------------------------

--
-- Table structure for table `favorites`
--

CREATE TABLE `favorites` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `favorites`
--

INSERT INTO `favorites` (`id`, `user_id`, `event_id`, `created_at`) VALUES
(2, 21, 8, '2025-12-20 08:53:38'),
(3, 21, 13, '2025-12-20 08:53:41'),
(4, 22, 20, '2025-12-20 12:03:34'),
(5, 22, 21, '2025-12-20 12:05:58'),
(6, 21, 29, '2026-01-10 08:05:44');

-- --------------------------------------------------------

--
-- Table structure for table `participant`
--

CREATE TABLE `participant` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `status` enum('active','blocked') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `participant`
--

INSERT INTO `participant` (`id`, `event_id`, `user_id`, `status`) VALUES
(1, 1, 1, 'active'),
(2, 1, 11, 'active'),
(3, 1, 21, 'active'),
(4, 3, 3, 'active'),
(5, 3, 9, 'active'),
(6, 3, 19, 'active'),
(7, 3, 21, 'active'),
(8, 6, 7, 'active'),
(9, 6, 6, 'active'),
(10, 9, 1, 'active'),
(11, 9, 11, 'active'),
(12, 9, 21, 'active'),
(13, 14, 11, 'active'),
(14, 14, 21, 'active'),
(15, 29, 21, 'active'),
(16, 29, 24, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `student_faculty`
--

CREATE TABLE `student_faculty` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `roll_number` varchar(255) NOT NULL,
  `emp_number` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_faculty`
--

INSERT INTO `student_faculty` (`id`, `user_id`, `roll_number`, `emp_number`) VALUES
(1, 23, 'RN-2026-023', 'NA'),
(2, 21, 'RN-2026-021', 'NA'),
(3, 22, 'RN-2026-022', 'NA'),
(4, 1, 'RN-2026-001', 'NA'),
(5, 2, 'RN-2026-002', 'NA'),
(6, 3, 'RN-2026-003', 'NA'),
(7, 4, 'RN-2026-004', 'NA'),
(8, 5, 'RN-2026-005', 'NA'),
(9, 6, 'RN-2026-006', 'NA'),
(10, 7, 'RN-2026-007', 'NA'),
(11, 8, 'RN-2026-008', 'NA'),
(12, 9, 'RN-2026-009', 'NA'),
(13, 10, 'RN-2026-010', 'NA'),
(14, 11, 'RN-2026-011', 'NA'),
(15, 12, 'RN-2026-012', 'NA'),
(16, 13, 'RN-2026-013', 'NA'),
(17, 14, 'RN-2026-014', 'NA'),
(18, 15, 'RN-2026-015', 'NA'),
(19, 16, 'RN-2026-016', 'NA'),
(20, 17, 'RN-2026-017', 'NA'),
(21, 18, 'RN-2026-018', 'NA'),
(22, 19, 'RN-2026-019', 'NA'),
(23, 20, 'RN-2026-020', 'NA'),
(24, 24, 'RN-2026-024', 'NA'),
(25, 25, 'RN-2026-101', 'NA'),
(26, 26, 'NA', 'EMP-2026-001');

-- --------------------------------------------------------

--
-- Table structure for table `subadmins`
--

CREATE TABLE `subadmins` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subadmins`
--

INSERT INTO `subadmins` (`id`, `username`, `password`, `full_name`, `email`, `status`, `created_at`) VALUES
(1, 'subadmin', 'password123', 'Sub Admin User', 'subadmin@college.edu', 'active', '2025-12-19 02:07:59');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `password` varchar(255) NOT NULL,
  `bio` text DEFAULT NULL,
  `interests` text DEFAULT NULL,
  `profile_pic` varchar(255) DEFAULT 'default_avatar.png',
  `status` enum('active','blocked') DEFAULT 'active',
  `is_student` tinyint(1) NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `email`, `phone`, `password`, `bio`, `interests`, `profile_pic`, `status`, `is_student`, `joined_at`) VALUES
(1, 'Aarav Patel', 'aarav@college.edu', '9876500001', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'Passionate about web technologies and building scalable apps.', 'IT/Tech,Coding,Open Source', 'p1.jpg', 'active', 1, '2025-11-01 10:00:00'),
(2, 'Ishita Sharma', 'ishita@college.edu', '9876500002', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'Professional Kathak dancer and art lover.', 'Cultural,Dance,Art', 'p2.jpg', 'active', 1, '2025-11-02 11:30:00'),
(3, 'Rohan Gupta', 'rohan@college.edu', '9876500003', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'Cricket is my life. Always ready for a match.', 'Sports,Fitness', 'p3.jpg', 'active', 1, '2025-11-03 09:15:00'),
(4, 'Meera Iyer', 'meera@college.edu', '9876500004', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'Social work enthusiast. Love helping the community.', 'Social,Volunteering', 'p4.jpg', 'active', 1, '2025-11-05 14:20:00'),
(5, 'Kabir Singh', 'kabir@college.edu', '9876500005', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'Amateur photographer. Exploring the world through my lens.', 'Cultural,Photography', 'p5.jpg', 'active', 1, '2025-11-10 16:45:00'),
(6, 'Ananya Sen', 'ananya@college.edu', '9876500006', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'Literature student and part-time poet.', 'Academic,Literature', 'p6.jpg', 'active', 1, '2025-11-12 10:00:00'),
(7, 'Vikram Rathore', 'vikram@college.edu', '9876500007', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'Debater and public speaker.', 'Academic,Debate', 'p7.jpg', 'active', 1, '2025-11-15 12:00:00'),
(8, 'Sanya Mirza', 'sanya@college.edu', '9876500008', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'Fitness freak. Love gymming and yoga.', 'Sports,Fitness', 'p8.jpg', 'active', 1, '2025-11-18 15:30:00'),
(9, 'Rahul Bose', 'rahul@college.edu', '9876500009', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'I love movies and stand-up comedy.', 'Entertainment,Drama', 'p9.jpg', 'active', 1, '2025-11-20 18:00:00'),
(10, 'Zoya Akhtar', 'zoya@college.edu', '9876500010', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'Classical music training since childhood.', 'Cultural,Music', 'p10.jpg', 'active', 1, '2025-11-22 09:00:00'),
(11, 'Amit Sharma', 'amit@college.edu', '9876500011', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'CS student. Loves problem solving.', 'IT/Tech,Competitive Programming', 'p11.jpg', 'active', 1, '2025-11-25 11:00:00'),
(12, 'Pooja Hegde', 'pooja@college.edu', '9876500012', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'Fashion and design enthusiast.', 'Cultural,Fashion', 'p12.jpg', 'active', 1, '2025-11-26 14:00:00'),
(13, 'Rajesh Kumar', 'rajesh@college.edu', '9876500013', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'History buff.', 'Academic,History', 'p13.jpg', 'active', 1, '2025-11-28 10:30:00'),
(14, 'Sneha Kapoor', 'sneha@college.edu', '9876500014', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'Foodie and blogger.', 'Entertainment,Social', 'p14.jpg', 'active', 1, '2025-11-30 16:00:00'),
(15, 'Arjun Reddy', 'arjun@college.edu', '9876500015', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'Quiet, focus, and determined.', 'Sports,Swimming', 'p15.jpg', 'active', 1, '2025-12-01 12:00:00'),
(16, 'Neha Kakkar', 'nehak@college.edu', '9876500016', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'Singing is my soul.', 'Cultural,Singing', 'p16.jpg', 'active', 1, '2025-12-02 09:30:00'),
(17, 'Manish Paul', 'manish@college.edu', '9876500017', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'Master of Ceremonies.', 'Entertainment,Anchor', 'p17.jpg', 'active', 1, '2025-12-03 14:15:00'),
(18, 'Tanvi Dogra', 'tanvi@college.edu', '9876500018', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'Science lover.', 'Academic,Physics', 'p18.jpg', 'active', 1, '2025-12-05 11:00:00'),
(19, 'Deepak Punia', 'deepak@college.edu', '9876500019', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'Wrestler and athlete.', 'Sports,Wrestling', 'p19.jpg', 'active', 1, '2025-12-08 10:00:00'),
(20, 'Sunita Williams', 'sunita@college.edu', '9876500020', '$2y$10$8W3Y6uR5S.5m.YxI/QO3.e9mD6V0ZkFhG.K.yJvV8fO/n6y3H5m.i', 'Space enthusiast.', 'Academic,Astronomy', 'p20.jpg', 'active', 1, '2025-12-10 15:00:00'),
(21, 'Arnab Som', 'arnabrks@gmail.com', '9123879312', '$2y$10$efI4Q0cqVOZFTwCVelxxxuyCmTSeqFFfqt3ME9PAeLfPQTlfO3F8m', 'Myself a developer', 'Sports, Tech, Art', 'profile_21_1766817995.png', 'active', 1, '2025-12-20 05:29:26'),
(22, 'Ram Sen', 'Ram@gmail.com', '9784561230', '$2y$10$G6W9.lCWgTEu5pWpY2dHi.NGnv/PRTZB894zCmp/nUDF47un9RyYK', 'Student Coordinator', 'Sports, Art, Tech', 'default_avatar.png', 'active', 1, '2025-12-20 12:02:42'),
(23, 'harvinder', 'hssaini@yahoo.com', '8096601222', '$2y$10$2FFc9fADifZNcWLmAHd.IuLjkr0TJOQ6hbxm/ihVERQchOWtP22zO', NULL, NULL, 'default_avatar.png', 'active', 1, '2026-01-11 00:23:29'),
(24, 'Vishal', 'walia.vishal@gmail.com', '9988994912', '$2y$10$SnYeXNQyG3PKNuOV4zTnnuS9ZWB0dIz0FxsoGTL9aQNg5yaQGZq1S', NULL, NULL, 'default_avatar.png', 'active', 1, '2026-01-12 01:42:56'),
(25, 'Rahul Kumar', 'rahul.kumar@college.edu', '9876543210', '$2y$10$4dbul0nZk8V1eDEu4n9HhOLcJbmkq5khtAHh0kFQVTCgssG4ENvay', 'Computer Science student passionate about AI and Machine Learning', 'IT/Tech, Coding, AI, Machine Learning', 'default_avatar.png', 'active', 1, '2026-01-26 12:40:23'),
(26, 'Dr. Amit Verma', 'amit.verma@college.edu', '9876543212', '$2y$10$5j2RWCYUNsBRP5nHzSXczejCzukOtquHbjN8dF9506L.654llD2qC', 'Professor of Computer Science with 15 years of teaching experience', 'Academic, Research, AI, Data Science', 'default_avatar.png', 'active', 0, '2026-01-26 12:41:14');

-- --------------------------------------------------------

--
-- Table structure for table `volunteers`
--

CREATE TABLE `volunteers` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` varchar(100) DEFAULT NULL,
  `status` enum('active','blocked') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `volunteers`
--

INSERT INTO `volunteers` (`id`, `event_id`, `user_id`, `role`, `status`) VALUES
(1, 1, 10, 'Tech Support Lead', 'active'),
(2, 2, 4, 'Stage Coordinator', 'active'),
(3, 2, 12, 'Backstage Manager', 'active'),
(4, 3, 9, 'Match Referee', 'active'),
(5, 3, 8, 'Water & Logistics', 'active'),
(6, 15, 6, 'Registration Desk', 'active'),
(7, 15, 14, 'Refreshment Team', 'active'),
(8, 13, 21, 'TEST', 'active'),
(9, 14, 21, 'Stage Manager', 'active'),
(10, 3, 21, 'Crowd Management', 'active');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `attendees`
--
ALTER TABLE `attendees`
  ADD PRIMARY KEY (`id`),
  ADD KEY `event_id` (`event_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_organizer` (`organizer_id`);

--
-- Indexes for table `event_status_log`
--
ALTER TABLE `event_status_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `event_id` (`event_id`);

--
-- Indexes for table `favorites`
--
ALTER TABLE `favorites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_fav` (`user_id`,`event_id`),
  ADD KEY `fk_fav_event` (`event_id`);

--
-- Indexes for table `participant`
--
ALTER TABLE `participant`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_participation` (`event_id`,`user_id`),
  ADD KEY `fk_users_participant` (`user_id`);

--
-- Indexes for table `student_faculty`
--
ALTER TABLE `student_faculty`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_student_faculty_users` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `phone` (`phone`);

--
-- Indexes for table `volunteers`
--
ALTER TABLE `volunteers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `event_id` (`event_id`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `attendees`
--
ALTER TABLE `attendees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `event_status_log`
--
ALTER TABLE `event_status_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `favorites`
--
ALTER TABLE `favorites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `participant`
--
ALTER TABLE `participant`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `student_faculty`
--
ALTER TABLE `student_faculty`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `volunteers`
--
ALTER TABLE `volunteers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendees`
--
ALTER TABLE `attendees`
  ADD CONSTRAINT `attendees_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendees_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `events`
--
ALTER TABLE `events`
  ADD CONSTRAINT `fk_organizer` FOREIGN KEY (`organizer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `event_status_log`
--
ALTER TABLE `event_status_log`
  ADD CONSTRAINT `event_status_log_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `favorites`
--
ALTER TABLE `favorites`
  ADD CONSTRAINT `fk_fav_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fav_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `participant`
--
ALTER TABLE `participant`
  ADD CONSTRAINT `fk_event_participant` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`),
  ADD CONSTRAINT `fk_users_participant` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `student_faculty`
--
ALTER TABLE `student_faculty`
  ADD CONSTRAINT `fk_student_faculty_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `volunteers`
--
ALTER TABLE `volunteers`
  ADD CONSTRAINT `volunteers_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `volunteers_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
