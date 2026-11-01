<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$me = require_role('admin', 'teacher');
$mediaId = get_int('media_id');
$parentId = get_int('lesson_id');
$isMedia = $mediaId > 0 || $parentId > 0;
if ($isMedia && !lesson_media_ready()) {
    flash('warning', 'กรุณารัน database/migrations/1.10.0.sql ก่อนเพิ่มสื่อในบทเรียน');
    redirect('admin/courses.php');
}
$mediaRow = $mediaId ? db_one('SELECT * FROM lesson_media WHERE id = ?', [$mediaId]) : null;
if ($mediaId && !$mediaRow) {
    http_response_code(404);
    exit('ไม่พบสื่อ');
}
if ($mediaRow) $parentId = (int)$mediaRow['lesson_id'];
$parentLesson = $parentId ? db_one('SELECT * FROM lessons WHERE id = ?', [$parentId]) : null;
if ($isMedia && !$parentLesson) {
    http_response_code(404);
    exit('ไม่พบบทเรียน');
}
$id = get_int('id');
$lesson = $isMedia ? $mediaRow : ($id ? db_one('SELECT * FROM lessons WHERE id = ?', [$id]) : null);
if ($isMedia) $id = $mediaId;
$courseId = $parentLesson ? (int)$parentLesson['course_id'] : ($lesson ? (int)$lesson['course_id'] : get_int('course_id'));
$course = db_one('SELECT * FROM courses WHERE id = ?', [$courseId]);
if (!$course || ($id && !$lesson) || !can_manage_course($courseId)) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์จัดการบทเรียนนี้');
}

