-- Run after 1.15.3.sql. Enrollment code per course (NULL = use the course code). Safe to repeat.
SET @lms_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='courses' AND column_name='enroll_key')=0, 'ALTER TABLE courses ADD enroll_key VARCHAR(50) NULL AFTER enroll_type', 'SELECT 1');
PREPARE lms_stmt FROM @lms_sql; EXECUTE lms_stmt; DEALLOCATE PREPARE lms_stmt;
