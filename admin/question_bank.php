<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$me = require_role('admin', 'teacher');
$courseId = get_int('course_id');
if (!$courseId) {
    // เมนูคลังข้อสอบ: เลือกรายวิชา (admin ทุกวิชา, ครูเฉพาะวิชาที่สอน)
    $courses = staff_courses($me);
    $pageTitle = 'คลังข้อสอบ';
    require __DIR__ . '/_top.php';
    ?>
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <p class="text-muted mb-0">เลือกรายวิชาเพื่อจัดการคลังข้อสอบ และดูผลก่อนเรียน–หลังเรียน</p>
        <a href="<?= url('admin/quiz_import.php') ?>" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-text"></i> รูปแบบการนำเข้าข้อสอบ</a>
    </div>
    <div class="card border-0 shadow-sm rounded-4"><div class="table-responsive"><table class="table table-hover mb-0">
        <thead class="table-light"><tr><th>รหัสวิชา</th><th>รายวิชา</th><th class="text-center">ข้อสอบในคลัง</th><th class="text-end">จัดการ</th></tr></thead>
        <tbody>
        <?php foreach ($courses as $c): ?>
            <tr><td><?= e($c['code']) ?></td><td><?= e($c['title']) ?></td><td class="text-center"><?= (int)$c['bank_count'] ?></td>
                <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/question_bank.php?course_id=' . $c['id']) ?>"><i class="bi bi-archive"></i> คลังข้อสอบ</a>
                    <a class="btn btn-sm btn-outline-success" href="<?= url('admin/prepost.php?course_id=' . $c['id']) ?>"><i class="bi bi-graph-up-arrow"></i> ผลก่อน–หลังเรียน</a></td></tr>
        <?php endforeach; ?>
        <?php if (!$courses): ?><tr><td colspan="4" class="text-center text-muted py-5">ยังไม่มีรายวิชาที่คุณสอน</td></tr><?php endif; ?>
        </tbody>
    </table></div></div>
    <?php
    require __DIR__ . '/_bottom.php';
    exit;
}
$course = db_one('SELECT * FROM courses WHERE id = ?', [$courseId]);
if (!$course || !can_manage_course($courseId)) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์จัดการรายวิชานี้');
}
if (!question_bank_ready()) {
    flash('warning', 'กรุณารัน database/migrations/1.15.0.sql ก่อนใช้คลังข้อสอบ');
    redirect('admin/lessons.php?course_id=' . $courseId);
}

