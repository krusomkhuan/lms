-- Run after 1.16.3.sql. Collaborative activity boards (posts, uploads, comments, reactions,
-- teams, scoring, participation log) and course XP / levels. Safe to run again.
CREATE TABLE IF NOT EXISTS boards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id INT UNSIGNED NOT NULL,
  lesson_id INT UNSIGNED NULL,
  title VARCHAR(200) NOT NULL,
  description MEDIUMTEXT NULL,
  layout ENUM('wall','grid','columns','stream','timeline','table','freeform','map') NOT NULL DEFAULT 'wall',
  template VARCHAR(30) NOT NULL DEFAULT '',
  status ENUM('open','closed','archived') NOT NULL DEFAULT 'open',
  opens_at DATETIME NULL,
  due_at DATETIME NULL,
  closes_at DATETIME NULL,
  accept_late TINYINT(1) NOT NULL DEFAULT 1,
  require_approval TINYINT(1) NOT NULL DEFAULT 0,
  hide_until_reveal TINYINT(1) NOT NULL DEFAULT 0,
  revealed_at DATETIME NULL,
  allow_comments TINYINT(1) NOT NULL DEFAULT 1,
  reaction_mode ENUM('none','like','vote','star') NOT NULL DEFAULT 'like',
  student_role ENUM('contributor','commenter','viewer') NOT NULL DEFAULT 'contributor',
  group_mode ENUM('none','teams') NOT NULL DEFAULT 'none',
  team_visibility ENUM('own','all') NOT NULL DEFAULT 'all',
  is_assignment TINYINT(1) NOT NULL DEFAULT 0,
  max_points DECIMAL(7,2) NOT NULL DEFAULT 10.00,
  one_answer TINYINT(1) NOT NULL DEFAULT 0,
  rev INT UNSIGNED NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (course_id),
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS board_sections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  board_id INT UNSIGNED NOT NULL,
  title VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS board_teams (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  board_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS board_team_members (
  board_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (board_id, user_id),
  FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (team_id) REFERENCES board_teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS board_members (
  board_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role ENUM('moderator','contributor','commenter','viewer') NOT NULL,
  PRIMARY KEY (board_id, user_id),
  FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS board_posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  board_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NULL,
  section_id INT UNSIGNED NULL,
  title VARCHAR(200) NOT NULL DEFAULT '',
  body MEDIUMTEXT NULL,
  link_url VARCHAR(1000) NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  reject_note VARCHAR(500) NULL,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  pos_x INT NULL,
  pos_y INT NULL,
  lat DECIMAL(9,6) NULL,
  lng DECIMAL(9,6) NULL,
  is_late TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  deleted_at DATETIME NULL,
  INDEX (board_id, deleted_at),
  INDEX (user_id),
  FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (team_id) REFERENCES board_teams(id) ON DELETE SET NULL,
  FOREIGN KEY (section_id) REFERENCES board_sections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS board_post_revisions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL DEFAULT '',
  body MEDIUMTEXT NULL,
  link_url VARCHAR(1000) NULL,
  edited_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (post_id) REFERENCES board_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (edited_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS board_uploads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  board_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  kind ENUM('image','video','audio','document') NOT NULL,
  path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  size INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS board_attachments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id INT UNSIGNED NOT NULL,
  kind ENUM('image','video','audio','document') NOT NULL,
  path VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  size INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  FOREIGN KEY (post_id) REFERENCES board_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS board_comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  body VARCHAR(2000) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  FOREIGN KEY (post_id) REFERENCES board_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS board_reactions (
  post_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  value TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, user_id),
  FOREIGN KEY (post_id) REFERENCES board_posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS board_scores (
  board_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  score DECIMAL(7,2) NULL,
  feedback VARCHAR(2000) NULL,
  status ENUM('graded','revision') NOT NULL DEFAULT 'graded',
  graded_by INT UNSIGNED NULL,
  graded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (board_id, user_id),
  FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS board_activity (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  board_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  post_id INT UNSIGNED NULL,
  action VARCHAR(30) NOT NULL,
  detail VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (board_id, id),
  FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS xp_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  board_id INT UNSIGNED NULL,
  lesson_id INT UNSIGNED NULL,
  source VARCHAR(30) NOT NULL,
  ref VARCHAR(80) NOT NULL,
  points INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY xp_once (user_id, course_id, source, ref),
  INDEX (course_id, user_id),
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE SET NULL,
  FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Teachers choose whether students can see the course level leaderboard.
SET @has := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'courses' AND column_name = 'leaderboard_enabled');
SET @sql := IF(@has = 0, 'ALTER TABLE courses ADD COLUMN leaderboard_enabled TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Board scores flow into the course gradebook.
ALTER TABLE grade_items MODIFY COLUMN kind ENUM('quiz','assignment','manual','board') NOT NULL;
