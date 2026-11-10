-- Run after 1.16.1.sql. Lets teachers remove one group student from one course
-- while the student stays in the group and keeps every other course. Safe to run again.
CREATE TABLE IF NOT EXISTS course_exclusions (
  course_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  excluded_by INT UNSIGNED NULL,
  excluded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (course_id, user_id),
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (excluded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
