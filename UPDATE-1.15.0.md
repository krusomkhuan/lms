# คลังข้อสอบ ก่อน–หลังเรียน แดชบอร์ด เรียนตามลำดับ และสำรองรายวิชา — 1.15.0

อัปเดตจากเวอร์ชัน 1.14.0

## ขั้นตอนอัปเดตเซิร์ฟเวอร์

1. สำรองข้อมูลก่อน: หลังบ้าน → สำรอง/กู้คืนข้อมูล → สร้างไฟล์สำรอง (ติ๊กรวมไฟล์อัปโหลด) แล้วดาวน์โหลดเก็บไว้
2. อัปโหลดไฟล์ตามรายการด้านล่างทับของเดิม (ยกเว้น `config/config.php`, `uploads/`, `backups/`)
3. รัน SQL ผ่าน phpMyAdmin (แท็บ SQL) **ตามลำดับ**: `database/migrations/1.15.0.sql` → `1.15.1.sql` → `1.15.2.sql` → `1.15.3.sql` → `1.15.4.sql` (รันซ้ำได้ ไม่กระทบข้อมูลเดิม)
4. เปิดเว็บ ท้ายเมนูหลังบ้านต้องแสดง **เวอร์ชัน 1.15.0** แล้วกด Ctrl+F5 เพื่อโหลดสคริปต์ใหม่

แบบทดสอบเดิมทั้งหมดใช้ได้ตามปกติ (เป็นแบบทดสอบทั่วไป ไม่สลับข้อ ทำได้ไม่จำกัด) รายวิชาเดิมทุกวิชาจะ **บังคับเรียนตามลำดับ** ทันทีหลังรัน 1.15.1.sql ปิดได้ที่หน้าแก้ไขรายวิชา

## ไฟล์ใหม่ — อัปโหลดเพิ่ม (17 ไฟล์)

- `admin/question_bank.php` — คลังข้อสอบ
- `admin/quiz_import.php` — รูปแบบการนำเข้าข้อสอบ / แม่แบบ
- `admin/prepost.php` — รายงานผลก่อน–หลังเรียน
- `admin/score_dashboard.php` — แดชบอร์ดคะแนน
- `admin/course_backup.php` — สำรอง/นำเข้ารายวิชา
- `includes/progress.php`
- `includes/course_backup.php`
- `includes/lesson_lock.php`
- `my-progress.php` — หน้าความก้าวหน้าของนักเรียน
- `student_preview.php` — มุมมองสำหรับผู้เรียน
- `assets/js/password-toggle.js` — ปุ่มรูปตาแสดง/ซ่อนรหัสผ่าน
- `assets/js/quiz-lockdown.js` — ป้องกันการออกจากหน้าสอบ
- `database/migrations/1.15.0.sql`
- `database/migrations/1.15.1.sql`
- `database/migrations/1.15.2.sql` — สื่อชนิด PowerPoint
- `database/migrations/1.15.3.sql` — สื่อชนิดแผนผังความคิด
- `database/migrations/1.15.4.sql` — รหัสลงทะเบียนเรียน

## ไฟล์เดิมที่แก้ไข — อัปโหลดทับ (28 ไฟล์)

- `admin/_bottom.php`
- `admin/_lesson_media_list.php`
- `admin/_quiz_builder.php`
- `admin/_top.php`
- `admin/course_form.php`
- `admin/courses.php`
- `admin/group_form.php`
- `admin/lesson_form.php`
- `admin/lessons.php`
- `admin/quiz_results.php`
- `admin/user_form.php`
- `assets/css/style.css`
- `assets/js/quiz-builder.js`
- `course.php`
- `lesson.php`
- `my-courses.php`
- `includes/bootstrap.php`
- `includes/footer.php`
- `includes/functions.php`
- `includes/gradebook.php`
- `includes/header.php`
- `includes/manual_data.php`
- `includes/quiz.php`
- `includes/quiz_submit.php`
- `includes/quiz_view.php`
- `includes/version.php`
- `database/lms.sql` (ใช้กับการติดตั้งใหม่เท่านั้น)
- `CHANGELOG.md`

## ไม่ต้องอัปโหลด

- `tests/` — ชุดทดสอบสำหรับเครื่องพัฒนา
- `database/boonta_lms.sql` — ไฟล์ส่งออกฐานข้อมูลจริง มีข้อมูลส่วนบุคคล ไม่ควรวางไว้บนเว็บ
