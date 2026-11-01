-- Run after 1.12.0.sql. Existing media and scores are preserved.
ALTER TABLE lessons MODIFY media_type ENUM('text','youtube','video','pdf','file','image','link','audio','quiz','assignment') NOT NULL DEFAULT 'text';
ALTER TABLE lesson_media MODIFY media_type ENUM('text','youtube','video','pdf','file','image','link','audio','quiz','assignment') NOT NULL DEFAULT 'text';
SET @assignment_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'lessons' AND column_name = 'assignment_points') = 0,
  'ALTER TABLE lessons ADD COLUMN assignment_points DECIMAL(7,2) NOT NULL DEFAULT 100.00', 'SELECT 1');
PREPARE assignment_stmt FROM @assignment_sql;
EXECUTE assignment_stmt;
DEALLOCATE PREPARE assignment_stmt;
SET @assignment_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'lesson_media' AND column_name = 'assignment_points') = 0,
  'ALTER TABLE lesson_media ADD COLUMN assignment_points DECIMAL(7,2) NOT NULL DEFAULT 100.00', 'SELECT 1');
PREPARE assignment_stmt FROM @assignment_sql;
EXECUTE assignment_stmt;
DEALLOCATE PREPARE assignment_stmt;
CREATE TABLE IF NOT EXISTS assignment_submissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lesson_id INT UNSIGNED NOT NULL,
  media_id INT UNSIGNED NULL,
  source_media_id INT UNSIGNED NOT NULL DEFAULT 0,
  user_id INT UNSIGNED NOT NULL,
  assignment_title VARCHAR(200) NOT NULL,
  instructions MEDIUMTEXT NULL,
  max_score DECIMAL(7,2) NOT NULL,
  submission_text MEDIUMTEXT NULL,
  file_path VARCHAR(255) NULL,
  original_name VARCHAR(255) NULL,
  score DECIMAL(7,2) NULL,
  feedback TEXT NULL,
  status ENUM('submitted','graded','revision') NOT NULL DEFAULT 'submitted',
  graded_by INT UNSIGNED NULL,
  graded_at DATETIME NULL,
  grade_version INT UNSIGNED NOT NULL DEFAULT 0,
  submission_token CHAR(64) NOT NULL UNIQUE,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX assignment_history (lesson_id, source_media_id, user_id, id),
  FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
  FOREIGN KEY (media_id) REFERENCES lesson_media(id) ON DELETE SET NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
