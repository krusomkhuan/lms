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
    $lid = (int)post('id');
    $lesson = db_one('SELECT * FROM lessons WHERE id = ? AND course_id = ?', [$lid, $courseId]);
    if ($lesson) {
        switch (post('action')) {
            case 'delete':
                $mediaFiles = lesson_media_ready() ? db_all('SELECT file_path FROM lesson_media WHERE lesson_id = ?', [$lid]) : [];
                if (assignments_ready()) $mediaFiles = array_merge($mediaFiles, db_all('SELECT file_path FROM assignment_submissions WHERE lesson_id = ?', [$lid]));
                db_exec('DELETE FROM lessons WHERE id = ?', [$lid]);
                delete_upload($lesson['file_path']);
                foreach ($mediaFiles as $mediaFile) delete_upload($mediaFile['file_path']);
                flash('success', 'ลบบทเรียนแล้ว');
                break;
            case 'toggle':
                db_exec('UPDATE lessons SET is_published = 1 - is_published WHERE id = ?', [$lid]);
                break;
            case 'up':
            case 'down':
                // จัดลำดับใหม่ทั้งชุดแล้วสลับตำแหน่ง
                $ids = array_column(db_all('SELECT id FROM lessons WHERE course_id = ? ORDER BY sort_order, id', [$courseId]), 'id');
                $i = array_search($lid, $ids);
                $j = post('action') === 'up' ? $i - 1 : $i + 1;
                if ($i !== false && isset($ids[$j])) {
                    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                }
                foreach ($ids as $n => $x) db_exec('UPDATE lessons SET sort_order = ? WHERE id = ?', [$n + 1, (int)$x]);
                break;
        }
    }
    redirect('admin/lessons.php?course_id=' . $courseId);
}

$lessons = db_all('SELECT l.*, (SELECT COUNT(*) FROM lesson_progress p WHERE p.lesson_id = l.id) AS done_count
                   FROM lessons l WHERE l.course_id = ? ORDER BY l.sort_order, l.id', [$courseId]);
$types = media_types();

$pageTitle = 'บทเรียน: ' . $course['code'] . ' ' . $course['title'];
require __DIR__ . '/_top.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <a href="<?= url('admin/courses.php') ?>"><i class="bi bi-arrow-left"></i> รายวิชาทั้งหมด</a>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= url('admin/score_dashboard.php?course_id=' . $courseId) ?>" class="btn btn-outline-success"><i class="bi bi-bar-chart-line"></i> แดชบอร์ดคะแนน</a>
        <a href="<?= url('admin/gradebook.php?course_id=' . $courseId) ?>" class="btn btn-outline-success">สมุดคะแนน / ตัดเกรด</a>
        <a href="<?= url('admin/question_bank.php?course_id=' . $courseId) ?>" class="btn btn-outline-primary"><i class="bi bi-archive"></i> คลังข้อสอบ</a>
        <a href="<?= url('admin/course_backup.php?course_id=' . $courseId) ?>" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-zip"></i> สำรองรายวิชา</a>
        <a href="<?= url('admin/prepost.php?course_id=' . $courseId) ?>" class="btn btn-outline-success"><i class="bi bi-graph-up-arrow"></i> ผลก่อน–หลังเรียน</a>
        <a href="<?= url('course.php?id=' . $courseId) ?>" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-eye"></i> ดูหน้าบ้าน</a>
        <a href="<?= url('admin/course_students.php?course_id=' . $courseId) ?>" class="btn btn-outline-warning"><i class="bi bi-people"></i> ผู้เรียน</a>
        <?php if (setting('ai_enabled') === '1' && setting('ai_teacher_enabled', '1') === '1'): ?>
            <a href="<?= url('admin/ai_teacher.php?course_id=' . $courseId) ?>" class="btn btn-outline-primary"><i class="bi bi-stars"></i> ผู้ช่วยสอน AI</a>
        <?php endif; ?>
        <a href="<?= url('admin/lesson_form.php?course_id=' . $courseId) ?>" class="btn btn-vt"><i class="bi bi-plus-lg"></i> เพิ่มบทเรียน/สื่อ</a>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr><th style="width:60px">ลำดับ</th><th>บทเรียน</th><th>ชนิดสื่อ</th><th class="text-center">เรียนจบ (คน)</th><th>แสดงผล</th><th class="text-end">จัดการ</th></tr></thead>
            <tbody>
            <?php foreach ($lessons as $n => $l): $t = $types[$l['media_type']]; ?>
                <tr>
                    <td class="text-nowrap">
                        <?php foreach (['up' => 'bi-arrow-up', 'down' => 'bi-arrow-down'] as $dir => $icon): ?>
                            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $l['id'] ?>">
                                <button name="action" value="<?= $dir ?>" class="btn btn-sm btn-link p-0 text-secondary" <?= ($dir === 'up' && $n === 0) || ($dir === 'down' && $n === count($lessons) - 1) ? 'disabled' : '' ?>><i class="bi <?= $icon ?>"></i></button></form>
                        <?php endforeach; ?>
                        <span class="ms-1"><?= $n + 1 ?></span>
                    </td>
                    <td><a href="<?= url('lesson.php?id=' . $l['id']) ?>" target="_blank"><?= e($l['title']) ?></a><div class="small text-muted"><?= e(lesson_schedule_label($l)) ?></div></td>
                    <td><span class="badge bg-<?= $t['color'] ?>-subtle text-<?= $t['color'] ?>"><i class="bi <?= $t['icon'] ?>"></i> <?= e($t['label']) ?></span></td>
                    <td class="text-center"><?= (int)$l['done_count'] ?></td>
                    <td>
                        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $l['id'] ?>">
                            <button name="action" value="toggle" class="btn btn-sm badge border-0 <?= $l['is_published'] ? 'bg-success' : 'bg-secondary' ?>"><?= $l['is_published'] ? 'แสดง' : 'ซ่อน' ?></button></form>
                    </td>
                    <td class="text-end text-nowrap">
                        <?php if (assignments_ready()): ?><a href="<?= url('admin/assignments.php?lesson_id=' . $l['id']) ?>" class="btn btn-sm btn-outline-success">ตรวจงาน</a><?php endif; ?>
                        <a href="<?= url('admin/lesson_form.php?id=' . $l['id'] . '#media-list') ?>" class="btn btn-sm btn-outline-primary">จัดการสื่อ</a>
                        <a href="<?= url('admin/lesson_form.php?id=' . $l['id']) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                        <form method="post" class="d-inline" data-confirm="ลบบทเรียนนี้?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $l['id'] ?>">
                            <button name="action" value="delete" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$lessons): ?><tr><td colspan="6" class="text-center text-muted py-5">ยังไม่มีบทเรียน — กด "เพิ่มบทเรียน/สื่อ" เพื่อเริ่มต้น</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
