<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/ai.php';
require_once ROOT_PATH . '/includes/ai_teacher.php';

$me = require_role('admin', 'teacher');
$courseId = get_int('course_id');
$course = db_one('SELECT * FROM courses WHERE id = ?', [$courseId]);
if (!$course || !can_manage_course($courseId)) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์จัดการรายวิชานี้');
}
$tools = ai_teacher_tools();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $tool = array_key_exists(post('tool'), $tools) ? post('tool') : 'lesson';
    $in = [
        'topic' => mb_substr(post('topic'), 0, 300), 'level' => mb_substr(post('level'), 0, 50), 'extra' => mb_substr(post('extra'), 0, 1500),
        'count' => (int)post('count'), 'periods' => (int)post('periods'), 'style' => mb_substr(post('style'), 0, 100),
        'qtype' => post('qtype'), 'difficulty' => post('difficulty'),
    ];
    if (in_array($tool, ['lesson', 'rubric'], true) && trim($in['topic']) === '') {
        flash('warning', 'กรุณาระบุหัวข้อ / งานที่ต้องการ');
        redirect('admin/ai_teacher.php?course_id=' . $courseId . '&tool=' . $tool);
    }
    // เนื้อหาที่ใช้เป็นแหล่งข้อมูล: บทเรียนที่ครูเลือก (หรือทั้งวิชา)
    $lessonIds = array_map('intval', (array)post('lessons', []));
    $material = ai_course_material($course, false, null, 80000, $lessonIds);
    $stats = in_array($tool, ['summary', 'improve'], true) ? ai_course_stats($courseId) : '';
    $r = ai_run($me, $tool, $courseId, ai_teacher_system($course, $material),
        [['role' => 'user', 'content' => ai_teacher_prompt($tool, $in, $stats)]], 'medium', 16000);
    if ($r['status'] !== 'ok') {
        flash('danger', 'สร้างไม่สำเร็จ: ' . $r['error']);
        redirect('admin/ai_teacher.php?course_id=' . $courseId . '&tool=' . $tool);
    }
    $title = $tools[$tool]['label'] . ($in['topic'] !== '' && in_array($tool, ['lesson', 'exercise', 'quiz', 'rubric'], true) ? ': ' . $in['topic'] : '');
    $draftId = db_exec('INSERT INTO ai_drafts (course_id, user_id, type, title, request, content, model) VALUES (?,?,?,?,?,?,?)',
        [$courseId, (int)$me['id'], $tool, mb_substr($title, 0, 200), json_encode($in, JSON_UNESCAPED_UNICODE), $r['text'], $r['model'] ?? ai_model()]);
    flash('success', 'AI สร้างฉบับร่างแล้ว — กรุณาตรวจสอบและแก้ไขก่อนนำไปใช้');
    redirect('admin/ai_draft.php?id=' . $draftId);
}

$current = array_key_exists($_GET['tool'] ?? '', $tools) ? $_GET['tool'] : 'lesson';
$lessons = db_all('SELECT id, title, is_published FROM lessons WHERE course_id = ? ORDER BY sort_order, id', [$courseId]);
$drafts = db_all("SELECT d.*, u.fullname FROM ai_drafts d LEFT JOIN users u ON u.id = d.user_id WHERE d.course_id = ? AND d.status <> 'discarded' ORDER BY d.id DESC LIMIT 50", [$courseId]);
$reason = ai_unavailable_reason() ?? (setting('ai_teacher_enabled', '1') !== '1' ? 'ผู้ดูแลระบบปิดผู้ช่วย AI สำหรับครู' : null);

$pageTitle = 'ผู้ช่วยสอน AI: ' . $course['code'] . ' ' . $course['title'];
require __DIR__ . '/_top.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="<?= url('admin/lessons.php?course_id=' . $courseId) ?>"><i class="bi bi-arrow-left"></i> บทเรียนของรายวิชา</a>
    <?php if (!$reason): ?><span class="small text-muted">ใช้ได้อีก <?= ai_quota_left($me) ?> ครั้งวันนี้ · <?= e(ai_model()) ?></span><?php endif; ?>
