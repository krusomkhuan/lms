-- อัปเกรดจาก 1.2.x → 1.3.0 : กลุ่มผู้เรียนมีครูผู้สอนได้หลายคน
-- รันใน phpMyAdmin (แท็บ SQL) บนฐานข้อมูล vocational_lms
-- ถ้าชื่อ foreign key ไม่ใช่ student_groups_ibfk_1 ให้ดูได้จาก: SHOW CREATE TABLE student_groups;

CREATE TABLE IF NOT EXISTS group_teachers (
  group_id INT UNSIGNED NOT NULL,
  teacher_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (group_id, teacher_id),
  FOREIGN KEY (group_id) REFERENCES student_groups(id) ON DELETE CASCADE,
  FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ย้ายครูผู้สอนเดิม (1 คน/กลุ่ม) เข้าตารางใหม่
INSERT IGNORE INTO group_teachers (group_id, teacher_id)
  SELECT id, advisor_id FROM student_groups WHERE advisor_id IS NOT NULL;

ALTER TABLE student_groups DROP FOREIGN KEY student_groups_ibfk_1;
ALTER TABLE student_groups DROP COLUMN advisor_id;
UPDATE settings SET value = '1.3.0' WHERE name = 'db_version';
