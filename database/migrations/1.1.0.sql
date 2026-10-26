-- อัปเกรดจาก 1.0.0 → 1.1.0 : ตัดระบบหมวดหมู่/สาขาออก
-- รันใน phpMyAdmin (แท็บ SQL) บนฐานข้อมูล vocational_lms
-- ถ้าชื่อ foreign key ไม่ใช่ courses_ibfk_1 ให้ดูได้จาก: SHOW CREATE TABLE courses;

ALTER TABLE courses DROP FOREIGN KEY courses_ibfk_1;
ALTER TABLE courses DROP COLUMN category_id;
DROP TABLE IF EXISTS categories;
UPDATE settings SET value = '1.1.0' WHERE name = 'db_version';
