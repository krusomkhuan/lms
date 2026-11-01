<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/course_backup.php';

$me = require_role('admin', 'teacher');
$courseId = get_int('course_id');
$course = $courseId ? db_one('SELECT * FROM courses WHERE id = ?', [$courseId]) : null;
if ($courseId && (!$course || !can_manage_course($courseId))) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์จัดการรายวิชานี้');
}
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (post('action') === 'export' && $course) {
            $path = course_backup_export($courseId, (bool)post('with_students'));
            $name = 'course-' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $course['code']) . '-' . date('Ymd-His') . '.zip';
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: private, no-store');
            readfile($path);
            unlink($path);
            exit;
        }
        if (post('action') === 'import') {
            $file = $_FILES['backup'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('กรุณาเลือกไฟล์สำรองรายวิชา (.zip) — ไฟล์ต้องไม่เกิน ' . ini_get('upload_max_filesize'));
            if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'zip') throw new RuntimeException('รองรับเฉพาะไฟล์ .zip ที่ได้จากปุ่มสำรองรายวิชา');
            $code = mb_substr(post('code'), 0, 30);
            $title = mb_substr(post('title'), 0, 200);
            if ($code === '' || $title === '') throw new RuntimeException('กรุณากรอกรหัสวิชาและชื่อรายวิชาใหม่');
            $newId = course_backup_import($file['tmp_name'], $me, $code, $title);
            flash('success', 'นำเข้ารายวิชาแล้ว (สถานะร่าง) — ตรวจสอบเนื้อหา กำหนดผู้เรียน แล้วเปลี่ยนเป็นเผยแพร่');
            redirect('admin/lessons.php?course_id=' . $newId);
        }
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    }
}

$pageTitle = $course ? 'สำรองรายวิชา: ' . $course['code'] . ' ' . $course['title'] : 'นำเข้ารายวิชาจากไฟล์สำรอง';
require __DIR__ . '/_top.php';
?>
<a href="<?= url($course ? 'admin/lessons.php?course_id=' . $courseId : 'admin/courses.php') ?>"><i class="bi bi-arrow-left"></i> <?= $course ? 'กลับไปบทเรียน' : 'รายวิชาทั้งหมด' ?></a>
<?php if ($error): ?><div class="alert alert-danger mt-3"><?= e($error) ?></div><?php endif; ?>
<div class="row g-3 mt-1">
    <?php if ($course): ?>
    <div class="col-lg-6"><div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body">
        <h2 class="h5"><i class="bi bi-download text-success"></i> ดาวน์โหลดไฟล์สำรองรายวิชา</h2>
        <p class="small text-muted">ได้ไฟล์ .zip เก็บไว้ในเครื่อง/Google Drive ประกอบด้วย</p>
        <ul class="small">
            <li>ข้อมูลรายวิชา บทเรียน สื่อ แบบทดสอบ (รวมการตั้งค่าก่อน–หลังเรียน) และคลังข้อสอบ</li>
            <li>ไฟล์ที่อัปโหลด: ภาพปก ไฟล์บทเรียน รูปในเนื้อหา</li>
            <li>สัดส่วนคะแนนและเกณฑ์เกรด</li>
        </ul>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="export">
            <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="with_students" value="1" id="withStudents" checked>
                <label class="form-check-label" for="withStudents">รวมข้อมูลผู้เรียน: คะแนนแบบทดสอบ งานที่ส่ง (พร้อมไฟล์) ความก้าวหน้า ผลเกรด และไฟล์ <code>scores.csv</code> สรุปคะแนนรายคน (เปิดด้วย Excel)</label></div>
            <button class="btn btn-vt"><i class="bi bi-file-earmark-zip"></i> ดาวน์โหลด .zip</button>
        </form>
        <p class="small text-muted mt-3 mb-0"><i class="bi bi-shield-lock"></i> ไฟล์ที่รวมข้อมูลผู้เรียนเป็นข้อมูลส่วนบุคคล (PDPA) เก็บรักษาอย่างปลอดภัย ไม่ส่งต่อผู้อื่น</p>
    </div></div></div>
    <?php endif; ?>
    <div class="col-lg-6"><div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body">
        <h2 class="h5"><i class="bi bi-upload text-primary"></i> นำเข้าเป็นรายวิชาใหม่</h2>
        <p class="small text-muted">ใช้คัดลอกรายวิชาไปสอนภาคเรียนใหม่ หรือย้ายรายวิชาจากเว็บอื่น ระบบจะสร้าง <b>รายวิชาใหม่ (สถานะร่าง)</b> โดยมีคุณเป็นครูผู้สอน นำเข้าเฉพาะเนื้อหา แบบทดสอบ คลังข้อสอบ และการตั้งค่าคะแนน — ไม่นำเข้าข้อมูลผู้เรียน และไม่แก้ไขรายวิชาเดิม</p>
        <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="import">
            <label class="form-label w-100">ไฟล์สำรองรายวิชา (.zip)<input type="file" name="backup" accept=".zip" class="form-control" required></label>
            <div class="row g-2">
                <div class="col-md-4"><label class="form-label w-100">รหัสวิชา<input name="code" maxlength="30" class="form-control" required value="<?= e($_POST['code'] ?? ($course['code'] ?? '')) ?>"></label></div>
                <div class="col-md-8"><label class="form-label w-100">ชื่อรายวิชาใหม่<input name="title" maxlength="200" class="form-control" required value="<?= e($_POST['title'] ?? ($course ? $course['title'] . ' (สำเนา)' : '')) ?>"></label></div>
            </div>
            <button class="btn btn-outline-primary"><i class="bi bi-box-arrow-in-down"></i> นำเข้า</button>
        </form>
    </div></div></div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
