-- Run after 1.13.0.sql. MariaDB/MySQL, safe to repeat.
SET @lms_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='lessons' AND column_name='available_from')=0, 'ALTER TABLE lessons ADD available_from DATETIME NULL, ADD available_until DATETIME NULL', 'SELECT 1');
PREPARE lms_stmt FROM @lms_sql; EXECUTE lms_stmt; DEALLOCATE PREPARE lms_stmt;
SET @lms_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='lessons' AND column_name='submission_opens_at')=0, 'ALTER TABLE lessons ADD submission_opens_at DATETIME NULL, ADD submission_due_at DATETIME NULL, ADD accept_late TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE lms_stmt FROM @lms_sql; EXECUTE lms_stmt; DEALLOCATE PREPARE lms_stmt;
SET @lms_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='lesson_media' AND column_name='submission_opens_at')=0, 'ALTER TABLE lesson_media ADD submission_opens_at DATETIME NULL, ADD submission_due_at DATETIME NULL, ADD accept_late TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE lms_stmt FROM @lms_sql; EXECUTE lms_stmt; DEALLOCATE PREPARE lms_stmt;
SET @lms_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='assignment_submissions' AND column_name='is_late')=0, 'ALTER TABLE assignment_submissions ADD is_late TINYINT(1) NOT NULL DEFAULT 0, ADD due_at_snapshot DATETIME NULL', 'SELECT 1');
PREPARE lms_stmt FROM @lms_sql; EXECUTE lms_stmt; DEALLOCATE PREPARE lms_stmt;

CREATE TABLE IF NOT EXISTS course_grade_config (
 course_id INT UNSIGNED PRIMARY KEY,
 scale_json TEXT NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 0,
 FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS grade_items (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 course_id INT UNSIGNED NOT NULL,
 source_key VARCHAR(100) NOT NULL,
 kind ENUM('quiz','assignment','manual') NOT NULL,
 lesson_id INT UNSIGNED NULL,
 source_media_id INT UNSIGNED NOT NULL DEFAULT 0,
 title VARCHAR(200) NOT NULL,
 max_score DECIMAL(9,2) NOT NULL DEFAULT 100.00,
 weight DECIMAL(5,2) NOT NULL DEFAULT 0,
 attempt_policy ENUM('highest','latest') NOT NULL DEFAULT 'latest',
 UNIQUE KEY grade_source (course_id,source_key),
 FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
 FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS grade_manual_scores (
 item_id INT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 score DECIMAL(9,2) NOT NULL,
 note VARCHAR(1000) NULL,
 version INT UNSIGNED NOT NULL DEFAULT 0,
 updated_by INT UNSIGNED NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(item_id,user_id),
 FOREIGN KEY (item_id) REFERENCES grade_items(id) ON DELETE CASCADE,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS grade_runs (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 course_id INT UNSIGNED NOT NULL,
 group_name VARCHAR(150) NULL,
 plan_json MEDIUMTEXT NOT NULL,
 created_by INT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
 FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS grade_results (
 run_id INT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL,
 total DECIMAL(5,2) NOT NULL,
 grade_label VARCHAR(10) NOT NULL,
 snapshot_json MEDIUMTEXT NOT NULL,
 PRIMARY KEY(run_id,user_id),
 FOREIGN KEY (run_id) REFERENCES grade_runs(id) ON DELETE CASCADE,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
