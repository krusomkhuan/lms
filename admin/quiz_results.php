<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_role('admin', 'teacher');
$lesson = db_one('SELECT * FROM lessons WHERE id = ?', [get_int('lesson_id')]);
if (!$lesson || !can_manage_course((int)$lesson['course_id'])) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ดูคะแนนบทเรียนนี้');
}
$page = max(1, get_int('page'));
$offset = ($page - 1) * 100;
$attempts = quiz_ready() ? db_all('SELECT a.*, u.fullname, u.student_code FROM quiz_attempts a JOIN users u ON u.id = a.user_id WHERE a.lesson_id = ? ORDER BY a.id DESC LIMIT 101 OFFSET ' . $offset, [(int)$lesson['id']]) : [];
$hasNext = count($attempts) > 100;
$attempts = array_slice($attempts, 0, 100);
$pageTitle = 'คะแนนแบบทดสอบ — ' . $lesson['title'];
require __DIR__ . '/_top.php';
?>
<a href="<?= url('admin/lesson_form.php?id=' . $lesson['id']) ?>">กลับไปแก้ไขบทเรียน</a>
<h1 class="h4 my-3"><?= e($pageTitle) ?></h1>
<p>แสดงคะแนนทุกครั้งที่ส่งคำตอบ ข้อละ 1 คะแนน คะแนนเดิมคงอยู่เมื่อแก้ไขข้อสอบหรือลบสื่อเพิ่มเติม</p>
<div class="table-responsive"><table class="table table-striped">
    <thead><tr><th>นักเรียน</th><th>รหัสนักเรียน</th><th>แบบทดสอบ</th><th>คะแนน</th><th>ร้อยละ</th><th>เวลาส่ง</th></tr></thead>
    <tbody><?php foreach ($attempts as $a): ?><tr>
        <td><?= e($a['fullname']) ?></td><td><?= e($a['student_code']) ?></td><td><?= e($a['quiz_title']) ?></td>
        <td><?= (int)$a['score'] ?> / <?= (int)$a['total'] ?></td><td><?= round($a['score'] * 100 / max(1, $a['total']), 2) ?>%</td><td><?= e(thai_date($a['submitted_at'], true)) ?></td>
    </tr><?php endforeach; ?>
    <?php if (!$attempts): ?><tr><td colspan="6">ยังไม่มีคะแนนสอบ</td></tr><?php endif; ?></tbody>
</table></div>
<?php if ($page > 1): ?><a class="btn btn-light" href="?lesson_id=<?= (int)$lesson['id'] ?>&page=<?= $page - 1 ?>">ก่อนหน้า</a><?php endif; ?>
<?php if ($hasNext): ?><a class="btn btn-light" href="?lesson_id=<?= (int)$lesson['id'] ?>&page=<?= $page + 1 ?>">ถัดไป</a><?php endif; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
