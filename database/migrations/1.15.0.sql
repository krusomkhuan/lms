-- Run after 1.14.0.sql. MariaDB/MySQL, safe to repeat.
CREATE TABLE IF NOT EXISTS question_bank (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id INT UNSIGNED NOT NULL,
  category VARCHAR(100) NOT NULL,
  question_text TEXT NOT NULL,
  options_json TEXT NOT NULL,
  correct TINYINT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX (course_id, category),
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
