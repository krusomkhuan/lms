-- อัปเกรดจาก 1.4.x → 1.5.0 : PDPA (นโยบายความเป็นส่วนตัว ความยินยอม คำร้องใช้สิทธิ)
-- รันใน phpMyAdmin (แท็บ SQL) บนฐานข้อมูล vocational_lms
-- หลังอัปเกรด ผู้ใช้ทุกคนจะถูกขอให้ยอมรับนโยบายความเป็นส่วนตัวเมื่อเข้าสู่ระบบครั้งถัดไป

ALTER TABLE users
  ADD COLUMN consent_version VARCHAR(20) NULL AFTER status,
  ADD COLUMN consent_at DATETIME NULL AFTER consent_version;

CREATE TABLE IF NOT EXISTS consent_logs (
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

CREATE TABLE IF NOT EXISTS pdpa_requests (
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

INSERT IGNORE INTO settings (name, value) VALUES
('privacy_version', '1'),
('privacy_updated', CURDATE()),
('privacy_policy', ''),
('pdpa_org_name', ''),
('pdpa_contact', '');
UPDATE settings SET value = '1.5.0' WHERE name = 'db_version';
