-- ระบบครูอาชีวะ (Vocational Teacher) — ครูสมควรออนไลน์
-- MySQL / MariaDB schema (utf8mb4)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS ai_logs, ai_drafts, pdpa_requests, consent_logs, lesson_progress, enrollments, course_groups, group_teachers, group_members,
  student_groups, lessons, course_teachers, courses, categories, users, settings;

SET FOREIGN_KEY_CHECKS = 1;

-- สมาชิกทั้งหมด: ผู้ดูแลระบบ / ครูผู้สอน / ผู้เรียน
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  fullname VARCHAR(150) NOT NULL,
  email VARCHAR(150) NULL,
  phone VARCHAR(30) NULL,
  student_code VARCHAR(30) NULL,
  role ENUM('admin','teacher','student') NOT NULL DEFAULT 'student',
  avatar VARCHAR(255) NULL,
  status ENUM('active','pending','banned') NOT NULL DEFAULT 'active',
  consent_version VARCHAR(20) NULL,   -- เวอร์ชันนโยบายความเป็นส่วนตัวที่ยอมรับล่าสุด (PDPA)
  consent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PDPA: บันทึกการให้/ปฏิเสธ/ถอนความยินยอม (หลักฐานตามกฎหมาย)
CREATE TABLE consent_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  username VARCHAR(50) NOT NULL,
  policy_version VARCHAR(20) NOT NULL,
  action ENUM('accept','decline','withdraw') NOT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PDPA: คำร้องใช้สิทธิของเจ้าของข้อมูล
CREATE TABLE pdpa_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  username VARCHAR(50) NOT NULL,
  fullname VARCHAR(150) NOT NULL,
  type ENUM('delete','correct','object','other') NOT NULL,
  detail TEXT NULL,
  status ENUM('pending','done','rejected') NOT NULL DEFAULT 'pending',
  admin_note TEXT NULL,
  handled_by INT UNSIGNED NULL,
  handled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- รายวิชา
CREATE TABLE courses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  credits VARCHAR(20) NULL,
  cover VARCHAR(255) NULL,
  enroll_type ENUM('open','group') NOT NULL DEFAULT 'open',
  status ENUM('published','draft') NOT NULL DEFAULT 'draft',
  ai_student_enabled TINYINT(1) NOT NULL DEFAULT 1,   -- เปิดผู้ช่วย AI ของผู้เรียนในวิชานี้
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ครูผู้สอนประจำรายวิชา (หลายคนต่อวิชา)
CREATE TABLE course_teachers (
  course_id INT UNSIGNED NOT NULL,
  teacher_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (course_id, teacher_id),
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- บทเรียน / สื่อการสอนหลายรูปแบบ
CREATE TABLE lessons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  media_type ENUM('text','youtube','video','pdf','file','image','link','audio') NOT NULL DEFAULT 'text',
  content MEDIUMTEXT NULL,
  media_url VARCHAR(500) NULL,
  file_path VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- กลุ่มผู้เรียน (เช่น ห้อง ปวช.1/1)
CREATE TABLE student_groups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  level VARCHAR(50) NULL,
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ครูผู้สอนประจำกลุ่ม (หลายคนต่อกลุ่ม)
CREATE TABLE group_teachers (
  group_id INT UNSIGNED NOT NULL,
  teacher_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (group_id, teacher_id),
  FOREIGN KEY (group_id) REFERENCES student_groups(id) ON DELETE CASCADE,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_members (
  group_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (group_id, user_id),
  FOREIGN KEY (group_id) REFERENCES student_groups(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- กลุ่มที่ได้รับสิทธิ์เรียนรายวิชา
CREATE TABLE course_groups (
  course_id INT UNSIGNED NOT NULL,
  group_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (course_id, group_id),
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY (group_id) REFERENCES student_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- การลงทะเบียนเรียนรายบุคคล
CREATE TABLE enrollments (
  course_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (course_id, user_id),
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ความก้าวหน้าการเรียน
CREATE TABLE lesson_progress (
  user_id INT UNSIGNED NOT NULL,
  lesson_id INT UNSIGNED NOT NULL,
  completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, lesson_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI: ร่างจาก AI ที่ครูต้องตรวจก่อนเผยแพร่
CREATE TABLE ai_drafts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  type ENUM('lesson','exercise','quiz','rubric','summary','improve') NOT NULL,
  title VARCHAR(200) NOT NULL,
  request TEXT NULL,
  content MEDIUMTEXT NULL,
  status ENUM('draft','published','discarded') NOT NULL DEFAULT 'draft',
  lesson_id INT UNSIGNED NULL,
  model VARCHAR(50) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI: บันทึกการใช้งาน (ไม่เก็บข้อความคำถาม/คำตอบ) ใช้คุมโควตาและดูค่าใช้จ่าย
CREATE TABLE ai_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  role VARCHAR(20) NOT NULL,
  feature VARCHAR(30) NOT NULL,
  course_id INT UNSIGNED NULL,
  model VARCHAR(50) NULL,
  input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  cache_read_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('ok','refusal','error') NOT NULL DEFAULT 'ok',
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ตั้งค่าเว็บไซต์
CREATE TABLE settings (
  name VARCHAR(50) PRIMARY KEY,
  value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (name, value) VALUES
('site_url', ''),
('site_name', 'Vocational Teacher'),
('site_slogan', 'ครูสมควรออนไลน์'),
('allow_register', '1'),
('require_approval', '0'),
('privacy_version', '1'),
('privacy_updated', CURDATE()),
('privacy_policy', ''),
('pdpa_org_name', ''),
('pdpa_contact', ''),
('ai_enabled', '0'),
('ai_api_key', ''),
('ai_model', 'claude-opus-5-5'),
('ai_teacher_enabled', '1'),
('ai_student_enabled', '1'),
('ai_teacher_daily_limit', '50'),
('ai_student_daily_limit', '30');
