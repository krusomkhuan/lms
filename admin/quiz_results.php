<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_role('admin', 'teacher');
$lesson = db_one('SELECT * FROM lessons WHERE id = ?', [get_int('lesson_id')]);
if (!$lesson || !can_manage_course((int)$lesson['course_id'])) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ดูคะแนนบทเรียนนี้');
}
$quizzes = quiz_ready() ? lesson_quizzes($lesson) : [];
// รีเซ็ตคะแนนรายคน: ลบการทำแบบทดสอบของนักเรียนคนนั้นในแบบทดสอบนั้น ให้สอบใหม่ได้ (ก่อน–หลังเรียนทำได้ 1 ครั้ง)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $mediaId = (int)post('source_media_id');
    $quiz = $quizzes[(int)$lesson['id'] . ':' . $mediaId] ?? null;
    if ($quiz && post('action') === 'reset_user') {
        $name = (string)db_value('SELECT fullname FROM users WHERE id = ?', [(int)post('user_id')]);
        $n = db_exec('DELETE FROM quiz_attempts WHERE lesson_id = ? AND source_media_id = ? AND user_id = ?', [(int)$lesson['id'], $mediaId, (int)post('user_id')]);
        flash('success', 'รีเซ็ตคะแนน "' . $quiz['title'] . '" ของ ' . $name . ' แล้ว (ลบ ' . $n . ' ครั้ง) นักเรียนทำแบบทดสอบใหม่ได้');
    }
    redirect('admin/quiz_results.php?lesson_id=' . (int)$lesson['id']);
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
    <thead><tr><th>นักเรียน</th><th>รหัสนักเรียน</th><th>แบบทดสอบ</th><th>คะแนน</th><th>ร้อยละ</th><th>เวลาส่ง</th><th></th></tr></thead>
    <tbody><?php foreach ($attempts as $a): ?><tr>
        <td><?= e($a['fullname']) ?></td><td><?= e($a['student_code']) ?></td><td><?= e($a['quiz_title']) ?></td>
        <td><?= (int)$a['score'] ?> / <?= (int)$a['total'] ?></td><td><?= round($a['score'] * 100 / max(1, $a['total']), 2) ?>%</td>
        <td><?= e(thai_date($a['submitted_at'], true)) ?></td>
        <td class="text-end"><?php $q = $quizzes[(int)$lesson['id'] . ':' . (int)$a['source_media_id']] ?? null; if ($q): ?>
            <form method="post" class="d-inline" data-confirm="<?= $q['kind'] === 'pre' ? 'นี่คือแบบทดสอบก่อนเรียน (ผลเปรียบเทียบก่อน–หลังเรียนจะเปลี่ยน) ' : '' ?>รีเซ็ตคะแนน <?= e($q['title']) ?> ของ <?= e($a['fullname']) ?> ทุกครั้ง ให้สอบใหม่ได้? ลบแล้วกู้คืนไม่ได้"><?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_user"><input type="hidden" name="source_media_id" value="<?= (int)$a['source_media_id'] ?>"><input type="hidden" name="user_id" value="<?= (int)$a['user_id'] ?>">
                <button class="btn btn-sm btn-outline-danger text-nowrap" title="ลบคะแนนของนักเรียนคนนี้ในแบบทดสอบนี้ ให้สอบใหม่ได้"><i class="bi bi-arrow-counterclockwise"></i> รีเซ็ต</button></form>
        <?php endif; ?></td>
    </tr><?php endforeach; ?>
    <?php if (!$attempts): ?><tr><td colspan="8">ยังไม่มีคะแนนสอบ</td></tr><?php endif; ?></tbody>
</table></div>
<?php if ($page > 1): ?><a class="btn btn-light" href="?lesson_id=<?= (int)$lesson['id'] ?>&page=<?= $page - 1 ?>">ก่อนหน้า</a><?php endif; ?>
<?php if ($hasNext): ?><a class="btn btn-light" href="?lesson_id=<?= (int)$lesson['id'] ?>&page=<?= $page + 1 ?>">ถัดไป</a><?php endif; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