$self = 'admin/question_bank.php?course_id=' . $courseId;
$category = fn($value) => mb_substr(trim(is_string($value) ? $value : ''), 0, 100) ?: 'ทั่วไป';
$insert = function (array $q, string $cat) use ($courseId, $me) {
    db_exec('INSERT INTO question_bank (course_id,category,question_text,options_json,correct,created_by) VALUES (?,?,?,?,?,?)',
        [$courseId, $cat, trim($q['text']), json_encode(array_map('trim', $q['options']), JSON_UNESCAPED_UNICODE), $q['correct'], (int)$me['id']]);
};
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        switch (post('action')) {
            case 'save':
                $options = array_values(array_filter((array)post('options', []), 'is_string'));
                $q = ['text' => post('question_text'), 'options' => $options, 'correct' => (int)post('correct', -1)];
                quiz_validate_questions([$q]);
                $qid = (int)post('id');
                if ($qid) {
                    db_exec('UPDATE question_bank SET category=?, question_text=?, options_json=?, correct=? WHERE id=? AND course_id=?',
                        [$category(post('category')), trim($q['text']), json_encode(array_map('trim', $options), JSON_UNESCAPED_UNICODE), $q['correct'], $qid, $courseId]);
                    flash('success', 'บันทึกการแก้ไขข้อสอบแล้ว');
                } else {
                    $insert($q, $category(post('category')));
                    flash('success', 'เพิ่มข้อสอบลงคลังแล้ว');
                }
                redirect($self . '&cat=' . urlencode($category(post('category'))));
            case 'delete':
                db_exec('DELETE FROM question_bank WHERE id = ? AND course_id = ?', [(int)post('id'), $courseId]);
                flash('success', 'ลบข้อสอบแล้ว');
                redirect($self . '&cat=' . urlencode((string)post('cat')));
            case 'import':
                $file = $_FILES['import_file'] ?? null;
                if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024 || !in_array($ext, ['csv', 'txt', 'tsv'], true)) throw new RuntimeException('รองรับไฟล์ .csv หรือ .txt ขนาดไม่เกิน 2 MB (ไฟล์ Excel ให้บันทึกเป็น CSV UTF-8 ก่อน)');
                    $questions = question_import((string)file_get_contents($file['tmp_name']), $ext !== 'txt');
                } else {
                    $questions = question_import((string)post('import_text'));
                }
                // หมวดในไฟล์/ข้อความ (คอลัมน์ A หรือบรรทัด "หมวด:") ใช้ก่อน ถ้าไม่มีใช้หมวดที่กรอกในฟอร์ม
                $conn->begin_transaction();
                foreach ($questions as $q) $insert($q, $q['category'] !== '' ? $q['category'] : $category(post('category')));
                $conn->commit();
                flash('success', 'นำเข้าข้อสอบ ' . count($questions) . ' ข้อ ลงคลังแล้ว');
                redirect($self . '&cat=' . urlencode($category(post('category'))));
            case 'copy_quiz':
                $source = grade_sources($courseId)['quiz:' . post('source')] ?? null;
                $questions = $source ? quiz_questions((string)$source['quiz_data']) : [];
                if (!$questions) throw new RuntimeException('แบบทดสอบที่เลือกไม่มีข้อที่ครูสร้างไว้ (แบบสุ่มจากคลังคัดลอกไม่ได้)');
                foreach ($questions as $q) $insert($q, $category(post('category')));
                flash('success', 'คัดลอก ' . count($questions) . ' ข้อ จาก "' . $source['title'] . '" ลงคลังแล้ว');
                redirect($self . '&cat=' . urlencode($category(post('category'))));
            case 'rename':
                $old = (string)post('old');
                $new = $category(post('new'));
                db_exec('UPDATE question_bank SET category = ? WHERE course_id = ? AND category = ?', [$new, $courseId, $old]);
                flash('success', 'เปลี่ยนชื่อหมวดแล้ว (แบบทดสอบที่เลือกหมวดเดิมไว้ ต้องเข้าไปเลือกหมวดใหม่)');
                redirect($self . '&cat=' . urlencode($new));
        }
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    }
}

$categories = question_bank_categories($courseId);
$cat = is_string($_GET['cat'] ?? null) ? $_GET['cat'] : '';
$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$where = 'course_id = ?'; $params = [$courseId];
if ($cat !== '') { $where .= ' AND category = ?'; $params[] = $cat; }
if ($search !== '') { $where .= ' AND question_text LIKE ?'; $params[] = '%' . $search . '%'; }
$rows = db_all("SELECT * FROM question_bank WHERE $where ORDER BY category, id LIMIT 500", $params);
$edit = get_int('edit') ? db_one('SELECT * FROM question_bank WHERE id = ? AND course_id = ?', [get_int('edit'), $courseId]) : null;
$posted = $_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save';
$form = [
    'id' => $posted ? (int)post('id') : (int)($edit['id'] ?? 0),
    'category' => $posted ? post('category') : ($edit['category'] ?? ($cat ?: '')),
    'text' => $posted ? post('question_text') : ($edit['question_text'] ?? ''),
    'options' => $posted ? array_values(array_filter((array)post('options', []), 'is_string')) : ($edit ? json_decode($edit['options_json'], true) : ['', '', '', '']),
    'correct' => $posted ? (int)post('correct', 0) : (int)($edit['correct'] ?? 0),
];
$manualQuizzes = array_filter(grade_sources($courseId), fn($s) => $s['media_type'] === 'quiz' && quiz_questions((string)$s['quiz_data']));

$pageTitle = 'คลังข้อสอบ: ' . $course['code'] . ' ' . $course['title'];
require __DIR__ . '/_top.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <a href="<?= url('admin/lessons.php?course_id=' . $courseId) ?>"><i class="bi bi-arrow-left"></i> กลับไปบทเรียน</a>
    <a href="<?= url('admin/prepost.php?course_id=' . $courseId) ?>" class="btn btn-outline-success"><i class="bi bi-graph-up-arrow"></i> ผลก่อนเรียน–หลังเรียน</a>
