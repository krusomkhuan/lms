-- เพิ่มสื่อได้หลายรายการต่อบทเรียน โดยเก็บสื่อเดิมไว้ใน lessons
CREATE TABLE IF NOT EXISTS lesson_media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lesson_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  media_type ENUM('text','youtube','video','pdf','file','image','link','audio') NOT NULL DEFAULT 'text',
  content MEDIUMTEXT NULL,
  media_url VARCHAR(500) NULL,
  file_path VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX lesson_media_order (lesson_id, sort_order, id),
  FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
