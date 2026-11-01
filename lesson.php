<?php
require_once __DIR__ . '/includes/bootstrap.php';

$me = require_login();
$id = get_int('id');
$lesson = db_one('SELECT l.*, c.title AS course_title, c.code AS course_code, c.status AS course_status, c.ai_student_enabled
                  FROM lessons l JOIN courses c ON c.id = l.course_id WHERE l.id = ?', [$id]);
if (!$lesson) {
    http_response_code(404);
    exit('ไม่พบบทเรียน');
}
$courseId = (int)$lesson['course_id'];
$canManage = can_manage_course($courseId);
if (!$canManage && (!is_enrolled($courseId, (int)$me['id']) || !$lesson['is_published'] || $lesson['course_status'] !== 'published')) {
    flash('warning', 'คุณยังไม่มีสิทธิ์เข้าเรียนบทเรียนนี้');
    redirect('course.php?id=' . $courseId);
}

if (!$canManage && !lesson_schedule_open($lesson)) {
    flash('warning', 'บทเรียนนี้ยังไม่เปิดหรือปิดการเรียนแล้ว · ' . lesson_schedule_label($lesson));
    redirect('course.php?id=' . $courseId);
}

require_once ROOT_PATH . '/includes/lesson_lock.php';
$locks = $canManage ? [] : lesson_locks($courseId, (int)$me['id']);
if (isset($locks[$id])) {
    flash('warning', 'รายวิชานี้ต้องเรียนตามลำดับ · กรุณาเรียน “' . $locks[$id]['title'] . '” ให้จบก่อน');
    redirect('course.php?id=' . $courseId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array(post('action'), ['complete', 'undo', 'submit_quiz', 'quiz_leave', 'submit_assignment'], true)) {
    http_response_code(400);
    exit('ข้อมูลส่งไม่ครบหรือไฟล์ใหญ่เกินที่เซิร์ฟเวอร์กำหนด กรุณาย้อนกลับแล้วลองใหม่');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('action'), ['complete', 'undo'], true)) {
    verify_csrf();
    if (post('action') === 'complete') {
        db_exec('INSERT IGNORE INTO lesson_progress (user_id, lesson_id) VALUES (?, ?)', [(int)$me['id'], $id]);
    } else {
        db_exec('DELETE FROM lesson_progress WHERE user_id = ? AND lesson_id = ?', [(int)$me['id'], $id]);
    }
    $nextId = (int)post('next_id');
    redirect('lesson.php?id=' . (post('action') === 'complete' && $nextId ? $nextId : $id));
}

$lessons = db_all('SELECT id, title, media_type, is_published FROM lessons WHERE course_id = ?' . ($canManage ? '' : ' AND is_published = 1' . lesson_schedule_sql()) . ' ORDER BY sort_order, id', [$courseId]);
$mediaByLesson = course_lesson_media($courseId, $canManage);
$selectedMediaId = get_int('media_id');
$media = $lesson;
if ($selectedMediaId) {
    $media = null;
    foreach ($mediaByLesson[$id] ?? [] as $candidate) {
        if ((int)$candidate['id'] === $selectedMediaId) { $media = $candidate; break; }
    }
    if (!$media) {
        http_response_code(404);
        exit('ไม่พบสื่อหรือสื่อนี้ยังไม่เผยแพร่');
    }
}
require __DIR__ . '/includes/quiz_submit.php';
require __DIR__ . '/includes/assignment_submit.php';
$done = array_column(db_all('SELECT lesson_id FROM lesson_progress WHERE user_id = ?', [(int)$me['id']]), 'lesson_id');
$ids = array_column($lessons, 'id');
$pos = array_search($id, $ids);
$prevId = $pos > 0 ? $ids[$pos - 1] : null;
$nextId = $pos !== false && $pos < count($ids) - 1 ? $ids[$pos + 1] : null;
$isDone = in_array($id, $done);
$types = media_types();
$fileUrl = upload_url($media['file_path']);
$progress = course_progress($courseId, (int)$me['id']);

$pageTitle = $lesson['title'];
require __DIR__ . '/includes/header.php';
?>
<div class="container-fluid px-lg-4 py-3">
    <div class="row g-4">
        <aside class="col-lg-3 order-1">
            <div class="card border-0 shadow-sm rounded-4 sticky-lg-top" style="top:80px">
                <div class="card-body pb-2">
                    <a href="<?= url('course.php?id=' . $courseId) ?>" class="small"><i class="bi bi-arrow-left"></i> กลับหน้ารายวิชา</a>
                    <h6 class="mt-2 mb-1"><?= e($lesson['course_code'] . ' ' . $lesson['course_title']) ?></h6>
                    <div class="progress mt-2" style="height:6px"><div class="progress-bar bg-success" style="width:<?= $progress ?>%"></div></div>
                    <div class="small text-muted mt-1">ความก้าวหน้า <?= $progress ?>%</div>
                    <div class="small fw-semibold mt-3 mb-1">บทเรียนและรายการสื่อ</div>
                </div>
                <div class="list-group list-group-flush lesson-list">
                    <?php foreach ($lessons as $i => $l): ?>
                        <?php if (isset($locks[(int)$l['id']])): ?>
                            <div class="list-group-item bg-light small text-muted d-flex gap-2" title="เรียน “<?= e($locks[(int)$l['id']]['title']) ?>” ให้จบก่อน">
                                <i class="bi bi-lock"></i>
                                <span class="flex-grow-1"><?= e($l['title']) ?><small class="d-block">ล็อก · เรียนบทก่อนหน้าให้จบก่อน</small></span>
                            </div>
                            <?php continue; ?>
                        <?php endif; ?>
                        <div class="list-group-item bg-light small fw-semibold d-flex gap-2">
                            <i class="bi <?= in_array($l['id'], $done) ? 'bi-check-circle-fill text-success' : $types[$l['media_type']]['icon'] ?>"></i>
                            <span class="flex-grow-1"><?= e($l['title']) ?></span>
                            <span class="badge bg-secondary align-self-start"><?= 1 + count($mediaByLesson[(int)$l['id']] ?? []) ?></span>
                        </div>
                        <a href="<?= url('lesson.php?id=' . $l['id']) ?>" class="list-group-item list-group-item-action small d-flex gap-2 ps-4 <?= $l['id'] == $id && !$selectedMediaId ? 'active' : '' ?>" <?= $l['id'] == $id && !$selectedMediaId ? 'aria-current="page"' : '' ?>>
                            <i class="bi <?= $types[$l['media_type']]['icon'] ?>"></i>
                            <span class="flex-grow-1">เนื้อหาหลัก · <?= e($types[$l['media_type']]['label']) ?><?= !$l['is_published'] ? ' (ซ่อนบทเรียน)' : '' ?></span>
                        </a>
                        <?php foreach ($mediaByLesson[(int)$l['id']] ?? [] as $entry): ?>
                            <a href="<?= url('lesson.php?id=' . $l['id'] . '&media_id=' . $entry['id']) ?>" class="list-group-item list-group-item-action small d-flex gap-2 ps-4 <?= $l['id'] == $id && (int)$entry['id'] === $selectedMediaId ? 'active' : '' ?>" <?= $l['id'] == $id && (int)$entry['id'] === $selectedMediaId ? 'aria-current="page"' : '' ?>>
                                <i class="bi <?= $types[$entry['media_type']]['icon'] ?>"></i>
                                <span class="flex-grow-1"><?= e($entry['title']) ?><small class="d-block"><?= e($types[$entry['media_type']]['label']) ?><?= !$entry['is_published'] ? ' · ซ่อน' : '' ?></small></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </aside>

        <main class="col-lg-9 order-2" id="selected-media">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                        <div>
                            <span class="badge bg-<?= $types[$media['media_type']]['color'] ?>"><i class="bi <?= $types[$media['media_type']]['icon'] ?>"></i> <?= e($types[$media['media_type']]['label']) ?></span>
                            <h3 class="mt-2 mb-0"><?= e($lesson['title']) ?></h3>
                            <?php if ($selectedMediaId): ?><h4 class="h5 mt-2 mb-0"><?= e($media['title']) ?></h4><?php endif; ?>
                        </div>
                        <?php if ($canManage): ?>
                            <a href="<?= url('admin/lesson_form.php?' . ($selectedMediaId ? 'media_id=' . $selectedMediaId : 'id=' . $id)) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> แก้ไข</a>
                        <?php endif; ?>
                    </div>

                    <?php if ($media['media_type'] === 'assignment' && trim((string)$media['content']) !== ''): ?>
                        <div class="lesson-content border-bottom pb-3 mb-3"><h4 class="h6">คำสั่งงาน</h4><?= clean_html($media['content']) ?></div>
                    <?php endif; ?>
                    <div class="mb-4">
                        <?php switch ($media['media_type']):
                            case 'assignment': require __DIR__ . '/includes/assignment_view.php'; break;
                            case 'quiz': require __DIR__ . '/includes/quiz_view.php'; break;
                            case 'youtube': $yt = youtube_id((string)$media['media_url']); ?>
                                <?php if ($yt): ?>
                                    <div class="ratio ratio-16x9"><iframe src="https://www.youtube-nocookie.com/embed/<?= e($yt) ?>" allowfullscreen allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"></iframe></div>
                                <?php else: ?><div class="alert alert-warning">ลิงก์ YouTube ไม่ถูกต้อง</div><?php endif; ?>
                                <?php break;
                            case 'video': ?>
                                <video src="<?= e($fileUrl ?: $media['media_url']) ?>" controls class="w-100 bg-dark"></video>
                                <?php break;
                            case 'audio': ?>
                                <audio src="<?= e($fileUrl ?: $media['media_url']) ?>" controls class="w-100"></audio>
                                <?php break;
                            case 'pdf': ?>
                                <div class="ratio" style="--bs-aspect-ratio:120%"><iframe src="<?= e($fileUrl ?: $media['media_url']) ?>" title="<?= e($media['title']) ?>"></iframe></div>
                                <a href="<?= e($fileUrl ?: $media['media_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary mt-2"><i class="bi bi-box-arrow-up-right"></i> เปิดในแท็บใหม่</a>
                                <?php break;
                            case 'slides':
                                $slides = slides_embed($media, $fileUrl); ?>
                                <?php if ($slides['embed']): ?>
                                    <div class="ratio ratio-16x9 border rounded-3 overflow-hidden"><iframe src="<?= e($slides['embed']) ?>" title="<?= e($media['title']) ?>" allowfullscreen></iframe></div>
                                <?php elseif ($slides['local']): ?>
                                    <div class="alert alert-info small"><i class="bi bi-info-circle"></i> ตัวอย่างสไลด์แสดงได้เมื่อเว็บอยู่บนเซิร์ฟเวอร์จริง (เครื่องทดสอบ localhost ใช้ปุ่มดาวน์โหลดแทน)</div>
                                <?php endif; ?>
                                <div class="d-flex flex-wrap gap-2 mt-2">
                                    <?php if ($fileUrl): ?><a href="<?= e($fileUrl) ?>" download class="btn btn-sm btn-outline-danger"><i class="bi bi-download"></i> ดาวน์โหลดไฟล์ (.<?= e(pathinfo($media['file_path'], PATHINFO_EXTENSION)) ?>)</a><?php endif; ?>
                                    <?php if ($slides['open']): ?><a href="<?= e($slides['open']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-up-right"></i> เปิดในแท็บใหม่</a><?php endif; ?>
                                </div>
                                <?php break;
                            case 'mindmap':
                                $mapExt = strtolower(pathinfo((string)$media['file_path'], PATHINFO_EXTENSION));
                                $mapEmbed = $fileUrl ? null : mindmap_embed((string)$media['media_url']); ?>
                                <?php if ($fileUrl && $mapExt === 'pdf'): ?>
                                    <div class="ratio" style="--bs-aspect-ratio:75%"><iframe src="<?= e($fileUrl) ?>" title="<?= e($media['title']) ?>"></iframe></div>
                                <?php elseif ($fileUrl): ?>
                                    <a href="<?= e($fileUrl) ?>" target="_blank" rel="noopener" title="คลิกเพื่อเปิดภาพเต็มขนาดและซูม"><img src="<?= e($fileUrl) ?>" class="img-fluid rounded-3 border w-100" alt="<?= e($media['title']) ?>"></a>
                                    <div class="small text-muted mt-1"><i class="bi bi-zoom-in"></i> คลิกที่ภาพเพื่อเปิดเต็มขนาดและซูม</div>
                                <?php elseif ($mapEmbed): ?>
                                    <div class="ratio ratio-16x9 border rounded-3 overflow-hidden"><iframe src="<?= e($mapEmbed) ?>" title="<?= e($media['title']) ?>" allowfullscreen></iframe></div>
                                <?php endif; ?>
                                <div class="d-flex flex-wrap gap-2 mt-2">
                                    <?php if ($fileUrl): ?><a href="<?= e($fileUrl) ?>" download class="btn btn-sm btn-outline-success"><i class="bi bi-download"></i> ดาวน์โหลด (.<?= e($mapExt) ?>)</a><?php endif; ?>
                                    <?php if (!$fileUrl && $media['media_url']): ?><a href="<?= e($media['media_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-up-right"></i> เปิดในแท็บใหม่</a><?php endif; ?>
                                </div>
                                <?php break;
                            case 'image': ?>
                                <img src="<?= e($fileUrl ?: $media['media_url']) ?>" class="img-fluid rounded-3" alt="<?= e($media['title']) ?>">
                                <?php break;
                            case 'file': ?>
                                <?php if ($fileUrl): ?>
                                    <a href="<?= e($fileUrl) ?>" download class="btn btn-lg btn-info text-white"><i class="bi bi-download"></i> ดาวน์โหลดไฟล์ (.<?= e(pathinfo($media['file_path'], PATHINFO_EXTENSION)) ?>)</a>
                                <?php endif; ?>
                                <?php break;
                            case 'link': ?>
                                <a href="<?= e($media['media_url']) ?>" target="_blank" rel="noopener" class="btn btn-lg btn-dark"><i class="bi bi-box-arrow-up-right"></i> เปิดลิงก์บทเรียน</a>
                                <div class="small text-muted mt-1 text-break"><?= e($media['media_url']) ?></div>
                                <?php break;
                        endswitch; ?>
                    </div>

                    <?php if ($media['media_type'] !== 'assignment' && trim(strip_tags((string)$media['content'], '<img>')) !== ''): ?>
                        <div class="lesson-content"><?= clean_html($media['content']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-white border-top p-3 d-flex flex-wrap justify-content-between gap-2">
                    <a class="btn btn-outline-secondary <?= $prevId ? '' : 'disabled' ?>" href="<?= $prevId ? url('lesson.php?id=' . $prevId) : '#' ?>"><i class="bi bi-chevron-left"></i> ก่อนหน้า</a>
                    <form method="post" class="d-flex gap-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="next_id" value="<?= (int)$nextId ?>">
                        <?php if ($isDone): ?>
                            <button name="action" value="undo" class="btn btn-outline-success"><i class="bi bi-check2-all"></i> เรียนจบแล้ว (ยกเลิก)</button>
                            <?php if ($nextId && isset($locks[$nextId])): ?><span class="btn btn-outline-secondary disabled"><i class="bi bi-lock"></i> บทถัดไป</span>
                            <?php elseif ($nextId): ?><a class="btn btn-vt" href="<?= url('lesson.php?id=' . $nextId) ?>">บทถัดไป <i class="bi bi-chevron-right"></i></a><?php endif; ?>
                        <?php else: ?>
                            <button name="action" value="complete" class="btn btn-success"><i class="bi bi-check2"></i> เรียนจบบทนี้<?= $nextId ? ' → บทถัดไป' : '' ?></button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <?php
            // AI Learning Assistant (แสดงเมื่อผู้ดูแลเปิดโมดูล และครูไม่ได้ปิดในรายวิชานี้)
            require_once ROOT_PATH . '/includes/ai.php';
            if (ai_student_available(['ai_student_enabled' => $lesson['ai_student_enabled']])): ?>
                <div class="card border-0 shadow-sm rounded-4 mt-4" id="aiBox">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <b><i class="bi bi-stars text-primary"></i> ผู้ช่วยการเรียนรู้ AI</b>
                        <span class="small text-muted">เหลือ <span id="aiQuota"><?= ai_quota_left($me) ?></span> ครั้งวันนี้ ·
                            <a href="#" id="aiReset">เริ่มใหม่</a></span>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <button class="btn btn-sm btn-outline-primary" data-mode="summary"><i class="bi bi-list-check"></i> สรุปบทเรียนนี้</button>
                            <button class="btn btn-sm btn-outline-success" data-mode="explain"><i class="bi bi-chat-square-text"></i> อธิบายส่วนที่ไม่เข้าใจ</button>
                            <button class="btn btn-sm btn-outline-warning" data-mode="hint"><i class="bi bi-lightbulb"></i> ขอคำใบ้ทำแบบฝึกหัด</button>
                        </div>
                        <div id="aiChat" class="mb-3" style="max-height:480px;overflow-y:auto"></div>
                        <form id="aiForm" class="d-flex gap-2">
                            <textarea id="aiText" class="form-control" rows="2" maxlength="2000" placeholder="ถามคำถามเกี่ยวกับบทเรียน หรือวางข้อความ/โจทย์ แล้วกดปุ่มด้านบน"></textarea>
                            <button class="btn btn-vt px-3" data-mode="ask"><i class="bi bi-send"></i></button>
                        </form>
                        <div class="form-text"><i class="bi bi-info-circle"></i> AI ตอบจากเนื้อหารายวิชานี้เท่านั้นและอาจผิดพลาดได้ ตรวจสอบกับบทเรียน/ครูผู้สอน · ไม่ให้คำตอบสำเร็จรูปของแบบฝึกหัด · <b>อย่าพิมพ์ข้อมูลส่วนตัว</b></div>
                    </div>
                </div>
                <script>
                    (function () {
                        var chat = document.getElementById('aiChat'), text = document.getElementById('aiText'), busy = false;
                        var labels = {summary: 'สรุปบทเรียนนี้', explain: 'อธิบาย: ', hint: 'ขอคำใบ้: ', ask: ''};
                        function bubble(html, mine, isText) {
                            var d = document.createElement('div');
                            d.className = 'p-2 px-3 rounded-3 mb-2 ' + (mine ? 'bg-primary-subtle ms-5' : 'bg-light me-3 border lesson-content');
                            if (isText) d.textContent = html; else d.innerHTML = html;   // ข้อความผู้ใช้แสดงแบบ text เสมอ
                            chat.appendChild(d); chat.scrollTop = chat.scrollHeight; return d;
                        }
                        function send(mode) {
                            if (busy) return;
                            var t = text.value.trim();
                            if (mode !== 'summary' && !t) { text.focus(); text.placeholder = mode === 'explain' ? 'วางข้อความส่วนที่ไม่เข้าใจตรงนี้ก่อน แล้วกด "อธิบาย"' : 'พิมพ์โจทย์/คำถามตรงนี้ก่อน'; return; }
                            busy = true;
                            bubble(labels[mode] + (mode === 'summary' ? '' : t), true, true);
                            var wait = bubble('<span class="spinner-border spinner-border-sm"></span> กำลังคิด...', false);
                            text.value = '';
                            fetch('<?= url('ai_learn.php') ?>', {
                                method: 'POST', credentials: 'same-origin',
                                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': '<?= csrf_token() ?>'},
                                body: JSON.stringify({lesson_id: <?= (int)$id ?>, mode: mode, text: t})
                            }).then(function (r) { return r.json(); }).then(function (j) {
                                if (j.ok) { wait.innerHTML = j.html; document.getElementById('aiQuota').textContent = j.quota; }
                                else { wait.className = 'alert alert-warning py-2 small'; wait.textContent = j.error; }
                            }).catch(function () {
                                wait.className = 'alert alert-danger py-2 small'; wait.textContent = 'เชื่อมต่อไม่สำเร็จ ลองใหม่อีกครั้ง';
                            }).finally(function () { busy = false; chat.scrollTop = chat.scrollHeight; });
                        }
                        document.querySelectorAll('#aiBox [data-mode]').forEach(function (b) {
                            b.addEventListener('click', function (ev) { ev.preventDefault(); send(b.getAttribute('data-mode')); });
                        });
                        text.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); send('ask'); } });
                        document.getElementById('aiReset').addEventListener('click', function (ev) {
                            ev.preventDefault(); chat.innerHTML = '';
                            fetch('<?= url('ai_learn.php') ?>', {method: 'POST', credentials: 'same-origin',
                                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': '<?= csrf_token() ?>'},
                                body: JSON.stringify({lesson_id: <?= (int)$id ?>, mode: 'reset'})});
                        });
                    })();
                </script>
            <?php endif; ?>
        </main>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
