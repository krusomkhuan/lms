<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$me = require_role('admin', 'teacher');
$id = get_int('id');
$lesson = $id ? db_one('SELECT * FROM lessons WHERE id = ?', [$id]) : null;
$courseId = $lesson ? (int)$lesson['course_id'] : get_int('course_id');
$course = db_one('SELECT * FROM courses WHERE id = ?', [$courseId]);
if (!$course || ($id && !$lesson) || !can_manage_course($courseId)) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์จัดการบทเรียนนี้');
}

$types = media_types();
$fileTypes = ['video', 'pdf', 'file', 'image', 'audio'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $type = array_key_exists(post('media_type'), $types) ? post('media_type') : 'text';
    $mediaUrl = in_array($type, ['text', 'file'], true) ? '' : post('media_url');
    try {
        if (post('title') === '') throw new RuntimeException('กรุณากรอกชื่อบทเรียน');
        if ($type === 'youtube' && !youtube_id($mediaUrl)) throw new RuntimeException('ลิงก์ YouTube ไม่ถูกต้อง');
        if ($mediaUrl !== '' && $type !== 'youtube' && !preg_match('~^https?://~i', $mediaUrl)) throw new RuntimeException('ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https://');
        if ($type === 'link' && $mediaUrl === '') throw new RuntimeException('กรุณาใส่ลิงก์');

        $filePath = $lesson['file_path'] ?? null;
        if (in_array($type, $fileTypes, true)) {
            $new = upload_file('file', 'lessons', allowed_ext($type));
            if ($new) {
                delete_upload($filePath);
                $filePath = $new;
            } elseif ($filePath && !in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), allowed_ext($type), true)) {
                // เปลี่ยนชนิดสื่อแล้วไฟล์เดิมไม่ตรงชนิด
                delete_upload($filePath);
                $filePath = null;
            }
            if (!$filePath && $mediaUrl === '') throw new RuntimeException('กรุณาอัปโหลดไฟล์ หรือใส่ลิงก์ไฟล์');
        } elseif ($filePath) {
            delete_upload($filePath);
            $filePath = null;
        }

        $data = [post('title'), $type, post('content'), $mediaUrl ?: null, $filePath, post('is_published') ? 1 : 0];
        if ($lesson) {
            db_exec('UPDATE lessons SET title=?, media_type=?, content=?, media_url=?, file_path=?, is_published=? WHERE id=?', array_merge($data, [$id]));
        } else {
            $order = (int)db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM lessons WHERE course_id = ?', [$courseId]);
            db_exec('INSERT INTO lessons (title,media_type,content,media_url,file_path,is_published,course_id,sort_order) VALUES (?,?,?,?,?,?,?,?)',
                array_merge($data, [$courseId, $order]));
        }
        flash('success', 'บันทึกบทเรียนเรียบร้อย');
        redirect('admin/lessons.php?course_id=' . $courseId);
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    }
}

