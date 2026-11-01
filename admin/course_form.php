<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/cover.php';

$me = require_role('admin', 'teacher');
$id = get_int('id');
$course = $id ? db_one('SELECT * FROM courses WHERE id = ?', [$id]) : null;
if ($id && (!$course || !can_manage_course($id))) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์แก้ไขรายวิชานี้');
}

$teachersAll = db_all("SELECT id, fullname, username FROM users WHERE role IN ('teacher','admin') AND status = 'active' ORDER BY role DESC, fullname");
$groupsAll = db_all('SELECT id, name FROM student_groups ORDER BY name');
$selTeachers = $id ? array_map('intval', array_column(db_all('SELECT teacher_id FROM course_teachers WHERE course_id = ?', [$id]), 'teacher_id')) : [(int)$me['id']];
$selGroups = $id ? array_map('intval', array_column(db_all('SELECT group_id FROM course_groups WHERE course_id = ?', [$id]), 'group_id')) : [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $selTeachers = array_map('intval', (array)post('teachers', []));
    $selGroups = array_map('intval', (array)post('groups', []));
    // ครูที่แก้ไขต้องยังอยู่ในรายชื่อผู้สอน (ป้องกันตัดสิทธิ์ตัวเองโดยไม่ตั้งใจ)
    if ($me['role'] === 'teacher' && !in_array((int)$me['id'], $selTeachers, true)) $selTeachers[] = (int)$me['id'];
    try {
        if (post('code') === '' || post('title') === '') throw new RuntimeException('กรุณากรอกรหัสวิชาและชื่อวิชา');
        $cover = upload_file('cover', 'covers', allowed_ext('cover'));
        $data = [post('code'), post('title'), post('description'), post('credits') ?: null,
            post('enroll_type') === 'group' ? 'group' : 'open', post('status') === 'published' ? 'published' : 'draft'];
        if ($course) {
            if ($cover) delete_upload($course['cover']);
            db_exec('UPDATE courses SET code=?, title=?, description=?, credits=?, enroll_type=?, status=?, cover=? WHERE id=?',
                array_merge($data, [$cover ?: $course['cover'], $id]));
        } else {
            $id = db_exec('INSERT INTO courses (code,title,description,credits,enroll_type,status,cover,created_by) VALUES (?,?,?,?,?,?,?,?)',
                array_merge($data, [$cover, (int)$me['id']]));
        }
        db_exec('UPDATE courses SET ai_student_enabled = ? WHERE id = ?', [post('ai_student_enabled') ? 1 : 0, $id]);
        require_once ROOT_PATH . '/includes/lesson_lock.php';
        if (lesson_lock_ready()) db_exec('UPDATE courses SET sequential_lessons = ? WHERE id = ?', [post('sequential_lessons') ? 1 : 0, $id]);
        // ไม่ได้อัปโหลดภาพเอง + เลือกสร้างอัตโนมัติ → สร้างภาพปกใหม่จากชื่อ/คำอธิบายล่าสุด
        if (!$cover && post('auto_cover')) {
            delete_upload($course['cover'] ?? null);
            db_exec('UPDATE courses SET cover = ? WHERE id = ?', [cover_generate($id, post('code'), post('title'), post('description')), $id]);
        }
        db_exec('DELETE FROM course_teachers WHERE course_id = ?', [$id]);
        foreach (array_unique($selTeachers) as $t) db_exec('INSERT IGNORE INTO course_teachers VALUES (?, ?)', [$id, $t]);
        db_exec('DELETE FROM course_groups WHERE course_id = ?', [$id]);
        foreach (array_unique($selGroups) as $g) db_exec('INSERT IGNORE INTO course_groups VALUES (?, ?)', [$id, $g]);

        flash('success', 'บันทึกรายวิชาเรียบร้อย');
        redirect($course ? 'admin/courses.php' : 'admin/lessons.php?course_id=' . $id);
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    }
}

