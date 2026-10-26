-- อัปเกรดจาก 1.1.x → 1.2.0 : ตั้งค่า URL เว็บไซต์จากหลังบ้าน
-- รันใน phpMyAdmin (แท็บ SQL) บนฐานข้อมูล vocational_lms
-- และลบบรรทัด define('BASE_URL', ...) / define('UPLOAD_URL', ...) ออกจาก config/config.php (ดูตัวอย่างไฟล์ใหม่)

INSERT IGNORE INTO settings (name, value) VALUES ('site_url', '');
UPDATE settings SET value = '1.2.0' WHERE name = 'db_version';