$v = fn($k, $d = '') => $_SERVER['REQUEST_METHOD'] === 'POST' ? post($k, $d) : ($lesson[$k] ?? $d);
$curType = $v('media_type', 'text');
$pageTitle = ($lesson ? 'แก้ไขบทเรียน' : 'เพิ่มบทเรียน') . ' — ' . $course['code'];
require __DIR__ . '/_top.php';
?>
<a href="<?= url('admin/lessons.php?course_id=' . $courseId) ?>"><i class="bi bi-arrow-left"></i> <?= e($course['title']) ?></a>
<?php if ($error): ?><div class="alert alert-danger mt-3"><?= e($error) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="mt-3">
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body">
                    <label class="form-label">ชื่อบทเรียน *</label>
                    <input name="title" class="form-control form-control-lg mb-3" required value="<?= e($v('title')) ?>" placeholder="เช่น บทที่ 1 ความรู้เบื้องต้น">

                    <label class="form-label">ชนิดสื่อการสอน</label>
                    <div class="row g-2 mb-3">
                        <?php foreach ($types as $k => $t): ?>
                            <div class="col-6 col-md-3">
                                <input type="radio" class="btn-check" name="media_type" id="mt_<?= $k ?>" value="<?= $k ?>" <?= $curType === $k ? 'checked' : '' ?>>
                                <label class="btn btn-outline-<?= $t['color'] ?> w-100 py-2 small" for="mt_<?= $k ?>"><i class="bi <?= $t['icon'] ?> d-block fs-4"></i><?= e($t['label']) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="box-url" class="mb-3">
                        <label class="form-label" id="url-label">ลิงก์</label>
                        <input name="media_url" class="form-control" value="<?= e($v('media_url')) ?>" placeholder="https://">
                        <div class="form-text" id="url-help"></div>
                    </div>
                    <div id="box-file" class="mb-3">
                        <label class="form-label">อัปโหลดไฟล์ <span class="small text-muted" id="file-ext"></span></label>
                        <?php if (!empty($lesson['file_path'])): ?>
                            <div class="small mb-1">ไฟล์ปัจจุบัน: <a href="<?= e(upload_url($lesson['file_path'])) ?>" target="_blank"><?= e(basename($lesson['file_path'])) ?></a> (อัปโหลดใหม่เพื่อแทนที่)</div>
                        <?php endif; ?>
                        <input type="file" name="file" class="form-control">
                        <div class="form-text">ขนาดไม่เกิน <?= MAX_UPLOAD_MB ?> MB (และไม่เกิน upload_max_filesize ของ PHP = <?= e(ini_get('upload_max_filesize')) ?>) หรือใส่ลิงก์ไฟล์ด้านบนแทนได้</div>
                    </div>

                    <label class="form-label">เนื้อหา / คำอธิบาย / ใบงาน</label>
                    <textarea name="content" id="lessonContent" rows="12" class="form-control font-monospace small"><?= e($v('content')) ?></textarea>
                    <div class="form-text">จัดรูปแบบ ใส่ตาราง ลิงก์ และรูปภาพได้ (ลากวาง/วางรูปลงในช่องได้เลย รูปละไม่เกิน <?= MAX_UPLOAD_MB ?> MB) · ดูโค้ด HTML ได้ที่ปุ่ม &lt;/&gt;</div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="is_published" value="1" id="pub" <?= $v('is_published', 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pub">แสดงบทเรียนนี้ให้ผู้เรียน</label>
                    </div>
                    <div class="d-grid gap-2">
                        <button class="btn btn-vt btn-lg"><i class="bi bi-save"></i> บันทึก</button>
                        <a href="<?= url('admin/lessons.php?course_id=' . $courseId) ?>" class="btn btn-light">ยกเลิก</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
<script>
    (function () {
        var cfg = {
            text: {url: false, file: false},
            youtube: {url: true, file: false, label: 'ลิงก์ YouTube', help: 'เช่น https://www.youtube.com/watch?v=xxxxxxxxxxx หรือ https://youtu.be/xxxxxxxxxxx'},
            link: {url: true, file: false, label: 'ลิงก์เว็บไซต์ภายนอก', help: 'เช่น Google Drive, Canva, Padlet, Kahoot, Google Form'},
            video: {url: true, file: true, label: 'ลิงก์ไฟล์วิดีโอ (ไม่บังคับ)', ext: '<?= implode(', ', allowed_ext('video')) ?>'},
            pdf: {url: true, file: true, label: 'ลิงก์ไฟล์ PDF (ไม่บังคับ)', ext: 'pdf'},
            file: {url: false, file: true, ext: '<?= implode(', ', allowed_ext('file')) ?>'},
            image: {url: true, file: true, label: 'ลิงก์รูปภาพ (ไม่บังคับ)', ext: '<?= implode(', ', allowed_ext('image')) ?>'},
            audio: {url: true, file: true, label: 'ลิงก์ไฟล์เสียง (ไม่บังคับ)', ext: '<?= implode(', ', allowed_ext('audio')) ?>'}
        };
        function update() {
            var t = document.querySelector('input[name=media_type]:checked').value, c = cfg[t];
            document.getElementById('box-url').style.display = c.url ? '' : 'none';
            document.getElementById('box-file').style.display = c.file ? '' : 'none';
            document.getElementById('url-label').textContent = c.label || 'ลิงก์';
            document.getElementById('url-help').textContent = c.help || '';
            document.getElementById('file-ext').textContent = c.ext ? '(' + c.ext + ')' : '';
        }
        document.querySelectorAll('input[name=media_type]').forEach(function (r) { r.addEventListener('change', update); });
        update();
    })();
</script>
<?php $editorSelector = '#lessonContent'; require __DIR__ . '/_editor.php'; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
