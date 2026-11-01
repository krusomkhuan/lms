<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_role('admin', 'teacher');
$lesson = db_one('SELECT * FROM lessons WHERE id = ?', [get_int('lesson_id')]);
if (!$lesson || !can_manage_course((int)$lesson['course_id'])) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ตรวจงานในบทเรียนนี้');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (!assignments_ready()) throw new RuntimeException('กรุณารัน database/migrations/1.13.0.sql ก่อนใช้งาน');
        $submission = db_one('SELECT * FROM assignment_submissions WHERE id = ? AND lesson_id = ?', [(int)post('submission_id'), (int)$lesson['id']]);
        if (!$submission) throw new RuntimeException('ไม่พบงานที่ต้องการตรวจ');
        $status = post('status');
        if (!in_array($status, ['graded', 'revision'], true)) throw new RuntimeException('สถานะงานไม่ถูกต้อง');
        $score = $status === 'graded' ? assignment_score(post('score'), $submission['max_score']) : null;
        $feedback = post('feedback');
        if (!is_string($feedback) || mb_strlen($feedback) > 10000) throw new RuntimeException('ข้อเสนอแนะยาวได้ไม่เกิน 10,000 ตัวอักษร');
        if ($status === 'revision' && $feedback === '') throw new RuntimeException('กรุณาระบุสิ่งที่ต้องการให้นักเรียนแก้ไข');
        $stmt = db_query('UPDATE assignment_submissions SET score=?,feedback=?,status=?,graded_by=?,graded_at=NOW(),grade_version=grade_version+1 WHERE id=? AND lesson_id=? AND grade_version=?',
            [$score, $feedback ?: null, $status, (int)$me['id'], (int)$submission['id'], (int)$lesson['id'], (int)post('grade_version')]);
        if ($stmt->affected_rows !== 1) throw new RuntimeException('งานนี้มีการตรวจโดยครูแล้ว กรุณาตรวจสอบข้อมูลล่าสุดก่อนบันทึกอีกครั้ง');
        flash('success', $status === 'graded' ? 'บันทึกคะแนนและผลตรวจแล้ว' : 'ส่งข้อเสนอแนะให้นักเรียนแก้ไขงานแล้ว');
        redirect('admin/assignments.php?lesson_id=' . $lesson['id'] . '&page=' . max(1, get_int('page')) . '#submission-' . $submission['id']);
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    }
}
$page = max(1, get_int('page'));
$offset = ($page - 1) * 25;
$submissions = assignments_ready() ? db_all('SELECT s.*, u.fullname, u.student_code, g.fullname AS grader FROM assignment_submissions s JOIN users u ON u.id = s.user_id LEFT JOIN users g ON g.id = s.graded_by WHERE s.lesson_id = ? ORDER BY s.id DESC LIMIT 26 OFFSET ' . $offset, [(int)$lesson['id']]) : [];
$hasNext = count($submissions) > 25;
$submissions = array_slice($submissions, 0, 25);
$pageTitle = 'ตรวจงานและให้คะแนน — ' . $lesson['title'];
require __DIR__ . '/_top.php';
?>
<a href="<?= url('admin/lesson_form.php?id=' . $lesson['id']) ?>">กลับไปแก้ไขบทเรียน</a>
<h1 class="h4 my-3"><?= e($pageTitle) ?></h1>
<p>แสดงงานที่ส่งล่าสุดก่อน คะแนนและคำสั่งงานยึดตามฉบับที่นักเรียนส่ง การส่งใหม่จะแยกเป็นอีกรายการเพื่อเก็บประวัติ</p>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if (!assignments_ready()): ?><div class="alert alert-warning">กรุณารัน database/migrations/1.13.0.sql ก่อนใช้งาน</div><?php endif; ?>
<?php if (!$submissions): ?><div class="alert alert-info">ยังไม่มีนักเรียนส่งงาน</div><?php endif; ?>
<?php foreach ($submissions as $s): ?>
<article class="card border-0 shadow-sm mb-4" id="submission-<?= (int)$s['id'] ?>">
    <div class="card-body">
        <h2 class="h5"><?= e($s['fullname']) ?> <span class="small text-muted"><?= e($s['student_code']) ?></span></h2>
        <p><?= e($s['assignment_title']) ?> <?= !empty($s['is_late']) ? '<span class="badge bg-warning text-dark">ส่งล่าช้า</span>' : '' ?> · <strong><?= e(assignment_status($s['status'])) ?></strong> · ส่งเมื่อ <?= e(thai_date($s['submitted_at'], true)) ?></p>
        <details class="mb-3"><summary>คำสั่งงานฉบับที่ส่ง · คะแนนเต็ม <?= e($s['max_score']) ?></summary><div class="lesson-content mt-2"><?= clean_html($s['instructions']) ?></div></details>
        <?php if ($s['submission_text']): ?><div class="border rounded p-3 mb-3 text-break" style="white-space:pre-wrap"><?= e($s['submission_text']) ?></div><?php endif; ?>
        <?php if ($s['file_path']): ?><a class="btn btn-outline-primary mb-3" href="<?= url('assignment_file.php?id=' . $s['id']) ?>">ดาวน์โหลดงาน: <?= e($s['original_name']) ?></a><?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>">
            <input type="hidden" name="grade_version" value="<?= (int)$s['grade_version'] ?>">
            <?php $retry = $error && (int)post('submission_id') === (int)$s['id']; ?>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label w-100">ผลการตรวจ
                    <select name="status" class="form-select"><option value="graded" <?= ($retry ? post('status') : $s['status']) !== 'revision' ? 'selected' : '' ?>>ตรวจแล้ว / ให้คะแนน</option><option value="revision" <?= ($retry ? post('status') : $s['status']) === 'revision' ? 'selected' : '' ?>>ให้นักเรียนแก้ไขงาน</option></select>
                </label></div>
                <div class="col-md-4"><label class="form-label w-100">คะแนน (เต็ม <?= e($s['max_score']) ?>)
                    <input type="number" name="score" min="0" max="<?= e($s['max_score']) ?>" step="0.01" class="form-control" value="<?= e($retry ? post('score') : $s['score']) ?>">
                </label></div>
                <div class="col-12"><label class="form-label w-100">ข้อเสนอแนะ / สิ่งที่ต้องแก้ไข
                    <textarea name="feedback" rows="3" maxlength="10000" class="form-control"><?= e($retry ? post('feedback') : $s['feedback']) ?></textarea>
                </label></div>
            </div>
            <button class="btn btn-vt mt-2">บันทึกผลตรวจและคะแนน</button>
            <p class="small text-muted mt-2 mb-0">เลือก “ให้แก้ไขงาน” ต้องระบุข้อเสนอแนะ และสถานะนี้ยังไม่ให้คะแนน</p>
        </form>
        <?php if ($s['graded_at']): ?><p class="small text-muted mt-3 mb-0">ตรวจล่าสุดโดย <?= e($s['grader'] ?? 'ครูผู้สอน') ?> · <?= e(thai_date($s['graded_at'], true)) ?> · คะแนน <?= $s['score'] !== null ? e($s['score']) . ' / ' . e($s['max_score']) : 'ยังไม่ให้คะแนน' ?></p><?php endif; ?>
    </div>
</article>
<?php endforeach; ?>
<?php if ($page > 1): ?><a class="btn btn-light" href="?lesson_id=<?= (int)$lesson['id'] ?>&page=<?= $page - 1 ?>">ก่อนหน้า</a><?php endif; ?>
<?php if ($hasNext): ?><a class="btn btn-light" href="?lesson_id=<?= (int)$lesson['id'] ?>&page=<?= $page + 1 ?>">ถัดไป</a><?php endif; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
