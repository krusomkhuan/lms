-- Run after 1.10.0.sql. Existing download media are preserved.
ALTER TABLE lessons MODIFY media_type ENUM('text','youtube','video','pdf','file','image','link','audio','quiz') NOT NULL DEFAULT 'text';
ALTER TABLE lesson_media MODIFY media_type ENUM('text','youtube','video','pdf','file','image','link','audio','quiz') NOT NULL DEFAULT 'text';
SET @quiz_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'lessons' AND column_name = 'quiz_data') = 0,
  'ALTER TABLE lessons ADD COLUMN quiz_data MEDIUMTEXT NULL', 'SELECT 1');
PREPARE quiz_column_stmt FROM @quiz_column_sql;
EXECUTE quiz_column_stmt;
DEALLOCATE PREPARE quiz_column_stmt;
SET @quiz_column_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'lesson_media' AND column_name = 'quiz_data') = 0,
  'ALTER TABLE lesson_media ADD COLUMN quiz_data MEDIUMTEXT NULL', 'SELECT 1');
PREPARE quiz_column_stmt FROM @quiz_column_sql;
EXECUTE quiz_column_stmt;
DEALLOCATE PREPARE quiz_column_stmt;
CREATE TABLE IF NOT EXISTS quiz_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lesson_id INT UNSIGNED NOT NULL,
  media_id INT UNSIGNED NULL,
  source_media_id INT UNSIGNED NOT NULL DEFAULT 0,
  user_id INT UNSIGNED NOT NULL,
  quiz_title VARCHAR(200) NOT NULL,
  score INT UNSIGNED NOT NULL,
  total INT UNSIGNED NOT NULL,
  answers MEDIUMTEXT NOT NULL,
  quiz_snapshot MEDIUMTEXT NOT NULL,
  submission_token CHAR(64) NOT NULL UNIQUE,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (lesson_id, user_id),
  FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
  FOREIGN KEY (media_id) REFERENCES lesson_media(id) ON DELETE SET NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
