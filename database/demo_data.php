<?php
// ข้อมูลตัวอย่าง — รหัสผ่านบัญชีตัวอย่างทั้งหมดคือ demo1234 (ดู README.md)
function install_demo_data(int $adminId): void
{
    $pass = password_hash('demo1234', PASSWORD_DEFAULT);

    $t1 = db_exec("INSERT INTO users (username,password,fullname,email,role) VALUES ('teacher1',?,'ครูสมควร ใจดี','teacher1@example.com','teacher')", [$pass]);
    $t2 = db_exec("INSERT INTO users (username,password,fullname,email,role) VALUES ('teacher2',?,'ครูสมศรี ตั้งใจสอน','teacher2@example.com','teacher')", [$pass]);

    $students = [];
    $names = ['สมชาย รักเรียน', 'สมหญิง ขยันดี', 'วิชัย ช่างคิด', 'มานี มีนา', 'ปิติ ยินดี', 'ชูใจ ใฝ่รู้'];
    foreach ($names as $i => $n) {
        $students[] = db_exec("INSERT INTO users (username,password,fullname,student_code,role) VALUES (?,?,?,?,'student')",
            ['student' . ($i + 1), $pass, $n, '6620100' . ($i + 1)]);
    }

    // กลุ่มผู้เรียน สาขาวิชาเทคโนโลยีธุรกิจดิจิทัล
    $groupIds = [];
    foreach (['ปวช.1', 'ปวช.2', 'ปวช.3', 'ปวส.1', 'ปวส.2'] as $level) {
        foreach ([1, 2] as $room) {
            $name = "$level/$room เทคโนโลยีธุรกิจดิจิทัล";
            $groupIds[$name] = db_exec('INSERT INTO student_groups (name,level,description) VALUES (?,?,?)',
                [$name, $level, 'สาขาวิชาเทคโนโลยีธุรกิจดิจิทัล']);
        }
    }
    $g1 = $groupIds['ปวช.1/1 เทคโนโลยีธุรกิจดิจิทัล'];
    $g2 = $groupIds['ปวส.1/1 เทคโนโลยีธุรกิจดิจิทัล'];
    // ครูผู้สอนประจำกลุ่ม (กลุ่มละหลายคนได้)
    db_exec('INSERT INTO group_teachers VALUES (?,?),(?,?),(?,?)', [$g1, $t1, $g1, $t2, $g2, $t2]);
    foreach (array_slice($students, 0, 4) as $s) db_exec('INSERT INTO group_members VALUES (?,?)', [$g1, $s]);
    foreach (array_slice($students, 4) as $s) db_exec('INSERT INTO group_members VALUES (?,?)', [$g2, $s]);

    $c1 = db_exec("INSERT INTO courses (code,title,description,credits,enroll_type,status,created_by)
        VALUES ('IT-101','การเขียนโปรแกรมเว็บเบื้องต้น','เรียนรู้ HTML, CSS และ PHP เพื่อสร้างเว็บไซต์แบบไดนามิก เหมาะสำหรับผู้เริ่มต้น','3 (1-4-3)','group','published',?)", [$adminId]);
    $c2 = db_exec("INSERT INTO courses (code,title,description,credits,enroll_type,status,created_by)
        VALUES ('EL-201','วงจรไฟฟ้ากระแสตรง','หลักการพื้นฐานของวงจรไฟฟ้า กฎของโอห์ม และการวัดค่าทางไฟฟ้า','2 (1-2-2)','open','published',?)", [$adminId]);
    $c3 = db_exec("INSERT INTO courses (code,title,description,credits,enroll_type,status,created_by)
        VALUES ('GE-001','ทักษะการเรียนรู้ด้วยตนเองออนไลน์','วิชาเปิดสำหรับผู้เรียนทุกคน แนะนำวิธีใช้งานระบบและการเรียนออนไลน์อย่างมีประสิทธิภาพ','1 (0-2-1)','open','published',?)", [$adminId]);

    db_exec('INSERT INTO course_teachers VALUES (?,?),(?,?),(?,?),(?,?)', [$c1, $t1, $c1, $t2, $c2, $t2, $c3, $t1]);
    db_exec('INSERT INTO course_groups VALUES (?,?),(?,?)', [$c1, $g1, $c1, $g2]);
    db_exec('INSERT INTO enrollments (course_id,user_id) VALUES (?,?)', [$c3, $students[0]]);

    $lessons = [
        [$c1, 'บทที่ 1 รู้จักกับเว็บไซต์และ HTML', 'text', '<p>เว็บไซต์ประกอบด้วยหน้าเว็บหลายหน้าที่เขียนด้วยภาษา <b>HTML</b> ซึ่งเป็นภาษาสำหรับกำหนดโครงสร้างเนื้อหา</p><ul><li>HTML — โครงสร้าง</li><li>CSS — การตกแต่ง</li><li>JavaScript / PHP — การทำงาน</li></ul>', null],
        [$c1, 'บทที่ 2 วิดีโอสอน HTML Crash Course', 'youtube', '<p>ชมวิดีโอแล้วสรุปแท็กที่ได้เรียนรู้อย่างน้อย 10 แท็ก</p>', 'https://www.youtube.com/watch?v=UB1O30fR-EE'],
        [$c1, 'บทที่ 3 แหล่งเรียนรู้ HTML (MDN)', 'link', '<p>ศึกษาเอกสารอ้างอิงเพิ่มเติมจาก MDN Web Docs</p>', 'https://developer.mozilla.org/th/docs/Learn/HTML'],
        [$c2, 'บทที่ 1 กฎของโอห์ม', 'text', '<p>กฎของโอห์มกล่าวว่า <b>V = I × R</b></p><p>แรงดันไฟฟ้า (V) เท่ากับกระแส (I) คูณความต้านทาน (R)</p>', null],
        [$c3, 'แนะนำการใช้งานระบบ ครูสมควรออนไลน์', 'text', '<p>ยินดีต้อนรับสู่ระบบ! เลือกบทเรียนทางซ้ายมือ ศึกษาเนื้อหา แล้วกด <b>"เรียนจบบทนี้"</b> เพื่อบันทึกความก้าวหน้า</p>', null],
    ];
    foreach ($lessons as $i => $l) {
        db_exec('INSERT INTO lessons (course_id,title,media_type,content,media_url,sort_order) VALUES (?,?,?,?,?,?)',
            [$l[0], $l[1], $l[2], $l[3], $l[4], $i + 1]);
    }
}
