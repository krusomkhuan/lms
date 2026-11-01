<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$me = require_role('admin', 'teacher');
$uid = (int)$me['id'];
$isAdmin = $me['role'] === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = (int)post('id');
    if (post('action') === 'delete' && can_manage_course($id)) {
        $course = db_one('SELECT cover FROM courses WHERE id = ?', [$id]);
        $files = db_all('SELECT file_path FROM lessons WHERE course_id = ?', [$id]);
        if (lesson_media_ready()) {
            $files = array_merge($files, db_all('SELECT m.file_path FROM lesson_media m JOIN lessons l ON l.id = m.lesson_id WHERE l.course_id = ?', [$id]));
        }
        if (assignments_ready()) $files = array_merge($files, db_all('SELECT s.file_path FROM assignment_submissions s JOIN lessons l ON l.id = s.lesson_id WHERE l.course_id = ?', [$id]));
        db_exec('DELETE FROM courses WHERE id = ?', [$id]);
        foreach ($files as $file) delete_upload($file['file_path']);
        delete_upload($course['cover'] ?? null);
        flash('success', 'ลบรายวิชาเรียบร้อย');
    } elseif (post('action') === 'auto_covers') {
        // สร้างภาพปกอัตโนมัติให้รายวิชาที่ยังไม่มีภาพปก (ครู: เฉพาะวิชาที่สอน)
        require_once ROOT_PATH . '/includes/cover.php';
        $n = 0;
        foreach (db_all("SELECT id, code, title, description FROM courses WHERE cover IS NULL OR cover = ''") as $c) {
            if (can_manage_course((int)$c['id'])) {
                db_exec('UPDATE courses SET cover = ? WHERE id = ?', [cover_generate((int)$c['id'], $c['code'], $c['title'], (string)$c['description']), (int)$c['id']]);
                $n++;
            }
        }
        flash($n ? 'success' : 'info', $n ? "สร้างภาพปกอัตโนมัติให้ $n รายวิชา" : 'ทุกรายวิชามีภาพปกแล้ว');
    } elseif (post('action') === 'toggle' && can_manage_course($id)) {
        db_exec("UPDATE courses SET status = IF(status = 'published', 'draft', 'published') WHERE id = ?", [$id]);
        flash('success', 'เปลี่ยนสถานะรายวิชาแล้ว');
    }
    redirect('admin/courses.php');
}

$q = trim($_GET['q'] ?? '');
$where = [];
$params = [];
if (!$isAdmin) {
    $where[] = 'c.id IN (SELECT course_id FROM course_teachers WHERE teacher_id = ?)';
    $params[] = $uid;
}
if ($q !== '') {
    $where[] = '(c.title LIKE ? OR c.code LIKE ?)';
    array_push($params, "%$q%", "%$q%");
}
$courses = db_all(course_list_sql() . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY c.code', $params);

$pageTitle = $isAdmin ? 'จัดการรายวิชา' : 'รายวิชาที่ฉันสอน';
require __DIR__ . '/_top.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between mb-3">
    <form class="d-flex gap-2">
        <input name="q" class="form-control" placeholder="ค้นหารหัส / ชื่อวิชา" value="<?= e($q) ?>">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    </form>
    <div class="d-flex gap-2">
        <?php if (array_filter($courses, fn($c) => empty($c['cover']))): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="auto_covers">
                <button class="btn btn-outline-primary"><i class="bi bi-image"></i> สร้างภาพปกให้วิชาที่ยังไม่มี</button></form>
        <?php endif; ?>
        <a href="<?= url('admin/course_backup.php') ?>" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-in-down"></i> นำเข้ารายวิชา</a>
        <a href="<?= url('admin/course_form.php') ?>" class="btn btn-vt"><i class="bi bi-plus-lg"></i> เพิ่มรายวิชา</a>
    </div>
</div>
<div class="card border-0 shadow-sm rounded-4">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
            <tr><th style="width:90px"></th><th>รหัส / รายวิชา</th><th>ครูผู้สอน</th><th class="text-center">บทเรียน</th><th>การลงทะเบียน</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr>
            </thead>
            <tbody>
            <?php foreach ($courses as $c): ?>
                <tr>
                    <td><img src="<?= e(course_cover($c)) ?>" class="rounded" style="width:80px;height:45px;object-fit:cover" alt=""></td>
                    <td><div class="small text-muted"><?= e($c['code']) ?></div><a href="<?= url('course.php?id=' . $c['id']) ?>" target="_blank" class="fw-semibold"><?= e($c['title']) ?></a></td>
                    <td class="small"><?= e($c['teachers'] ?: '-') ?></td>
                    <td class="text-center"><?= (int)$c['lesson_count'] ?></td>
                    <td class="small"><?= $c['enroll_type'] === 'open' ? '<i class="bi bi-unlock text-success"></i> เปิดทั่วไป' : '<i class="bi bi-people text-primary"></i> เฉพาะกลุ่ม' ?></td>
                    <td>
                        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button class="btn btn-sm badge border-0 <?= $c['status'] === 'published' ? 'bg-success' : 'bg-secondary' ?>" title="คลิกเพื่อสลับสถานะ"><?= $c['status'] === 'published' ? 'เผยแพร่' : 'ฉบับร่าง' ?></button>
                        </form>
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="<?= url('admin/lessons.php?course_id=' . $c['id']) ?>" class="btn btn-sm btn-primary" title="บทเรียน/สื่อ"><i class="bi bi-collection-play"></i></a>
                        <a href="<?= url('admin/course_students.php?course_id=' . $c['id']) ?>" class="btn btn-sm btn-warning" title="ผู้เรียน/ความก้าวหน้า"><i class="bi bi-people"></i></a>
                        <a href="<?= url('admin/course_backup.php?course_id=' . $c['id']) ?>" class="btn btn-sm btn-outline-secondary" title="สำรอง/คัดลอกรายวิชา"><i class="bi bi-file-earmark-zip"></i></a>
                        <?php if (setting('ai_enabled') === '1' && setting('ai_teacher_enabled', '1') === '1'): ?>
                            <a href="<?= url('admin/ai_teacher.php?course_id=' . $c['id']) ?>" class="btn btn-sm btn-outline-primary" title="ผู้ช่วยสอน AI"><i class="bi bi-stars"></i></a>
                        <?php endif; ?>
                        <a href="<?= url('admin/course_form.php?id=' . $c['id']) ?>" class="btn btn-sm btn-outline-secondary" title="แก้ไข"><i class="bi bi-pencil"></i></a>
                        <form method="post" class="d-inline" data-confirm="ลบรายวิชานี้และบทเรียนทั้งหมด?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$courses): ?><tr><td colspan="7" class="text-center text-muted py-5">ยังไม่มีรายวิชา</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
