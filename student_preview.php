<?php
// เปิด/ปิดโหมด "มุมมองสำหรับผู้เรียน" ของ admin/ครู (เก็บใน session เท่านั้น)
require_once __DIR__ . '/includes/bootstrap.php';

require_role('admin', 'teacher');
if (($_GET['mode'] ?? '') === 'off') {
    unset($_SESSION['student_preview']);
    flash('success', 'ออกจากมุมมองสำหรับผู้เรียนแล้ว');
    redirect('admin/index.php');
}
$_SESSION['student_preview'] = true;
flash('info', 'กำลังดูเว็บไซต์ในมุมมองสำหรับผู้เรียน · ระบบจะไม่บันทึกข้อมูลใด ๆ ในโหมดนี้');
redirect('my-courses.php');
