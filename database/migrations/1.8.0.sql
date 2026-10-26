-- อัปเกรดจาก 1.7.x → 1.8.0 : โมดูลเสริม AI (ผู้ช่วยครู / ผู้ช่วยผู้เรียน)
-- รันใน phpMyAdmin (แท็บ SQL) บนฐานข้อมูล vocational_lms

-- ครูเปิด/ปิดผู้ช่วย AI ของผู้เรียนได้รายวิชา (เช่น ปิดช่วงสอบ)
ALTER TABLE courses ADD COLUMN ai_student_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER status;

-- ร่างจาก AI ที่ครูต้องตรวจก่อนเผยแพร่
CREATE TABLE IF NOT EXISTS ai_drafts (
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

-- บันทึกการใช้งาน AI (ไม่เก็บข้อความคำถาม/คำตอบ) ใช้คุมโควตาและดูค่าใช้จ่าย
CREATE TABLE IF NOT EXISTS ai_logs (
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

INSERT IGNORE INTO settings (name, value) VALUES
('ai_enabled', '0'),
('ai_api_key', ''),
('ai_model', 'claude-opus-5-5'),
('ai_teacher_enabled', '1'),
('ai_student_enabled', '1'),
('ai_teacher_daily_limit', '50'),
('ai_student_daily_limit', '30');
UPDATE settings SET value = '1.8.0' WHERE name = 'db_version';
