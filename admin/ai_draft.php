<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/ai.php';
require_once ROOT_PATH . '/includes/ai_teacher.php';

$me = require_role('admin', 'teacher');
$id = get_int('id');
$draft = db_one('SELECT * FROM ai_drafts WHERE id = ?', [$id]);
if (!$draft || !can_manage_course((int)$draft['course_id'])) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์เข้าถึงฉบับร่างนี้');
}
$course = db_one('SELECT * FROM courses WHERE id = ?', [(int)$draft['course_id']]);
$tool = ai_teacher_tools()[$draft['type']];
$hasAnswerKey = in_array($draft['type'], ['exercise', 'quiz'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $content = clean_html(post('content'));
    $title = mb_substr(post('title') ?: $draft['title'], 0, 200);
    $action = post('action');

    if ($action === 'discard') {
        db_exec("UPDATE ai_drafts SET status = 'discarded', updated_at = NOW() WHERE id = ?", [$id]);
        flash('info', 'ทิ้งฉบับร่างแล้ว');
        redirect('admin/ai_teacher.php?course_id=' . $course['id']);
    }

    db_exec('UPDATE ai_drafts SET title = ?, content = ?, updated_at = NOW() WHERE id = ?', [$title, $content, $id]);

    if ($action === 'to_lesson' && $tool['publishable']) {
        $publish = post('publish') === '1';
        if ($publish && !post('reviewed')) {
            flash('warning', 'ต้องยืนยันว่าได้ตรวจสอบเนื้อหาแล้ว จึงจะเผยแพร่ให้ผู้เรียนได้');
            redirect('admin/ai_draft.php?id=' . $id);
        }
        if ($publish && $hasAnswerKey && !post('key_removed')) {
            flash('warning', 'ต้องยืนยันว่าได้ลบเฉลย/แนวคำตอบออกแล้ว (หรือตั้งใจให้ผู้เรียนเห็น) จึงจะเผยแพร่ได้');
            redirect('admin/ai_draft.php?id=' . $id);
        }
        $order = (int)db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM lessons WHERE course_id = ?', [(int)$course['id']]);
        $lessonId = db_exec("INSERT INTO lessons (course_id, title, media_type, content, sort_order, is_published) VALUES (?,?,'text',?,?,?)",
            [(int)$course['id'], $title, $content, $order, $publish ? 1 : 0]);
        db_exec("UPDATE ai_drafts SET status = 'published', lesson_id = ?, updated_at = NOW() WHERE id = ?", [$lessonId, $id]);
        flash('success', $publish ? 'เผยแพร่เป็นบทเรียนแล้ว' : 'สร้างเป็นบทเรียนแบบซ่อนแล้ว — เปิดแสดงได้ที่หน้าบทเรียนเมื่อพร้อม');
        redirect('admin/lessons.php?course_id=' . $course['id']);
    }
    flash('success', 'บันทึกฉบับร่างแล้ว');
    redirect('admin/ai_draft.php?id=' . $id);
}

$req = json_decode((string)$draft['request'], true) ?: [];
$pageTitle = 'ตรวจฉบับร่างจาก AI';
require __DIR__ . '/_top.php';
?>
<a href="<?= url('admin/ai_teacher.php?course_id=' . $course['id'] . '&tool=' . $draft['type']) ?>"><i class="bi bi-arrow-left"></i> ผู้ช่วยสอน AI: <?= e($course['code'] . ' ' . $course['title']) ?></a>

<div class="alert alert-warning mt-3 d-flex gap-2">
    <i class="bi bi-robot fs-4"></i>
    <div><b>ฉบับร่างที่สร้างโดย AI (<?= e($draft['model']) ?>)</b> — อาจมีข้อมูลคลาดเคลื่อน กรุณาตรวจสอบความถูกต้องของเนื้อหา ตัวเลข และแหล่งอ้างอิงก่อนนำไปใช้
        <?= strpos((string)$draft['content'], '[ตรวจสอบ]') !== false ? '<br><b class="text-danger">มีจุดที่ AI ทำเครื่องหมาย [ตรวจสอบ] ไว้ — ค้นหาคำนี้ในเนื้อหา</b>' : '' ?></div>
</div>
<?php if ($draft['status'] === 'published' && $draft['lesson_id']): ?>
    <div class="alert alert-success">นำไปสร้างเป็นบทเรียนแล้ว — <a href="<?= url('admin/lesson_form.php?id=' . $draft['lesson_id']) ?>">แก้ไขบทเรียน</a> (การแก้ไขด้านล่างไม่กระทบบทเรียนที่สร้างไปแล้ว)</div>
<?php endif; ?>

<form method="post">
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body">
                    <label class="form-label">ชื่อ</label>
                    <input name="title" class="form-control mb-3" value="<?= e($draft['title']) ?>">
                    <textarea name="content" id="draftContent" rows="20" class="form-control"><?= e($draft['content']) ?></textarea>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body">
                    <div class="small text-muted mb-3">
                        <i class="bi <?= $tool['icon'] ?>"></i> <?= e($tool['label']) ?> · สร้างเมื่อ <?= thai_date($draft['created_at'], true) ?>
                        <?php foreach (['topic' => 'หัวข้อ', 'level' => 'ระดับ', 'count' => 'จำนวน', 'extra' => 'คำสั่งเพิ่มเติม'] as $k => $lbl): ?>
                            <?php if (!empty($req[$k])): ?><div><?= $lbl ?>: <?= e($req[$k]) ?></div><?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <button name="action" value="save" class="btn btn-outline-primary w-100 mb-3"><i class="bi bi-save"></i> บันทึกฉบับร่าง</button>

                    <?php if ($tool['publishable']): ?>
                        <hr>
                        <h6>นำไปใช้เป็นบทเรียน</h6>
                        <div class="form-check"><input class="form-check-input" type="radio" name="publish" value="0" id="p0" checked>
                            <label class="form-check-label" for="p0">สร้างเป็นบทเรียน<b>แบบซ่อน</b> (แนะนำ ตรวจอีกรอบในหน้าบทเรียน)</label></div>
                        <div class="form-check mb-2"><input class="form-check-input" type="radio" name="publish" value="1" id="p1">
                            <label class="form-check-label" for="p1">สร้างและ<b>เผยแพร่</b>ให้ผู้เรียนทันที</label></div>
                        <div class="border rounded p-2 mb-2 bg-light small" id="confirmBox">
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="reviewed" value="1" id="rv">
                                <label class="form-check-label" for="rv">ข้าพเจ้าตรวจสอบความถูกต้องของเนื้อหาแล้ว</label></div>
                            <?php if ($hasAnswerKey): ?>
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="key_removed" value="1" id="kr">
                                    <label class="form-check-label" for="kr">ลบ<b>เฉลย/แนวคำตอบสำหรับครู</b>ออกแล้ว (หรือตั้งใจให้ผู้เรียนเห็น)</label></div>
                            <?php endif; ?>
                        </div>
                        <button name="action" value="to_lesson" class="btn btn-vt w-100"><i class="bi bi-journal-plus"></i> สร้างเป็นบทเรียน</button>
                        <?php if ($hasAnswerKey): ?><div class="form-text">แนะนำ: คัดลอกส่วนเฉลยเก็บไว้ก่อน แล้วลบออกจากเนื้อหาที่จะให้ผู้เรียนเห็น</div><?php endif; ?>
                    <?php else: ?>
                        <div class="small text-muted">รายงานนี้สำหรับครูเท่านั้น ไม่แสดงต่อผู้เรียน · <a href="#" onclick="window.print(); return false;">พิมพ์</a></div>
                    <?php endif; ?>
                    <hr>
                    <button name="action" value="discard" class="btn btn-sm btn-outline-danger w-100" onclick="return confirm('ทิ้งฉบับร่างนี้?')"><i class="bi bi-trash"></i> ทิ้งฉบับร่าง</button>
                </div>
            </div>
        </div>
    </div>
</form>
<?php $editorSelector = '#draftContent'; require __DIR__ . '/_editor.php'; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
