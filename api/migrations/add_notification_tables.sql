-- Migration: Add tables for FCM push notifications and organizer messaging
-- Run this against your college_event_db (or exdeos_college_event_db) database.

-- Store FCM device tokens per user (Android app registers token after login)
CREATE TABLE IF NOT EXISTS `user_fcm_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `fcm_token` varchar(255) NOT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_token` (`user_id`, `fcm_token`),
  KEY `user_id` (`user_id`),
  KEY `fcm_token` (`fcm_token`),
  CONSTRAINT `user_fcm_tokens_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Log of notifications sent by organizers to volunteers/participants
CREATE TABLE IF NOT EXISTS `organizer_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `organizer_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `recipient_type` enum('volunteers','participants','both') NOT NULL DEFAULT 'both',
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  KEY `organizer_id` (`organizer_id`),
  CONSTRAINT `organizer_notifications_event_fk` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `organizer_notifications_organizer_fk` FOREIGN KEY (`organizer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Optional: list of dates when the app should show/send notifications (e.g. reminder dates)
CREATE TABLE IF NOT EXISTS `notification_dates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) DEFAULT NULL,
  `notify_date` date NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `event_id` (`event_id`),
  KEY `notify_date` (`notify_date`),
  CONSTRAINT `notification_dates_event_fk` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