</div>
<?php if ($reason): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> ยังใช้ผู้ช่วย AI ไม่ได้: <?= e($reason) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="row g-2 mb-3">
            <?php foreach ($tools as $k => $t): ?>
                <div class="col-6 col-md-4">
                    <a href="?course_id=<?= $courseId ?>&tool=<?= $k ?>" class="card h-100 text-reset border-2 <?= $current === $k ? 'border-' . $t['color'] : 'border-0' ?> shadow-sm rounded-4">
                        <div class="card-body py-2">
                            <div class="fw-semibold"><i class="bi <?= $t['icon'] ?> text-<?= $t['color'] ?>"></i> <?= e($t['label']) ?></div>
                            <div class="small text-muted"><?= e($t['desc']) ?></div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="post" class="card border-0 shadow-sm rounded-4" id="aiForm">
            <?= csrf_field() ?><input type="hidden" name="tool" value="<?= $current ?>">
            <div class="card-body p-4">
                <h5 class="mb-3"><i class="bi <?= $tools[$current]['icon'] ?> text-<?= $tools[$current]['color'] ?>"></i> <?= e($tools[$current]['label']) ?></h5>
                <div class="row g-3">
                    <?php if ($current === 'lesson'): ?>
                        <div class="col-md-8"><label class="form-label">หัวข้อบทเรียน *</label><input name="topic" class="form-control" required placeholder="เช่น การออกแบบหน้าร้านค้าออนไลน์"></div>
                        <div class="col-md-4"><label class="form-label">จำนวนคาบ</label><input name="periods" type="number" min="1" max="20" value="2" class="form-control"></div>
                    <?php elseif ($current === 'exercise'): ?>
                        <div class="col-md-8"><label class="form-label">เน้นเรื่อง (ไม่บังคับ)</label><input name="topic" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">จำนวนข้อ</label><input name="count" type="number" min="1" max="40" value="10" class="form-control"></div>
                        <div class="col-12"><label class="form-label">รูปแบบ</label><input name="style" class="form-control" placeholder="เช่น ตอบสั้น / ฝึกปฏิบัติ / สถานการณ์จำลอง / ใบงาน (เว้นว่าง = ผสม)"></div>
                    <?php elseif ($current === 'quiz'): ?>
                        <div class="col-md-6"><label class="form-label">เน้นเรื่อง (ไม่บังคับ)</label><input name="topic" class="form-control"></div>
                        <div class="col-md-2"><label class="form-label">จำนวนข้อ</label><input name="count" type="number" min="1" max="60" value="10" class="form-control"></div>
                        <div class="col-md-2"><label class="form-label">ชนิด</label><select name="qtype" class="form-select"><option value="mcq">ปรนัย</option><option value="essay">อัตนัย</option><option value="mixed">ผสม</option></select></div>
                        <div class="col-md-2"><label class="form-label">ความยาก</label><select name="difficulty" class="form-select"><option value="mixed">คละ</option><option value="easy">ง่าย</option><option value="medium">กลาง</option><option value="hard">ยาก</option></select></div>
                    <?php elseif ($current === 'rubric'): ?>
                        <div class="col-md-8"><label class="form-label">งาน / ชิ้นงาน / การปฏิบัติที่ประเมิน *</label><input name="topic" class="form-control" required placeholder="เช่น การนำเสนอโครงงานเว็บไซต์ร้านค้าออนไลน์"></div>
                        <div class="col-md-4"><label class="form-label">จำนวนประเด็น</label><input name="count" type="number" min="2" max="10" value="4" class="form-control"></div>
                    <?php elseif ($current === 'summary'): ?>
                        <div class="col-12"><div class="alert alert-info small mb-0">ใช้ข้อมูลความก้าวหน้าของผู้เรียนในวิชานี้โดย<b>ไม่ส่งชื่อ รหัส หรืออีเมลผู้เรียน</b>ให้ AI (ส่งเฉพาะสถิติรวม/รายกลุ่ม)</div></div>
                    <?php else: ?>
                        <div class="col-12"><label class="form-label">ปัญหาหรือข้อสังเกตที่พบ (ไม่บังคับ)</label><textarea name="topic" rows="2" class="form-control" placeholder="เช่น ผู้เรียนไม่ค่อยดูวิดีโอจนจบ / ทำงานกลุ่มไม่ทัน"></textarea></div>
                    <?php endif; ?>
                    <?php if (!in_array($current, ['summary', 'improve'], true)): ?>
                        <div class="col-md-4"><label class="form-label">ระดับชั้น</label><input name="level" class="form-control" placeholder="เช่น ปวส.1" list="levels"><datalist id="levels"><option>ปวช.1</option><option>ปวช.2</option><option>ปวช.3</option><option>ปวส.1</option><option>ปวส.2</option></datalist></div>
                    <?php endif; ?>
                    <div class="col-md-8"><label class="form-label">คำสั่งเพิ่มเติม (ไม่บังคับ)</label><input name="extra" class="form-control" maxlength="1500" placeholder="เช่น ยกตัวอย่างร้านค้าในชุมชน / ใช้ภาษาเข้าใจง่าย"></div>

                    <?php if ($lessons && $current !== 'summary'): ?>
                        <div class="col-12">
                            <label class="form-label">ใช้เนื้อหาจากบทเรียน <span class="small text-muted">(ไม่เลือก = ทั้งรายวิชา)</span></label>
                            <div class="border rounded p-2" style="max-height:170px;overflow-y:auto">
                                <?php foreach ($lessons as $l): ?>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="lessons[]" value="<?= $l['id'] ?>" id="l<?= $l['id'] ?>">
                                        <label class="form-check-label small" for="l<?= $l['id'] ?>"><?= e($l['title']) ?><?= $l['is_published'] ? '' : ' <span class="badge bg-secondary">ซ่อน</span>' ?></label></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">AI อ่านได้เฉพาะเนื้อหาข้อความของบทเรียน ไม่เห็นเนื้อหาในวิดีโอ ไฟล์ PDF หรือลิงก์</div>
                        </div>
                    <?php endif; ?>
                </div>
                <button class="btn btn-vt mt-4 px-4" <?= $reason ? 'disabled' : '' ?> id="aiSubmit"><i class="bi bi-stars"></i> สร้างด้วย AI</button>
                <span class="small text-muted ms-2 d-none" id="aiWait"><span class="spinner-border spinner-border-sm"></span> กำลังสร้าง อาจใช้เวลา 30 วินาที - 2 นาที...</span>
                <div class="small text-muted mt-2"><i class="bi bi-shield-check"></i> ผลลัพธ์บันทึกเป็น<b>ฉบับร่าง</b> ครูต้องตรวจสอบและยืนยันก่อนเผยแพร่ให้ผู้เรียนเสมอ</div>
            </div>
        </form>
    </div>

    <div class="col-xl-4">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white"><b>ฉบับร่างจาก AI</b></div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($drafts as $d): $t = $tools[$d['type']]; ?>
                    <a href="<?= url('admin/ai_draft.php?id=' . $d['id']) ?>" class="list-group-item list-group-item-action">
                        <div class="d-flex justify-content-between">
                            <span><i class="bi <?= $t['icon'] ?> text-<?= $t['color'] ?>"></i> <?= e($d['title']) ?></span>
                            <?= $d['status'] === 'published' ? '<span class="badge bg-success">ใช้แล้ว</span>' : '<span class="badge bg-warning text-dark">รอตรวจ</span>' ?>
                        </div>
                        <div class="text-muted"><?= e($d['fullname'] ?? '') ?> · <?= thai_date($d['created_at'], true) ?></div>
                    </a>
                <?php endforeach; ?>
                <?php if (!$drafts): ?><li class="list-group-item text-muted text-center py-4">ยังไม่มีฉบับร่าง</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<script>
    document.getElementById('aiForm').addEventListener('submit', function () {
        document.getElementById('aiSubmit').disabled = true;
        document.getElementById('aiWait').classList.remove('d-none');
    });
</script>
<?php require __DIR__ . '/_bottom.php'; ?>
