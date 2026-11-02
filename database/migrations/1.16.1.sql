-- Run after 1.16.0.sql. Keep legacy leave records without guessing the leave type.
ALTER TABLE attendance_records MODIFY COLUMN status ENUM('present','late','leave','absent','sick_leave','personal_leave') NOT NULL;