</div>
<p class="text-muted">เก็บข้อสอบของรายวิชาแยกตามหมวด (เช่น หน่วยที่ 1, หน่วยที่ 2) แล้วเลือก "สุ่มจากคลังข้อสอบ" ในแบบทดสอบของบทเรียน เพื่อใช้เป็นแบบทดสอบก่อนเรียน/หลังเรียน นักเรียนแต่ละคนจะได้ข้อไม่เหมือนกัน</p>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-4 mb-3"><div class="card-body">
            <h2 class="h6">หมวดข้อสอบ (รวม <?= array_sum($categories) ?> ข้อ)</h2>
            <div class="list-group list-group-flush">
                <a class="list-group-item list-group-item-action d-flex justify-content-between <?= $cat === '' ? 'active' : '' ?>" href="<?= url($self) ?>">ทุกหมวด <span><?= array_sum($categories) ?></span></a>
                <?php foreach ($categories as $name => $n): ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between <?= $cat === (string)$name ? 'active' : '' ?>" href="<?= url($self . '&cat=' . urlencode((string)$name)) ?>"><?= e($name) ?> <span><?= (int)$n ?></span></a>
                <?php endforeach; ?>
            </div>
            <?php if ($cat !== '' && isset($categories[$cat])): ?>
                <form method="post" class="d-flex gap-2 mt-3"><?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="old" value="<?= e($cat) ?>">
                    <input name="new" class="form-control form-control-sm" maxlength="100" value="<?= e($cat) ?>" aria-label="ชื่อหมวดใหม่"><button class="btn btn-sm btn-outline-secondary text-nowrap">เปลี่ยนชื่อหมวด</button></form>
            <?php endif; ?>
        </div></div>

        <div class="card border-0 shadow-sm rounded-4 mb-3"><div class="card-body">
            <h2 class="h6"><?= $form['id'] ? 'แก้ไขข้อสอบ' : 'เพิ่มข้อสอบทีละข้อ' ?></h2>
            <form method="post" id="bankForm"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $form['id'] ?>">
                <label class="form-label w-100 small">หมวด<input name="category" class="form-control" maxlength="100" list="bankCats" value="<?= e($form['category']) ?>" placeholder="เช่น หน่วยที่ 1"></label>
                <label class="form-label w-100 small">โจทย์<textarea name="question_text" class="form-control" rows="3" maxlength="2000" required><?= e($form['text']) ?></textarea></label>
                <div class="small mb-1">ตัวเลือก (เลือกวงกลมหน้าคำตอบที่ถูก)</div>
                <div id="bankOptions">
                    <?php foreach ($form['options'] as $j => $option): ?>
                        <div class="d-flex align-items-center gap-2 mb-2"><input type="radio" name="correct" value="<?= $j ?>" <?= $form['correct'] === $j ? 'checked' : '' ?> aria-label="เฉลย"><span><?= QUIZ_LETTERS[$j] ?>.</span><input name="options[]" class="form-control" maxlength="1000" required value="<?= e($option) ?>"></div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="addOpt">เพิ่มตัวเลือก</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="delOpt">ลดตัวเลือก</button>
                <div class="mt-3 d-flex gap-2"><button class="btn btn-vt">บันทึก</button><?php if ($form['id']): ?><a class="btn btn-light" href="<?= url($self . '&cat=' . urlencode($cat)) ?>">ยกเลิก</a><?php endif; ?></div>
            </form>
        </div></div>

        <div class="card border-0 shadow-sm rounded-4 mb-3"><div class="card-body">
            <h2 class="h6">นำเข้าหลายข้อ</h2>
            <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="import">
                <label class="form-label w-100 small">หมวด (ใช้เมื่อในข้อมูลไม่ได้ระบุหมวด)<input name="category" class="form-control" maxlength="100" list="bankCats" value="<?= e($cat) ?>" placeholder="เช่น หน่วยที่ 1"></label>
                <textarea name="import_text" class="form-control font-monospace small" rows="9" placeholder="1. โจทย์ข้อที่ 1&#10;ก. ตัวเลือก&#10;ข. คำตอบที่ถูก *&#10;ค. ตัวเลือก&#10;ง. ตัวเลือก&#10;&#10;2. โจทย์ข้อที่ 2&#10;ก. ..."><?= e($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'import' ? post('import_text') : '') ?></textarea>
                <label class="form-label w-100 small mt-2">หรืออัปโหลดไฟล์ .csv / .txt<input type="file" name="import_file" accept=".csv,.txt,.tsv" class="form-control form-control-sm"></label>
                <div class="form-text">วางข้อความจาก Word (เลขข้อ + ก. ข. ค. + * ที่คำตอบถูก) หรือคัดลอกตารางจาก Excel มาวาง · <a href="<?= url('admin/quiz_import.php') ?>" target="_blank">ดูรูปแบบการนำเข้าและแม่แบบ</a></div>
                <button class="btn btn-outline-primary mt-2">นำเข้า</button>
            </form>
        </div></div>

        <?php if ($manualQuizzes): ?>
        <div class="card border-0 shadow-sm rounded-4 mb-3"><div class="card-body">
            <h2 class="h6">คัดลอกจากแบบทดสอบที่มีอยู่</h2>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="copy_quiz">
                <select name="source" class="form-select mb-2" aria-label="แบบทดสอบต้นทาง">
                    <?php foreach ($manualQuizzes as $key => $s): ?><option value="<?= e(substr($key, 5)) ?>"><?= e($s['title']) ?> (<?= count(quiz_questions((string)$s['quiz_data'])) ?> ข้อ)</option><?php endforeach; ?>
                </select>
                <input name="category" class="form-control mb-2" maxlength="100" list="bankCats" value="<?= e($cat) ?>" placeholder="ลงหมวด เช่น หน่วยที่ 1" aria-label="หมวด">
                <button class="btn btn-outline-primary">คัดลอกลงคลัง</button>
            </form>
        </div></div>
        <?php endif; ?>
        <datalist id="bankCats"><?php foreach ($categories as $name => $n): ?><option value="<?= e($name) ?>"><?php endforeach; ?></datalist>
    </div>

    <div class="col-lg-8">
        <form class="d-flex gap-2 mb-3"><input type="hidden" name="course_id" value="<?= $courseId ?>"><input type="hidden" name="cat" value="<?= e($cat) ?>">
            <input name="q" class="form-control" placeholder="ค้นหาโจทย์" value="<?= e($search) ?>"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button></form>
        <?php foreach ($rows as $n => $r): $options = json_decode($r['options_json'], true); ?>
            <div class="card border-0 shadow-sm rounded-4 mb-2"><div class="card-body">
                <div class="d-flex justify-content-between gap-2">
                    <div><span class="badge bg-light text-dark border me-1"><?= e($r['category']) ?></span><b><?= $n + 1 ?>.</b> <?= nl2br(e($r['question_text'])) ?></div>
                    <div class="text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= url($self . '&cat=' . urlencode($cat) . '&edit=' . $r['id']) ?>"><i class="bi bi-pencil"></i></a>
                        <form method="post" class="d-inline" data-confirm="ลบข้อสอบข้อนี้ออกจากคลัง?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="cat" value="<?= e($cat) ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                    </div>
                </div>
                <ol class="list-unstyled small mb-0 mt-2 ms-3">
                    <?php foreach ($options as $j => $option): ?><li class="<?= $j === (int)$r['correct'] ? 'text-success fw-semibold' : '' ?>"><?= QUIZ_LETTERS[$j] ?>. <?= e($option) ?><?= $j === (int)$r['correct'] ? ' <i class="bi bi-check-circle-fill"></i>' : '' ?></li><?php endforeach; ?>
                </ol>
            </div></div>
        <?php endforeach; ?>
        <?php if (!$rows): ?><div class="text-center text-muted py-5">ยังไม่มีข้อสอบ<?= $cat !== '' || $search !== '' ? 'ตามที่ค้นหา' : 'ในคลัง — เพิ่มทีละข้อ วางข้อความ หรือคัดลอกจากแบบทดสอบที่มีอยู่' ?></div><?php endif; ?>
        <?php if (count($rows) === 500): ?><p class="small text-muted">แสดง 500 ข้อแรก — เลือกหมวดหรือค้นหาเพื่อดูข้ออื่น</p><?php endif; ?>
    </div>
</div>
<script>
(() => {
    const letters = ['ก', 'ข', 'ค', 'ง', 'จ', 'ฉ', 'ช', 'ซ', 'ฌ', 'ญ'], box = document.getElementById('bankOptions');
    document.getElementById('addOpt').onclick = () => {
        const n = box.children.length;
        if (n >= 10) return;
        const row = box.children[0].cloneNode(true);
        row.querySelector('[type=radio]').value = n; row.querySelector('[type=radio]').checked = false;
        row.querySelector('span').textContent = letters[n] + '.'; row.querySelector('[name="options[]"]').value = '';
        box.append(row);
    };
    document.getElementById('delOpt').onclick = () => {
        if (box.children.length <= 2) return;
        const last = box.lastElementChild;
        if (last.querySelector('[type=radio]').checked) box.children[0].querySelector('[type=radio]').checked = true;
        last.remove();
    };
})();
</script>
<?php require __DIR__ . '/_bottom.php'; ?>