$types = media_types();
if (($lesson['media_type'] ?? '') !== 'file') unset($types['file']);
$fileTypes = ['video', 'pdf', 'file', 'image', 'audio'];
$isNewLesson = !$isMedia && !$lesson;
$linkRows = [];
$urls = (array)post('extra_urls', []);
$titles = (array)post('extra_titles', []);
$kinds = (array)post('extra_types', []);
$contents = (array)post('extra_contents', []);
$quizzes = (array)post('extra_quizzes', []);
$assignmentPoints = (array)post('extra_assignment_points', []);
$extraOpens = (array)post('extra_submission_opens_at', []);
$extraDue = (array)post('extra_submission_due_at', []);
$extraLate = (array)post('extra_accept_late', []);
foreach (array_unique(array_merge(array_keys($kinds), array_keys($urls))) as $index) {
    if (!ctype_digit((string)$index)) continue;
    $linkRows[$index] = [
        'url' => isset($urls[$index]) && is_string($urls[$index]) ? trim($urls[$index]) : '',
        'title' => isset($titles[$index]) && is_string($titles[$index]) ? trim($titles[$index]) : '',
        'type' => isset($kinds[$index]) && is_string($kinds[$index]) && isset($types[$kinds[$index]]) ? $kinds[$index] : 'link',
        'submission_opens_at' => is_string($extraOpens[$index] ?? null) ? $extraOpens[$index] : '',
        'submission_due_at' => is_string($extraDue[$index] ?? null) ? $extraDue[$index] : '',
        'accept_late' => !empty($extraLate[$index]) ? 1 : 0,
        'assignment_points' => is_string($assignmentPoints[$index] ?? null) ? $assignmentPoints[$index] : '100',
        'quiz_data' => is_string($quizzes[$index] ?? null) ? $quizzes[$index] : '[]',
        'content' => isset($contents[$index]) && is_string($contents[$index]) ? trim($contents[$index]) : '',
    ];
}
$error = '';
$createdUploads = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $type = array_key_exists(post('media_type'), $types) ? post('media_type') : 'text';
    $mediaUrl = in_array($type, ['text', 'file', 'quiz', 'assignment'], true) ? '' : post('media_url');
    try {
        $teachingDates = schedule_range(post('available_from'), post('available_until'));
        $submissionDates = $type === 'assignment' ? schedule_range(post('submission_opens_at'), post('submission_due_at')) : [null, null];
        if (!schedule_ready() && array_filter(array_merge($teachingDates, $submissionDates))) throw new RuntimeException('กรุณารัน database/migrations/1.14.0.sql ก่อนกำหนดวันเวลา');
        $points = '100.00';
        if ($type === 'assignment') {
            if (!assignments_ready()) throw new RuntimeException('กรุณารัน database/migrations/1.13.0.sql ก่อนใช้ระบบส่งงาน');
            $points = assignment_points(post('assignment_points', '100'));
        }
        $quizData = null;
        if ($type === 'quiz') {
            if (!quiz_ready()) throw new RuntimeException('กรุณารัน database/migrations/1.12.0.sql ก่อนใช้แบบทดสอบ');
            $quizData = quiz_validate(post('quiz_data'), $courseId);
        }
        if (post('title') === '') throw new RuntimeException('กรุณากรอกชื่อบทเรียน');
        if (mb_strlen(post('title')) > 200) throw new RuntimeException('ชื่อยาวได้ไม่เกิน 200 ตัวอักษร');
        if ($type === 'youtube' && !youtube_id($mediaUrl)) throw new RuntimeException('ลิงก์ YouTube ไม่ถูกต้อง');
        if ($mediaUrl !== '' && $type !== 'youtube' && !preg_match('~^https?://~i', $mediaUrl)) throw new RuntimeException('ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https://');
        if ($type === 'link' && $mediaUrl === '') throw new RuntimeException('กรุณาใส่ลิงก์');
        $pendingLinks = [];
        foreach ($linkRows as $index => $row) {
            $upload = isset($_FILES['extra_files']['error'][$index]) ? array_map(fn($values) => $values[$index] ?? null, $_FILES['extra_files']) : null;
            $hasUpload = $upload && $upload['error'] !== UPLOAD_ERR_NO_FILE;
            if (!in_array($row['type'], ['quiz', 'assignment'], true) && $row['url'] === '' && $row['title'] === '' && $row['content'] === '' && !$hasUpload) continue;
            $number = count($pendingLinks) + 1;
            if ($row['type'] === 'assignment') {
                if (!assignments_ready()) throw new RuntimeException('กรุณารัน database/migrations/1.13.0.sql ก่อนใช้ระบบส่งงาน');
                $row['assignment_points'] = assignment_points($row['assignment_points']);
                [$row['submission_opens_at'], $row['submission_due_at']] = schedule_range($row['submission_opens_at'], $row['submission_due_at']);
                if (!schedule_ready() && ($row['submission_opens_at'] || $row['submission_due_at'] || $row['accept_late'])) throw new RuntimeException('กรุณารัน database/migrations/1.14.0.sql ก่อนกำหนดวันเวลา');
            }
            if ($row['type'] === 'quiz') {
                if (!quiz_ready()) throw new RuntimeException('กรุณารัน database/migrations/1.12.0.sql ก่อนใช้แบบทดสอบ');
                $row['quiz_data'] = quiz_validate($row['quiz_data'], $courseId);
            }
            if (in_array($row['type'], ['text', 'file', 'quiz', 'assignment'], true)) $row['url'] = '';
            if ($row['url'] !== '' && (strlen($row['url']) > 500 || !filter_var($row['url'], FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $row['url']))) {
                throw new RuntimeException('ลิงก์สื่อเพิ่มเติมรายการที่ ' . $number . ' ต้องเป็น URL ที่ขึ้นต้นด้วย http:// หรือ https:// และยาวไม่เกิน 500 ตัวอักษร');
            }
            if ($row['type'] === 'youtube' && !youtube_id($row['url'])) throw new RuntimeException('ลิงก์ YouTube เพิ่มเติมบรรทัดที่ ' . $number . ' ไม่ถูกต้อง');
            if ($row['type'] === 'link' && $row['url'] === '') throw new RuntimeException('กรุณากรอกลิงก์สื่อเพิ่มเติมรายการที่ ' . $number);
            if ($row['type'] === 'text' && $row['content'] === '') throw new RuntimeException('กรุณากรอกเนื้อหาสื่อข้อความเพิ่มเติมรายการที่ ' . $number);
            if (in_array($row['type'], $fileTypes, true) && !$hasUpload && $row['url'] === '') throw new RuntimeException('กรุณาเลือกไฟล์หรือกรอกลิงก์สื่อเพิ่มเติมรายการที่ ' . $number);
            if (mb_strlen($row['title']) > 200) throw new RuntimeException('ชื่อสื่อเพิ่มเติมยาวได้ไม่เกิน 200 ตัวอักษร');
            if ($row['title'] === '') $row['title'] = mb_substr(post('title'), 0, 165) . ' — สื่อเพิ่มเติม ' . $number;
            $row['upload'] = $upload;
            $pendingLinks[] = $row;
        }
        if ($pendingLinks && !lesson_media_ready()) throw new RuntimeException('กรุณารัน database/migrations/1.10.0.sql ก่อนเพิ่มลิงก์หลายรายการ');

        $filePath = $lesson['file_path'] ?? null;
        if (in_array($type, $fileTypes, true)) {
            $new = upload_file('file', 'lessons', allowed_ext($type));
            if ($new) {
                $createdUploads[] = $new;
                $filePath = $new;
            } elseif ($filePath && !in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), allowed_ext($type), true)) {
                // เปลี่ยนชนิดสื่อแล้วไฟล์เดิมไม่ตรงชนิด
                $filePath = null;
            }
            if (!$filePath && $mediaUrl === '') throw new RuntimeException('กรุณาอัปโหลดไฟล์ หรือใส่ลิงก์ไฟล์');
        } elseif ($filePath) {
            $filePath = null;
        }

        foreach ($pendingLinks as &$row) {
            $row['file_path'] = in_array($row['type'], $fileTypes, true) && $row['upload']
                ? upload_file('extra_files', 'lessons', allowed_ext($row['type']), $row['upload']) : null;
            if ($row['file_path']) $createdUploads[] = $row['file_path'];
        }
        unset($row);

        $data = [post('title'), $type, post('content'), $mediaUrl ?: null, $filePath, post('is_published') ? 1 : 0];
        $conn->begin_transaction();
        try {
        if ($isMedia && $lesson) {
            db_exec('UPDATE lesson_media SET title=?, media_type=?, content=?, media_url=?, file_path=?, is_published=? WHERE id=? AND lesson_id=?', array_merge($data, [$mediaId, $parentId]));
        } elseif ($isMedia) {
            $order = (int)db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM lesson_media WHERE lesson_id = ?', [$parentId]);
            $mediaId = db_exec('INSERT INTO lesson_media (title,media_type,content,media_url,file_path,is_published,lesson_id,sort_order) VALUES (?,?,?,?,?,?,?,?)', array_merge($data, [$parentId, $order]));
        } elseif ($lesson) {
            db_exec('UPDATE lessons SET title=?, media_type=?, content=?, media_url=?, file_path=?, is_published=? WHERE id=?', array_merge($data, [$id]));
        } else {
            $order = (int)db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM lessons WHERE course_id = ?', [$courseId]);
            $id = db_exec('INSERT INTO lessons (title,media_type,content,media_url,file_path,is_published,course_id,sort_order) VALUES (?,?,?,?,?,?,?,?)',
                array_merge($data, [$courseId, $order]));
        }
        if (quiz_ready()) {
            $table = $isMedia ? 'lesson_media' : 'lessons';
            db_exec("UPDATE $table SET quiz_data = ? WHERE id = ?", [$quizData, $isMedia ? $mediaId : $id]);
        }
        if (assignments_ready()) {
            $table = $isMedia ? 'lesson_media' : 'lessons';
            db_exec("UPDATE $table SET assignment_points = ? WHERE id = ?", [$points, $isMedia ? $mediaId : $id]);
        }
        if (schedule_ready()) {
            $table = $isMedia ? 'lesson_media' : 'lessons';
            db_exec("UPDATE $table SET submission_opens_at=?,submission_due_at=?,accept_late=? WHERE id=?", [$submissionDates[0], $submissionDates[1], $type === 'assignment' && post('accept_late') ? 1 : 0, $isMedia ? $mediaId : $id]);
            if (!$isMedia) db_exec('UPDATE lessons SET available_from=?,available_until=? WHERE id=?', [$teachingDates[0], $teachingDates[1], $id]);
        }
        $targetLessonId = $isMedia ? $parentId : $id;
        if ($pendingLinks) {
            $order = (int)db_value('SELECT COALESCE(MAX(sort_order), 0) FROM lesson_media WHERE lesson_id = ?', [$targetLessonId]);
            foreach ($pendingLinks as $row) {
                $extraId = db_exec('INSERT INTO lesson_media (lesson_id,title,media_type,media_url,content,file_path,is_published,sort_order) VALUES (?,?,?,?,?,?,?,?)',
                    [$targetLessonId, $row['title'], $row['type'], $row['url'] ?: null, $row['content'] ?: null, $row['file_path'], post('is_published') ? 1 : 0, ++$order]);
                if ($row['type'] === 'assignment' && schedule_ready()) db_exec('UPDATE lesson_media SET submission_opens_at=?,submission_due_at=?,accept_late=? WHERE id=?', [$row['submission_opens_at'] ?: null, $row['submission_due_at'] ?: null, $row['accept_late'], $extraId]);
                if ($row['type'] === 'assignment') db_exec('UPDATE lesson_media SET assignment_points = ? WHERE id = ?', [$row['assignment_points'], $extraId]);
                if ($row['type'] === 'quiz') db_exec('UPDATE lesson_media SET quiz_data = ? WHERE id = ?', [$row['quiz_data'], $extraId]);
            }
        }
        $conn->commit();
        } catch (Throwable $ex) {
            $conn->rollback();
            throw new RuntimeException('บันทึกสื่อไม่สำเร็จ กรุณาลองอีกครั้ง');
        }
        if (!empty($lesson['file_path']) && $lesson['file_path'] !== $filePath) delete_upload($lesson['file_path']);
        flash('success', $isMedia ? 'เพิ่ม/บันทึกสื่อเรียบร้อย คลิกเพิ่มสื่อเพื่อเพิ่มรายการถัดไปได้' : 'บันทึกบทเรียนเรียบร้อย สามารถเพิ่มสื่อในบทเรียนได้');
        if ($isMedia) redirect('admin/lesson_form.php?id=' . $parentId . '#media-list');
        if ($pendingLinks) redirect('admin/lesson_form.php?id=' . $id . '#media-list');
        if (!$lesson) redirect('admin/lesson_form.php?id=' . $id . '#media-list');
        redirect('admin/lessons.php?course_id=' . $courseId);
    } catch (RuntimeException $ex) {
        foreach ($createdUploads as $path) delete_upload($path);
        $error = $ex->getMessage();
    }
}

