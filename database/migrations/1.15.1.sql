-- Run after 1.15.0.sql. MariaDB/MySQL, safe to repeat.
-- บังคับเรียนบทเรียนตามลำดับ (ค่าเริ่มต้น: เปิด)
SET @lms_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='courses' AND column_name='sequential_lessons')=0, 'ALTER TABLE courses ADD sequential_lessons TINYINT(1) NOT NULL DEFAULT 1 AFTER ai_student_enabled', 'SELECT 1');
PREPARE lms_stmt FROM @lms_sql; EXECUTE lms_stmt; DEALLOCATE PREPARE lms_stmt;
