-- Migration: Celebration Days — dates for app notifications (2026 & 2027)
-- Run after add_notification_tables.sql. No foreign keys.

CREATE TABLE IF NOT EXISTS `celebration_days` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `occasion_name` varchar(150) NOT NULL,
  `occasion_date` date NOT NULL,
  `is_fixed` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = same calendar date every year',
  `is_tentative` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = date may change (e.g. Eid)',
  `sort_order` int(11) DEFAULT NULL COMMENT 'Original S.No. from list',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_occasion_date` (`occasion_name`(100), `occasion_date`),
  KEY `occasion_date` (`occasion_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed: 21 occasions for 2026 and 2027. Safe to re-run: duplicate (occasion_name, occasion_date) are ignored.
INSERT IGNORE INTO `celebration_days` (`occasion_name`, `occasion_date`, `is_fixed`, `is_tentative`, `sort_order`) VALUES
-- 2026
('New Year\'s Day', '2026-01-01', 1, 0, 1),
('Bhogi', '2026-01-13', 0, 0, 2),
('Sankranti / Pongal', '2026-01-14', 0, 0, 3),
('Basant Panchami', '2026-01-23', 0, 0, 4),
('Republic Day', '2026-01-26', 1, 0, 5),
('Holi', '2026-03-03', 0, 0, 6),
('Ugadi', '2026-03-19', 0, 0, 7),
('International Women\'s Day', '2026-03-08', 1, 0, 14),
('Eid al-Fitr (tentative)', '2026-04-20', 0, 1, 20),
('International Yoga Day', '2026-06-21', 1, 0, 15),
('Eid al-Adha (Bakrid) (tentative)', '2026-06-28', 0, 1, 21),
('Independence Day', '2026-08-15', 1, 0, 8),
('Teachers\' Day (Dr. Radhakrishnan\'s Birthday)', '2026-09-05', 1, 0, 16),
('Bathukamma Festival', '2026-09-28', 0, 0, 9),
('Gandhi Jayanti', '2026-10-02', 1, 0, 10),
('Dussehra', '2026-10-22', 0, 0, 11),
('Children\'s Day (Nehru Jayanti)', '2026-11-14', 1, 0, 17),
('Diwali', '2026-11-14', 0, 0, 12),
('Constitution Day', '2026-11-26', 1, 0, 18),
('Human Rights Day', '2026-12-10', 1, 0, 19),
('Christmas', '2026-12-25', 1, 0, 13),
-- 2027
('New Year\'s Day', '2027-01-01', 1, 0, 1),
('Bhogi', '2027-01-13', 0, 0, 2),
('Sankranti / Pongal', '2027-01-14', 0, 0, 3),
('Basant Panchami', '2027-02-11', 0, 0, 4),
('Republic Day', '2027-01-26', 1, 0, 5),
('Holi', '2027-03-22', 0, 0, 6),
('Ugadi', '2027-04-08', 0, 0, 7),
('International Women\'s Day', '2027-03-08', 1, 0, 14),
('Eid al-Fitr (tentative)', '2027-04-09', 0, 1, 20),
('International Yoga Day', '2027-06-21', 1, 0, 15),
('Eid al-Adha (Bakrid) (tentative)', '2027-06-17', 0, 1, 21),
('Independence Day', '2027-08-15', 1, 0, 8),
('Teachers\' Day (Dr. Radhakrishnan\'s Birthday)', '2027-09-05', 1, 0, 16),
('Bathukamma Festival', '2027-10-17', 0, 0, 9),
('Gandhi Jayanti', '2027-10-02', 1, 0, 10),
('Dussehra', '2027-10-12', 0, 0, 11),
('Children\'s Day (Nehru Jayanti)', '2027-11-14', 1, 0, 17),
('Diwali', '2027-11-02', 0, 0, 12),
('Constitution Day', '2027-11-26', 1, 0, 18),
('Human Rights Day', '2027-12-10', 1, 0, 19),
('Christmas', '2027-12-25', 1, 0, 13);