$v = fn($k, $d = '') => $_SERVER['REQUEST_METHOD'] === 'POST' ? post($k, $d) : ($lesson[$k] ?? $d);
$curType = $v('media_type', 'text');
$pageTitle = ($isMedia ? ($lesson ? 'แก้ไขสื่อ' : 'เพิ่มสื่อในบทเรียน') : ($lesson ? 'แก้ไขบทเรียน' : 'เพิ่มบทเรียน')) . ' — ' . $course['code'];
require __DIR__ . '/_top.php';
?>
<a href="<?= url('admin/lessons.php?course_id=' . $courseId) ?>"><i class="bi bi-arrow-left"></i> <?= e($course['title']) ?></a>
<?php if ($isMedia): ?><p class="mt-3">บทเรียน: <a href="<?= url('admin/lesson_form.php?id=' . $parentId) ?>"><?= e($parentLesson['title']) ?></a> · สื่อแต่ละรายการจะแสดงแยกกันเมื่อผู้เรียนคลิกเลือก</p><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger mt-3"><?= e($error) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="mt-3">
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body">
                    <label class="form-label"><?= $isMedia ? 'ชื่อสื่อ' : 'ชื่อบทเรียน' ?> *</label>
                    <input name="title" class="form-control form-control-lg mb-3" required maxlength="200" value="<?= e($v('title')) ?>" placeholder="<?= $isMedia ? 'เช่น วิดีโอแนะนำ / ใบงาน PDF / เสียงบรรยาย' : 'เช่น บทที่ 1 ความรู้เบื้องต้น' ?>">

                    <label class="form-label"><?= $isNewLesson && lesson_media_ready() ? 'เพิ่มสื่อในบทเรียนนี้ (เพิ่มได้หลายชนิด)' : 'ชนิดสื่อการสอนหลัก' ?></label>
                    <?php if ($isNewLesson && lesson_media_ready()): ?>
                        <input type="hidden" name="media_type" value="text">
                        <p class="small text-muted">กดชนิดสื่อเพื่อเพิ่มรายการใหม่ เพิ่มชนิดเดิมซ้ำได้ ข้อมูลแต่ละรายการจะแยกกัน และบันทึกพร้อมบทเรียนครั้งเดียว</p>
                    <?php endif; ?>
                    <div class="row g-2 mb-3">
                        <?php foreach ($types as $k => $t): ?>
                            <div class="col-6 col-md-3">
                                <?php if ($isNewLesson && lesson_media_ready()): ?>
                                    <button type="button" class="btn btn-outline-<?= $t['color'] ?> w-100 py-2 small add-media-kind" data-media-type="<?= $k ?>"><i class="bi <?= $t['icon'] ?> d-block fs-4"></i><?= e($t['label']) ?></button>
                                <?php else: ?>
                                <input type="radio" class="btn-check" name="media_type" id="mt_<?= $k ?>" value="<?= $k ?>" <?= $curType === $k ? 'checked' : '' ?>>
                                <label class="btn btn-outline-<?= $t['color'] ?> w-100 py-2 small" for="mt_<?= $k ?>"><i class="bi <?= $t['icon'] ?> d-block fs-4"></i><?= e($t['label']) ?></label>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!$isMedia && !$isNewLesson): ?>
                        <div class="mb-3">
                            <button type="button" id="remove-primary-media" class="btn btn-sm btn-outline-danger">ลบสื่อหลักรายการนี้</button>
                            <div id="remove-primary-status" class="form-text" role="status">ลบลิงก์หรือไฟล์สื่อหลัก โดยเก็บบทเรียนและคำอธิบายไว้ กดบันทึกเพื่อยืนยัน</div>
                        </div>
                    <?php endif; ?>
                    <div id="box-assignment" class="mb-3">
                        <label class="form-label">คะแนนเต็มของงาน<input type="number" name="assignment_points" min="0.01" max="10000" step="0.01" class="form-control" value="<?= e($v('assignment_points', '100')) ?>"></label>
                        <?php $schedulePrefix = ['open' => 'submission_opens_at', 'due' => 'submission_due_at', 'late' => 'accept_late']; $scheduleValues = ['submission_opens_at' => $v('submission_opens_at'), 'submission_due_at' => $v('submission_due_at'), 'accept_late' => $v('accept_late', 0)]; require __DIR__ . '/_assignment_schedule_fields.php'; ?>
                        <p class="form-text">กรอกคำสั่งงานในช่องเนื้อหา / คำอธิบายด้านล่าง นักเรียนส่งข้อความหรือไฟล์ได้ ครูตรวจ ให้คะแนน และส่งข้อเสนอแนะได้</p>
                    </div>
                    <div id="box-quiz">
                        <?php $quizName = 'quiz_data'; $quizValue = $v('quiz_data', '[]'); require __DIR__ . '/_quiz_builder.php'; ?>
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

                    <?php if (lesson_media_ready()): ?>
                        <section class="mb-3" aria-label="สื่อหลายชนิดในบทเรียน">
                            <?php if (!$isNewLesson): ?>
                                <div class="small fw-semibold mb-2">เพิ่มสื่ออื่นในบทเรียนเดียวกัน</div>
                                <div class="d-flex flex-wrap gap-2 mb-3">
                                    <?php foreach ($types as $kind => $definition): ?><button type="button" class="btn btn-sm btn-outline-<?= $definition['color'] ?> add-media-kind" data-media-type="<?= $kind ?>"><i class="bi <?= $definition['icon'] ?>"></i> เพิ่ม<?= e($definition['label']) ?></button><?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div id="extra-media-count" class="small fw-semibold mb-2" aria-live="polite"></div>
                            <div id="extra-link-rows">
                                <?php foreach ($linkRows as $index => $row): ?>
                                    <?php $rowIndex = (string)$index; require __DIR__ . '/_lesson_media_fields.php'; ?>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" id="add-link-row" class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg"></i> เพิ่มลิงก์อีกบรรทัด</button>
                            <div class="form-text">เลือกชนิดสื่อด้านบนเพื่อเพิ่มข้อความ วิดีโอ PDF งานมอบหมาย แบบทดสอบ รูปภาพ เสียง หรือลิงก์ แต่ละรายการมีช่องกรอกและอัปโหลดแยกกัน</div>
                        </section>
                        <template id="extra-link-template">
                            <?php $row = ['type' => 'link', 'title' => '', 'url' => '', 'content' => '']; $rowIndex = '__ROW__'; require __DIR__ . '/_lesson_media_fields.php'; ?>
                        </template>
                    <?php endif; ?>

                    <label class="form-label"><?= $isNewLesson ? 'เนื้อหา / คำอธิบายหลักของบทเรียน (ไม่บังคับ)' : 'เนื้อหา / คำอธิบาย / ใบงาน' ?></label>
                    <textarea name="content" id="lessonContent" rows="12" class="form-control font-monospace small"><?= e($v('content')) ?></textarea>
                    <div class="form-text">จัดรูปแบบ ใส่ตาราง ลิงก์ และรูปภาพได้ (ลากวาง/วางรูปลงในช่องได้เลย รูปละไม่เกิน <?= MAX_UPLOAD_MB ?> MB) · ดูโค้ด HTML ได้ที่ปุ่ม &lt;/&gt;</div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body">
                    <?php if (!$isMedia): ?>
                        <div class="mb-3 border-bottom pb-3">
                            <h2 class="h6">วันเวลาเปิด–ปิดการสอน</h2>
                            <label class="form-label w-100">เปิดบทเรียน<input type="datetime-local" name="available_from" class="form-control" value="<?= e(schedule_input_value($v('available_from'))) ?>"></label>
                            <label class="form-label w-100">ปิดบทเรียน<input type="datetime-local" name="available_until" class="form-control" value="<?= e(schedule_input_value($v('available_until'))) ?>"></label>
                            <p class="form-text">เวลาประเทศไทย เว้นว่างเพื่อไม่จำกัดเวลา ใช้กับสื่อทุกชิ้นในบทเรียน ครูเปิดดูได้ตลอด</p>
                        </div>
                    <?php endif; ?>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="is_published" value="1" id="pub" <?= $v('is_published', 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pub"><?= $isMedia ? 'แสดงสื่อนี้ให้ผู้เรียน' : 'แสดงบทเรียนนี้ให้ผู้เรียน' ?></label>
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
<?php if ($isMedia && $lesson): ?>
    <form method="post" action="<?= url('admin/lesson_media_action.php') ?>" class="mt-3" data-confirm="ลบสื่อรายการนี้ออกจากบทเรียน?">
        <?= csrf_field() ?><input type="hidden" name="media_id" value="<?= (int)$mediaId ?>">
        <button class="btn btn-outline-danger" name="action" value="delete">ลบสื่อรายการนี้</button>
    </form>
<?php endif; ?>
<?php if (!$isMedia && $lesson): ?>
    <?php require __DIR__ . '/_lesson_media_list.php'; ?>
<?php endif; ?>
<script>
    (function () {
        var cfg = {
            text: {url: false, file: false},
            quiz: {url: false, file: false},
            assignment: {url: false, file: false},
            youtube: {url: true, file: false, label: 'ลิงก์ YouTube', help: 'เช่น https://www.youtube.com/watch?v=xxxxxxxxxxx หรือ https://youtu.be/xxxxxxxxxxx'},
            link: {url: true, file: false, label: 'ลิงก์เว็บไซต์ภายนอก', help: 'เช่น Google Drive, Canva, Padlet, Kahoot, Google Form'},
            video: {url: true, file: true, label: 'ลิงก์ไฟล์วิดีโอ (ไม่บังคับ)', ext: '<?= implode(', ', allowed_ext('video')) ?>'},
            pdf: {url: true, file: true, label: 'ลิงก์ไฟล์ PDF (ไม่บังคับ)', ext: 'pdf'},
            file: {url: false, file: true, ext: '<?= implode(', ', allowed_ext('file')) ?>'},
            image: {url: true, file: true, label: 'ลิงก์รูปภาพ (ไม่บังคับ)', ext: '<?= implode(', ', allowed_ext('image')) ?>'},
            audio: {url: true, file: true, label: 'ลิงก์ไฟล์เสียง (ไม่บังคับ)', ext: '<?= implode(', ', allowed_ext('audio')) ?>'}
        };
        function update() {
            var input = document.querySelector('input[name=media_type]:checked') || document.querySelector('input[name=media_type]');
            var t = input.value, c = cfg[t];
            document.getElementById('box-assignment').hidden = t !== 'assignment';
            document.querySelector('input[name=assignment_points]').disabled = t !== 'assignment';
            document.querySelectorAll('#box-assignment .assignment-schedule-input').forEach(function (input) { input.disabled = t !== 'assignment'; });
            document.getElementById('box-quiz').hidden = t !== 'quiz';
            document.getElementById('box-url').style.display = c.url ? '' : 'none';
            document.getElementById('box-file').style.display = c.file ? '' : 'none';
            document.getElementById('url-label').textContent = c.label || 'ลิงก์';
            document.getElementById('url-help').textContent = c.help || '';
            document.getElementById('file-ext').textContent = c.ext ? '(' + c.ext + ')' : '';
            var removePrimary = document.getElementById('remove-primary-media');
            if (removePrimary) removePrimary.hidden = t === 'text';
        }
        var removePrimary = document.getElementById('remove-primary-media');
        if (removePrimary) removePrimary.addEventListener('click', function () {
            document.getElementById('mt_text').checked = true;
            document.querySelector('input[name=media_url]').value = '';
            document.querySelector('input[name=file]').value = '';
            document.getElementById('remove-primary-status').textContent = 'นำสื่อหลักออกแล้ว กดบันทึกเพื่อยืนยันการลบ (บทเรียนและคำอธิบายยังคงอยู่)';
            update();
        });
        document.querySelectorAll('input[name=media_type]').forEach(function (r) { r.addEventListener('change', update); });
        var addLink = document.getElementById('add-link-row'), linkRows = document.getElementById('extra-link-rows');
        if (addLink) {
            var nextIndex = <?= $linkRows ? max(array_keys($linkRows)) + 1 : 0 ?>;
            var extensions = <?= json_encode(array_combine($fileTypes, array_map('allowed_ext', $fileTypes))) ?>;
            function updateRows() {
                var rows = linkRows.querySelectorAll('.extra-link-row');
                rows.forEach(function (row, index) {
                    var select = row.querySelector('.media-row-type'), type = select.value;
                    row.querySelector('.media-row-heading').textContent = 'สื่อรายการที่ ' + (index + 1) + ' · ' + select.options[select.selectedIndex].text;
                    row.querySelector('.media-row-assignment').hidden = type !== 'assignment';
                    row.querySelector('.assignment-points').disabled = type !== 'assignment';
                    row.querySelectorAll('.assignment-schedule-input').forEach(function (input) { input.disabled = type !== 'assignment'; });
                    row.querySelector('.media-row-quiz').hidden = type !== 'quiz';
                    row.querySelector('.media-row-url').hidden = !cfg[type].url;
                    row.querySelector('.media-row-file').hidden = !cfg[type].file;
                    var file = row.querySelector('input[type=file]');
                    file.disabled = !cfg[type].file;
                    file.accept = extensions[type] ? '.' + extensions[type].join(',.') : '';
                    var url = row.querySelector('input[type=url]');
                    url.disabled = !cfg[type].url;
                    row.querySelector('.media-row-content-label').textContent = type === 'assignment' ? 'คำสั่งงาน / รายละเอียดงาน' : (type === 'text' ? 'เนื้อหาข้อความ' : 'คำอธิบายสื่อ (ไม่บังคับ)');
                });
                document.getElementById('extra-media-count').textContent = rows.length ? 'สื่อที่เพิ่มในครั้งนี้ ' + rows.length + ' รายการ' : '';
            }
            function addMedia(type) {
                var fragment = document.getElementById('extra-link-template').content.cloneNode(true);
                var row = fragment.querySelector('.extra-link-row');
                row.querySelectorAll('[name]').forEach(function (field) { field.name = field.name.replace('__ROW__', nextIndex); });
                nextIndex++;
                row.querySelector('select').value = type;
                linkRows.appendChild(fragment);
                updateRows();
                if (window.initQuizBuilders) window.initQuizBuilders();
                row.querySelector('input[name^="extra_titles"]').focus();
            }
            addLink.addEventListener('click', function () { addMedia('link'); });
            document.querySelectorAll('.add-media-kind').forEach(function (button) {
                button.addEventListener('click', function () { addMedia(button.getAttribute('data-media-type')); });
            });
            linkRows.addEventListener('change', function (event) { if (event.target.matches('.media-row-type')) updateRows(); });
            linkRows.addEventListener('click', function (event) {
                var remove = event.target.closest('.remove-link');
                if (remove) { remove.closest('.extra-link-row').remove(); updateRows(); }
            });
            updateRows();
        }
        update();
    })();
</script>
<script src="<?= url('assets/js/quiz-builder.js') ?>"></script>
<?php $editorSelector = '#lessonContent'; require __DIR__ . '/_editor.php'; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
