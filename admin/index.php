<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$me = require_role('admin', 'teacher');
$uid = (int)$me['id'];
$isAdmin = $me['role'] === 'admin';

if ($isAdmin) {
    $stats = [
        ['รายวิชา', db_value('SELECT COUNT(*) FROM courses'), 'bi-journal-bookmark', 'primary', 'courses.php'],
        ['บทเรียน/สื่อ', db_value('SELECT COUNT(*) FROM lessons'), 'bi-collection-play', 'danger', 'courses.php'],
        ['ครูผู้สอน', db_value("SELECT COUNT(*) FROM users WHERE role='teacher'"), 'bi-person-video3', 'success', 'users.php?role=teacher'],
        ['ผู้เรียน', db_value("SELECT COUNT(*) FROM users WHERE role='student'"), 'bi-people', 'warning', 'users.php?role=student'],
        ['กลุ่มผู้เรียน', db_value('SELECT COUNT(*) FROM student_groups'), 'bi-diagram-3', 'info', 'groups.php'],
        ['รออนุมัติ', db_value("SELECT COUNT(*) FROM users WHERE status='pending'"), 'bi-hourglass-split', 'secondary', 'users.php?status=pending'],
    ];
    $courses = db_all(course_list_sql() . ' ORDER BY c.created_at DESC LIMIT 8');
    $newUsers = db_all('SELECT * FROM users ORDER BY created_at DESC LIMIT 6');
} else {
    $mine = '(SELECT course_id FROM course_teachers WHERE teacher_id = ?)';
    $stats = [
        ['รายวิชาที่สอน', db_value("SELECT COUNT(*) FROM course_teachers WHERE teacher_id = ?", [$uid]), 'bi-journal-bookmark', 'primary', 'courses.php'],
        ['บทเรียนของฉัน', db_value("SELECT COUNT(*) FROM lessons WHERE course_id IN $mine", [$uid]), 'bi-collection-play', 'danger', 'courses.php'],
        ['ผู้เรียนในวิชา', db_value("SELECT COUNT(DISTINCT u) FROM (SELECT user_id u FROM enrollments WHERE course_id IN $mine
              UNION SELECT gm.user_id FROM course_groups cg JOIN group_members gm ON gm.group_id = cg.group_id WHERE cg.course_id IN $mine) x", [$uid, $uid]), 'bi-people', 'warning', 'courses.php'],
        ['กลุ่มที่ฉันสอน', db_value('SELECT COUNT(*) FROM group_teachers WHERE teacher_id = ?', [$uid]), 'bi-diagram-3', 'info', 'groups.php'],
    ];
    $courses = db_all(course_list_sql() . " WHERE c.id IN $mine ORDER BY c.created_at DESC", [$uid]);
    $newUsers = [];
}

$pageTitle = 'แดชบอร์ด';
// ผู้ดูแล: เตือนเมื่อโฟลเดอร์อัปโหลดเขียนไม่ได้ (อัปโหลดรูปโปรไฟล์/ไฟล์บทเรียน/งานจะล้มเหลว)
$unwritable = [];
if ($me['role'] === 'admin') {
    foreach (['', 'avatars', 'covers', 'lessons', 'editor', 'submissions'] as $sub) {
        $dir = UPLOAD_PATH . ($sub !== '' ? '/' . $sub : '');
        if (is_dir($dir) ? !is_writable($dir) : $sub === '') $unwritable[] = 'uploads' . ($sub !== '' ? '/' . $sub : '');
    }
}
require __DIR__ . '/_top.php';
?>
<?php if ($unwritable): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-octagon-fill"></i> <b>อัปโหลดไฟล์ไม่ได้:</b> เว็บเซิร์ฟเวอร์ไม่มีสิทธิ์เขียนโฟลเดอร์ <?= e(implode(', ', $unwritable)) ?>
        — ตั้งสิทธิ์โฟลเดอร์เป็น 755 หรือ 775 (และเจ้าของเป็นผู้ใช้ของเว็บเซิร์ฟเวอร์) ผ่าน File Manager ของโฮสติ้งหรือ FTP</div>
<?php endif; ?>
<div class="row g-3 mb-4">
    <?php foreach ($stats as $s): ?>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="<?= url('admin/' . $s[4]) ?>" class="card stat-card shadow-sm text-reset h-100">
                <div class="card-body">
                    <div class="icon bg-<?= $s[3] ?>-subtle text-<?= $s[3] ?> mb-2"><i class="bi <?= $s[2] ?>"></i></div>
                    <div class="fs-3 fw-semibold"><?= number_format((int)$s[1]) ?></div>
                    <div class="small text-muted"><?= $s[0] ?></div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="<?= $isAdmin ? 'col-xl-8' : 'col-12' ?>">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <b><?= $isAdmin ? 'รายวิชาล่าสุด' : 'รายวิชาที่ฉันสอน' ?></b>
                <a href="<?= url('admin/course_form.php') ?>" class="btn btn-sm btn-vt"><i class="bi bi-plus-lg"></i> เพิ่มรายวิชา</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light"><tr><th>รหัส</th><th>รายวิชา</th><th>ผู้สอน</th><th class="text-center">บทเรียน</th><th>สถานะ</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($courses as $c): ?>
                        <tr>
                            <td><?= e($c['code']) ?></td>
                            <td><?= e($c['title']) ?></td>
                            <td class="small"><?= e($c['teachers']) ?></td>
                            <td class="text-center"><?= (int)$c['lesson_count'] ?></td>
                            <td><?= $c['status'] === 'published' ? '<span class="badge bg-success">เผยแพร่</span>' : '<span class="badge bg-secondary">ฉบับร่าง</span>' ?></td>
                            <td class="text-end"><a href="<?= url('admin/lessons.php?course_id=' . $c['id']) ?>" class="btn btn-sm btn-outline-primary">บทเรียน</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$courses): ?><tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีรายวิชา</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php if ($isAdmin): ?>
        <div class="col-xl-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white"><b>สมาชิกใหม่</b></div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($newUsers as $u): ?>
                        <li class="list-group-item d-flex align-items-center gap-2">
                            <img src="<?= e(avatar_url($u)) ?>" class="avatar" alt="">
                            <div class="flex-grow-1 small"><?= e($u['fullname']) ?><div class="text-muted"><?= thai_date($u['created_at'], true) ?></div></div>
                            <?= role_badge($u['role']) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
