CREATE TABLE `scimath_answer_slides` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int(10) unsigned NOT NULL,
  `question_id` int(10) unsigned DEFAULT NULL,
  `image_path` varchar(255) NOT NULL,
  `display_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_answer_event` (`event_id`,`display_order`)
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `scimath_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int(10) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `display_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_event_name` (`event_id`,`name`),
  KEY `idx_categories_event_order` (`event_id`,`display_order`),
  CONSTRAINT `fk_categories_event` FOREIGN KEY (`event_id`) REFERENCES `scimath_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `scimath_competition_sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int(10) unsigned NOT NULL,
  `current_question_id` int(10) unsigned DEFAULT NULL,
  `display_state` enum('cover','question','time_up','ranking','final_results') NOT NULL DEFAULT 'cover',
  `current_round` smallint(5) unsigned DEFAULT NULL,
  `timer_duration_seconds` smallint(5) unsigned DEFAULT NULL,
  `timer_started_at` timestamp NULL DEFAULT NULL,
  `timer_paused_at` timestamp NULL DEFAULT NULL,
  `timer_remaining_seconds` smallint(6) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `round_started_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_competition_sessions_event` (`event_id`),
  KEY `fk_competition_sessions_question` (`current_question_id`),
  CONSTRAINT `fk_competition_sessions_event` FOREIGN KEY (`event_id`) REFERENCES `scimath_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_competition_sessions_question` FOREIGN KEY (`current_question_id`) REFERENCES `scimath_questions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `scimath_contestants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int(10) unsigned NOT NULL,
  `name` varchar(150) NOT NULL,
  `team_code` varchar(20) DEFAULT NULL,
  `acronym` varchar(20) DEFAULT NULL,
  `organization` varchar(150) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `starting_score` int(11) NOT NULL DEFAULT 0,
  `display_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_clincher` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contestants_event_code` (`event_id`,`team_code`),
  KEY `idx_contestants_event_order` (`event_id`,`display_order`),
  KEY `idx_scimath_contestants_event_clincher` (`event_id`,`is_clincher`),
  CONSTRAINT `fk_contestants_event` FOREIGN KEY (`event_id`) REFERENCES `scimath_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `scimath_event_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int(10) unsigned NOT NULL,
  `default_points` int(10) unsigned NOT NULL DEFAULT 10,
  `default_time_seconds` smallint(5) unsigned NOT NULL DEFAULT 60,
  `ranking_order` enum('score_desc','score_asc') NOT NULL DEFAULT 'score_desc',
  `tie_break_mode` enum('none') NOT NULL DEFAULT 'none',
  `auto_show_ranking_after_score` tinyint(1) NOT NULL DEFAULT 0,
  `timer_warning_seconds` smallint(5) unsigned DEFAULT NULL,
  `display_theme` varchar(50) NOT NULL DEFAULT 'default',
  `extra_settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extra_settings`)),
  `timer_audio_path` varchar(255) DEFAULT NULL,
  `banner_audio_path` varchar(255) DEFAULT NULL,
  `game_audio_path` varchar(255) DEFAULT NULL,
  `banner_audio_volume` tinyint(3) unsigned NOT NULL DEFAULT 100,
  `game_audio_volume` tinyint(3) unsigned NOT NULL DEFAULT 30,
  `times_up_image_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_settings_event_id` (`event_id`),
  CONSTRAINT `fk_event_settings_event` FOREIGN KEY (`event_id`) REFERENCES `scimath_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `scimath_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `cover_image_path` varchar(255) DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `status` enum('draft','ready','active','completed','archived') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_events_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `scimath_promotional_slides` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int(10) unsigned NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `description` text DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` enum('image','video','pdf','document') NOT NULL DEFAULT 'image',
  `display_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_promo_event_order` (`event_id`,`display_order`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `scimath_questions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int(10) unsigned NOT NULL,
  `category_id` int(10) unsigned DEFAULT NULL,
  `round` enum('easy','medium','hard','clincher') NOT NULL DEFAULT 'easy',
  `question_number` smallint(5) unsigned NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `answer_image_path` varchar(255) DEFAULT NULL,
  `points` int(10) unsigned DEFAULT NULL,
  `time_seconds` smallint(5) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `display_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_questions_event_number` (`event_id`,`question_number`),
  KEY `idx_questions_event_order` (`event_id`,`display_order`),
  KEY `idx_questions_category` (`category_id`),
  KEY `idx_scimath_questions_event_round` (`event_id`,`round`),
  CONSTRAINT `fk_questions_category` FOREIGN KEY (`category_id`) REFERENCES `scimath_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_questions_event` FOREIGN KEY (`event_id`) REFERENCES `scimath_events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `scimath_score_adjustments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `score_entry_id` int(10) unsigned NOT NULL,
  `previous_result` enum('correct','incorrect','no_answer','adjustment') DEFAULT NULL,
  `previous_points` int(11) DEFAULT NULL,
  `new_result` enum('correct','incorrect','no_answer','adjustment') NOT NULL,
  `new_points` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `changed_by` varchar(100) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_score_adjustments_entry` (`score_entry_id`),
  CONSTRAINT `fk_score_adjustments_entry` FOREIGN KEY (`score_entry_id`) REFERENCES `scimath_score_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `scimath_score_entries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int(10) unsigned NOT NULL,
  `question_id` int(10) unsigned NOT NULL,
  `contestant_id` int(10) unsigned NOT NULL,
  `result` enum('correct','incorrect','no_answer','adjustment') NOT NULL,
  `points_awarded` int(11) NOT NULL,
  `scored_by` varchar(100) DEFAULT NULL,
  `scored_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_score_entries_question_contestant` (`question_id`,`contestant_id`),
  KEY `idx_score_entries_event` (`event_id`),
  KEY `idx_score_entries_contestant` (`contestant_id`),
  CONSTRAINT `fk_score_entries_contestant` FOREIGN KEY (`contestant_id`) REFERENCES `scimath_contestants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_score_entries_event` FOREIGN KEY (`event_id`) REFERENCES `scimath_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_score_entries_question` FOREIGN KEY (`question_id`) REFERENCES `scimath_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;