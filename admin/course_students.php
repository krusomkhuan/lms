<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$me = require_role('admin', 'teacher');
$courseId = get_int('course_id');
$course = db_one('SELECT * FROM courses WHERE id = ?', [$courseId]);
if (!$course || !can_manage_course($courseId)) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์จัดการรายวิชานี้');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (post('action') === 'add') {
        $n = 0;
        foreach ((array)post('user_ids', []) as $u) {
            $n += db_exec("INSERT IGNORE INTO enrollments (course_id, user_id) SELECT ?, id FROM users WHERE id = ? AND role = 'student'", [$courseId, (int)$u]);
        }
        flash('success', "เพิ่มผู้เรียน $n คน");
    } elseif (post('action') === 'remove') {
        db_exec('DELETE FROM enrollments WHERE course_id = ? AND user_id = ?', [$courseId, (int)post('user_id')]);
        flash('info', 'นำผู้เรียนออกจากรายวิชาแล้ว (หากอยู่ในกลุ่มที่ได้รับสิทธิ์ยังคงเข้าเรียนได้)');
    }
    redirect('admin/course_students.php?course_id=' . $courseId);
}

$total = (int)db_value('SELECT COUNT(*) FROM lessons WHERE course_id = ? AND is_published = 1', [$courseId]);
$students = db_all(
    "SELECT u.id, u.fullname, u.student_code, u.username,
        MAX(src = 'self') AS self_enrolled,
        GROUP_CONCAT(DISTINCT gname SEPARATOR ', ') AS group_names,
        (SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
          WHERE lp.user_id = u.id AND l.course_id = ? AND l.is_published = 1) AS done,
        (SELECT MAX(lp.completed_at) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
          WHERE lp.user_id = u.id AND l.course_id = ?) AS last_at
     FROM (
        SELECT user_id, 'self' AS src, NULL AS gname FROM enrollments WHERE course_id = ?
        UNION ALL
        SELECT gm.user_id, 'group', g.name FROM course_groups cg
          JOIN group_members gm ON gm.group_id = cg.group_id JOIN student_groups g ON g.id = cg.group_id
        WHERE cg.course_id = ?
     ) s JOIN users u ON u.id = s.user_id
     GROUP BY u.id ORDER BY u.student_code, u.fullname",
    [$courseId, $courseId, $courseId, $courseId]
);
$enrolledIds = array_column($students, 'id');
$candidates = db_all("SELECT id, fullname, student_code FROM users WHERE role = 'student' AND status = 'active' ORDER BY student_code, fullname");
$candidates = array_filter($candidates, fn($c) => !in_array($c['id'], $enrolledIds));

$pageTitle = 'ผู้เรียน: ' . $course['code'] . ' ' . $course['title'];
require __DIR__ . '/_top.php';
?>
<div class="d-flex justify-content-between mb-3">
    <a href="<?= url('admin/lessons.php?course_id=' . $courseId) ?>"><i class="bi bi-arrow-left"></i> กลับไปบทเรียน</a>
    <a href="<?= url('admin/course_form.php?id=' . $courseId) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-people"></i> กำหนดกลุ่มที่ได้รับสิทธิ์</a>
</div>
<a class="btn btn-outline-success mb-3" href="<?= url('admin/gradebook.php?course_id='.$courseId) ?>">สมุดคะแนนรายบุคคล / รายกลุ่ม / ตัดเกรด</a>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white"><b>ผู้เรียนทั้งหมด <?= count($students) ?> คน</b> <span class="small text-muted">· บทเรียนที่เผยแพร่ <?= $total ?> บท</span></div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light"><tr><th>รหัส</th><th>ชื่อ-นามสกุล</th><th>ที่มา</th><th style="width:200px">ความก้าวหน้า</th><th>เรียนล่าสุด</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($students as $s): $p = $total ? round($s['done'] * 100 / $total) : 0; ?>
                        <tr>
                            <td class="small"><?= e($s['student_code'] ?: '-') ?></td>
                            <td><?= e($s['fullname']) ?></td>
                            <td class="small">
                                <?php if ($s['self_enrolled']): ?><span class="badge bg-light text-dark border">ลงทะเบียนรายบุคคล</span><?php endif; ?>
                                <?php if ($s['group_names']): ?><span class="badge bg-primary-subtle text-primary"><?= e($s['group_names']) ?></span><?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height:8px"><div class="progress-bar <?= $p >= 100 ? 'bg-success' : '' ?>" style="width:<?= $p ?>%"></div></div>
                                    <span class="small text-nowrap"><?= (int)$s['done'] ?>/<?= $total ?></span>
                                </div>
                            </td>
                            <td class="small text-muted"><?= thai_date($s['last_at'], true) ?></td>
                            <td class="text-end">
                                <?php if ($s['self_enrolled']): ?>
                                    <form method="post" data-confirm="นำผู้เรียนออกจากรายวิชา?"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="user_id" value="<?= $s['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$students): ?><tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีผู้เรียน</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <form method="post" class="card border-0 shadow-sm rounded-4">
            <?= csrf_field() ?><input type="hidden" name="action" value="add">
            <div class="card-header bg-white"><b>เพิ่มผู้เรียนรายบุคคล</b></div>
            <div class="card-body">
                <select name="user_ids[]" class="form-select" multiple size="12">
                    <?php foreach ($candidates as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= e(($c['student_code'] ? $c['student_code'] . ' ' : '') . $c['fullname']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">กด Ctrl / Shift ค้างเพื่อเลือกหลายคน</div>
                <button class="btn btn-vt w-100 mt-2"><i class="bi bi-person-plus"></i> เพิ่มเข้ารายวิชา</button>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
