-- Run after 1.16.2.sql. Records which items in a lesson a student has opened, so
-- courses that require learning in order also lock the items inside each lesson. Safe to run again.
CREATE TABLE IF NOT EXISTS media_progress (
  user_id INT UNSIGNED NOT NULL,
  lesson_id INT UNSIGNED NOT NULL,
  media_id INT UNSIGNED NOT NULL DEFAULT 0,
  viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, lesson_id, media_id),
  INDEX (lesson_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