$v = fn($k, $d = '') => $_SERVER['REQUEST_METHOD'] === 'POST' ? post($k, $d) : ($course[$k] ?? $d);
$pageTitle = $course ? 'แก้ไขรายวิชา' : 'เพิ่มรายวิชาใหม่';
require __DIR__ . '/_top.php';
?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">รหัสวิชา *</label><input name="code" class="form-control" required value="<?= e($v('code')) ?>"></div>
                        <div class="col-md-8"><label class="form-label">ชื่อรายวิชา *</label><input name="title" class="form-control" required value="<?= e($v('title')) ?>"></div>
                        <div class="col-md-6"><label class="form-label">หน่วยกิต (ท-ป-น)</label><input name="credits" class="form-control" placeholder="เช่น 3 (2-2-3)" value="<?= e($v('credits')) ?>"></div>
                        <div class="col-12"><label class="form-label">คำอธิบายรายวิชา</label><textarea name="description" rows="5" class="form-control"><?= e($v('description')) ?></textarea></div>
                        <?php
                        // ค่าเริ่มต้น: ติ๊กสร้างอัตโนมัติ ถ้ายังไม่มีภาพปก หรือภาพปกเดิมสร้างอัตโนมัติ (จะอัปเดตตามชื่อวิชาที่แก้)
                        $autoChecked = $_SERVER['REQUEST_METHOD'] === 'POST' ? (bool)post('auto_cover') : (empty($course['cover']) || is_auto_cover($course['cover']));
                        ?>
                        <div class="col-12"><label class="form-label">ภาพปก</label>
                            <div class="row g-3 align-items-start">
                                <div class="col-md-6">
                                    <img id="coverPreview" src="<?= e($autoChecked ? url('admin/cover_preview.php?' . http_build_query(['code' => $v('code'), 'title' => $v('title'), 'desc' => $v('description')])) : course_cover($course ?? [])) ?>"
                                         data-current="<?= e(course_cover($course ?? [])) ?>" class="img-fluid rounded-3 border" style="aspect-ratio:16/9;object-fit:cover;width:100%" alt="">
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="auto_cover" value="1" id="autoCover" <?= $autoChecked ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="autoCover"><b>สร้างภาพปกอัตโนมัติ</b> จากชื่อวิชาและคำอธิบายรายวิชา</label>
                                        <div class="form-text">ระบบเลือกไอคอนและสีให้ตรงกับเนื้อหาวิชา (เช่น โปรแกรม ไฟฟ้า บัญชี ธุรกิจ) — ตัวอย่างอัปเดตทันทีเมื่อพิมพ์</div>
                                    </div>
                                    <label class="form-label small mb-1">หรืออัปโหลดภาพปกเอง (ใช้แทนภาพอัตโนมัติ)</label>
                                    <input type="file" name="cover" id="coverFile" accept="image/*" class="form-control form-control-sm">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 mt-4">
                <div class="card-header bg-white"><b><i class="bi bi-person-video3"></i> ครูผู้สอน</b> <span class="small text-muted">(เลือกได้หลายคน)</span></div>
                <div class="card-body row">
                    <?php foreach ($teachersAll as $t): ?>
                        <div class="col-md-6"><div class="form-check">
                            <input class="form-check-input" type="checkbox" name="teachers[]" value="<?= $t['id'] ?>" id="t<?= $t['id'] ?>" <?= in_array((int)$t['id'], $selTeachers, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="t<?= $t['id'] ?>"><?= e($t['fullname']) ?> <span class="text-muted small">(<?= e($t['username']) ?>)</span></label>
                        </div></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body">
                    <label class="form-label">สถานะ</label>
                    <select name="status" class="form-select mb-3">
                        <option value="draft" <?= $v('status') === 'draft' ? 'selected' : '' ?>>ฉบับร่าง (ยังไม่แสดง)</option>
                        <option value="published" <?= $v('status') === 'published' ? 'selected' : '' ?>>เผยแพร่</option>
                    </select>
                    <label class="form-label">การลงทะเบียนเรียน</label>
                    <select name="enroll_type" class="form-select mb-1">
                        <option value="open" <?= $v('enroll_type') === 'open' ? 'selected' : '' ?>>เปิดให้สมาชิกลงทะเบียนเอง</option>
                        <option value="group" <?= $v('enroll_type', 'group') === 'group' ? 'selected' : '' ?>>เฉพาะกลุ่มที่ครูอนุมัติเข้าเรียน</option>
                    </select>
                    <div class="form-text mb-2">กลุ่มที่เลือกด้านล่างเข้าเรียนได้เสมอ ไม่ว่าจะตั้งค่าแบบใด</div>
                    <?php $seqOn = $_SERVER['REQUEST_METHOD'] === 'POST' ? (bool)post('sequential_lessons') : (int)($course['sequential_lessons'] ?? 1) === 1; ?>
                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" name="sequential_lessons" value="1" id="seqLessons" <?= $seqOn ? 'checked' : '' ?>>
                        <label class="form-check-label" for="seqLessons"><i class="bi bi-lock"></i> บังคับเรียนตามลำดับบทเรียน</label>
                    </div>
                    <div class="form-text">ผู้เรียนต้องกด “เรียนจบบทนี้” ก่อนจึงเปิดบทถัดไปได้ (บทที่ยังไม่เปิดหรือปิดการเรียนแล้วไม่ขวางบทถัดไป)</div>
                    <?php if (setting('ai_enabled') === '1'): ?>
                        <?php $aiOn = $_SERVER['REQUEST_METHOD'] === 'POST' ? (bool)post('ai_student_enabled') : (int)($course['ai_student_enabled'] ?? 1) === 1; ?>
                        <div class="form-check form-switch mt-3">
                            <input class="form-check-input" type="checkbox" name="ai_student_enabled" value="1" id="aiStu" <?= $aiOn ? 'checked' : '' ?>>
                            <label class="form-check-label" for="aiStu"><i class="bi bi-stars text-primary"></i> เปิดผู้ช่วย AI ให้ผู้เรียน</label>
                        </div>
                        <div class="form-text">ปิดชั่วคราวได้ เช่น ช่วงสอบ</div>
                    <?php else: ?>
                        <input type="hidden" name="ai_student_enabled" value="<?= (int)($course['ai_student_enabled'] ?? 1) ?>">
                    <?php endif; ?>
                </div>
            </div>
            <div class="card border-0 shadow-sm rounded-4 mt-4">
                <div class="card-header bg-white"><b><i class="bi bi-people"></i> อนุมัติกลุ่มเรียนเข้ารายวิชา</b></div>
                <div class="card-body">
                    <p class="small text-muted">เลือกกลุ่มแล้วกดบันทึกเพื่ออนุมัติให้สมาชิกทั้งกลุ่มเข้าเรียน รวมถึงนักเรียนที่สมัครเข้ากลุ่มภายหลัง ยกเลิกการเลือกเพื่อถอนสิทธิ์ของกลุ่มในวิชานี้</p>
                    <?php foreach ($groupsAll as $g): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="groups[]" value="<?= $g['id'] ?>" id="g<?= $g['id'] ?>" <?= in_array((int)$g['id'], $selGroups, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="g<?= $g['id'] ?>"><?= e($g['name']) ?></label>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$groupsAll): ?><div class="small text-muted">ยังไม่มีกลุ่ม — <a href="<?= url('admin/group_form.php') ?>">สร้างกลุ่ม</a></div><?php endif; ?>
                </div>
            </div>
            <div class="d-grid gap-2 mt-4">
                <button class="btn btn-vt btn-lg"><i class="bi bi-save"></i> บันทึก</button>
                <a href="<?= url('admin/courses.php') ?>" class="btn btn-light">ยกเลิก</a>
            </div>
        </div>
    </div>
</form>
<script>
    (function () {
        var form = document.querySelector('form[enctype]'), img = document.getElementById('coverPreview'),
            auto = document.getElementById('autoCover'), file = document.getElementById('coverFile'), timer;
        var base = '<?= url('admin/cover_preview.php') ?>';
        function refresh() {
            if (file.files.length) {                     // เลือกไฟล์เอง → แสดงไฟล์นั้น
                img.src = URL.createObjectURL(file.files[0]);
            } else if (auto.checked) {
                var q = new URLSearchParams({code: form.code.value, title: form.title.value, desc: form.description.value});
                img.src = base + '?' + q.toString();
            } else {
                img.src = img.getAttribute('data-current');
            }
        }
        ['code', 'title', 'description'].forEach(function (n) {
            form[n].addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(refresh, 400); });
        });
        auto.addEventListener('change', refresh);
        file.addEventListener('change', function () { if (file.files.length) auto.checked = false; refresh(); });
    })();
</script>
<?php require __DIR__ . '/_bottom.php'; ?>
