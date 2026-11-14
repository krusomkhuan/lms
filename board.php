<?php
// หน้ากระดานกิจกรรม: นักเรียนส่งคำตอบ/ผลงาน ครูดูแลกระดาน (ข้อมูลโหลดผ่าน board_api.php)
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();
$board = board_get(get_int('id'));
if (!$board) {
    http_response_code(404);
    exit('ไม่พบกระดาน');
}
$role = board_role($board, $me);
if (!$role) {
    flash('warning', 'คุณไม่มีสิทธิ์เข้ากระดานนี้ (ต้องเป็นผู้เรียนในรายวิชา ' . $board['course_code'] . ')');
    redirect('course.php?id=' . (int)$board['course_id']);
}
$lesson = $board['lesson_id'] ? db_one('SELECT id, title FROM lessons WHERE id = ?', [(int)$board['lesson_id']]) : null;
$manage = board_can($role, 'manage');
$config = [
    'board' => (int)$board['id'], 'api' => url('board_api.php'), 'csrf' => csrf_token(), 'layout' => $board['layout'],
    'maxBytes' => board_max_upload_bytes(), 'maxFiles' => BOARD_MAX_FILES, 'kinds' => BOARD_FILE_KINDS,
    'layouts' => array_map(fn($l) => $l[0], BOARD_LAYOUTS), 'me' => (int)$me['id'], 'levelUrl' => url('my-activities.php?course_id=' . (int)$board['course_id']),
];
$pageTitle = $board['title'];
$noContainerFlash = true;
require __DIR__ . '/includes/header.php';
?>
<link href="<?= url('assets/css/board.css') ?>?v=<?= (int)@filemtime(ROOT_PATH . '/assets/css/board.css') ?>" rel="stylesheet">
<div class="board-page">
    <div class="container-fluid px-lg-4 pt-3"><?= render_flash() ?></div>
    <header class="board-head container-fluid px-lg-4 pb-3">
        <nav class="small mb-1">
            <a href="<?= url('course.php?id=' . (int)$board['course_id']) ?>"><i class="bi bi-arrow-left"></i> <?= e($board['course_code'] . ' ' . $board['course_title']) ?></a>
            <?php if ($lesson): ?> · <a href="<?= url('lesson.php?id=' . (int)$lesson['id']) ?>"><?= e($lesson['title']) ?></a><?php endif; ?>
        </nav>
        <div class="d-flex flex-wrap align-items-start gap-3">
            <div class="flex-grow-1" style="min-width:16rem">
                <h1 class="h3 mb-1"><i class="bi bi-easel2 text-primary"></i> <?= e($board['title']) ?></h1>
                <?php if (trim(strip_tags((string)$board['description'], '<img>')) !== ''): ?><div class="board-question lesson-content"><?= clean_html($board['description']) ?></div><?php endif; ?>
                <div class="small text-muted mt-1" id="board-meta"></div>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center board-tools">
                <div class="btn-group btn-group-sm" role="group" aria-label="รูปแบบการแสดงผล" id="layout-switch">
                    <?php foreach (BOARD_LAYOUTS as $key => [$label, $icon, $hint]): ?>
                        <button type="button" class="btn btn-outline-secondary" data-layout="<?= $key ?>" title="<?= e($label . ' – ' . $hint) ?>" aria-label="<?= e($label) ?>"><i class="bi <?= $icon ?>"></i></button>
                    <?php endforeach; ?>
                </div>
                <select class="form-select form-select-sm w-auto" id="team-filter" hidden aria-label="กรองตามทีม"></select>
                <?php if (board_can($role, 'moderate')): ?>
                    <button type="button" class="btn btn-sm btn-outline-warning" id="pending-filter" hidden><i class="bi bi-hourglass-split"></i> รออนุมัติ <span class="badge bg-warning text-dark" id="pending-count">0</span></button>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="reveal-btn" hidden></button>
                <?php endif; ?>
                <?php if ($manage): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="select-mode" title="ติ๊กเลือกนักเรียนหลายคนแล้วมอบดาวพร้อมกัน"><i class="bi bi-check2-square"></i> เลือกให้ดาวหลายคน</button>
                    <button type="button" class="btn btn-sm btn-award" id="award-all" title="มอบดาวให้ทุกคนที่ตอบในกิจกรรมนี้"><i class="bi bi-stars"></i> ให้ดาวทุกคน</button>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-dark dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-gear"></i> จัดการ</button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?= url('admin/board_report.php?id=' . (int)$board['id']) ?>"><i class="bi bi-clipboard-check"></i> ตรวจคำตอบ / ให้คะแนน / รายงาน</a></li>
                            <li><a class="dropdown-item" href="<?= url('admin/board_form.php?id=' . (int)$board['id']) ?>"><i class="bi bi-sliders"></i> ตั้งค่ากระดาน ทีม และสิทธิ์</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><button class="dropdown-item" data-status="open"><i class="bi bi-unlock"></i> เปิดรับคำตอบ</button></li>
                            <li><button class="dropdown-item" data-status="closed"><i class="bi bi-lock"></i> ปิดรับคำตอบ</button></li>
                            <li><button class="dropdown-item" data-status="archived"><i class="bi bi-archive"></i> เก็บถาวร</button></li>
                        </ul>
                    </div>
                <?php endif; ?>
                <span class="small text-muted" id="live-dot" title="อัปเดตอัตโนมัติ"><i class="bi bi-broadcast"></i> สด</span>
            </div>
        </div>
        <div id="board-banner"></div>
    </header>
    <main id="board-app" class="board-app container-fluid px-lg-4 pb-5" aria-live="polite">
        <div class="text-center text-muted py-5"><div class="spinner-border"></div><div class="mt-2">กำลังโหลดกระดาน…</div></div>
    </main>
    <?php if ($manage): ?>
        <div class="select-bar shadow-lg" id="select-bar" hidden role="region" aria-label="มอบดาวให้นักเรียนที่เลือก">
            <span class="fw-semibold" id="select-count" aria-live="polite"></span>
            <button type="button" class="btn btn-sm btn-light" id="select-all"></button>
            <label class="d-flex align-items-center gap-1 small mb-0">คนละ <select class="form-select form-select-sm" id="select-amount" style="width:auto"><option value="1">1 ดาว</option><option value="2">2 ดาว</option><option value="3">3 ดาว</option><option value="4">4 ดาว</option><option value="5">5 ดาว</option></select></label>
            <button type="button" class="btn btn-sm btn-award" id="select-award" disabled><i class="bi bi-star-fill"></i> มอบดาว</button>
            <button type="button" class="btn btn-sm btn-outline-light" id="select-cancel">ยกเลิก</button>
        </div>
    <?php endif; ?>
    <button type="button" class="board-fab btn btn-vt shadow-lg" id="fab" hidden><i class="bi bi-plus-lg"></i> <span>ส่งคำตอบ</span></button>
</div>

<!-- แผงส่งคำตอบ / ผลงาน -->
<div class="modal fade" id="composer" tabindex="-1" aria-labelledby="composer-title">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <form class="modal-content" id="composer-form" novalidate>
            <div class="modal-header">
                <h2 class="modal-title h5" id="composer-title">ส่งคำตอบ</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger d-none" id="composer-error" role="alert"></div>
                <div class="small text-success mb-2 d-none" id="draft-note"><i class="bi bi-cloud-check"></i> กู้คืนคำตอบที่พิมพ์ค้างไว้แล้ว</div>
                <label class="form-label w-100" id="title-label"><span id="title-text">หัวข้อ (ไม่บังคับ)</span>
                    <input class="form-control mt-1" name="title" maxlength="200" autocomplete="off"></label>
                <label class="form-label w-100" id="body-label">คำตอบ / คำอธิบาย
                    <textarea class="form-control mt-1" name="body" rows="5" maxlength="10000"></textarea></label>
                <div class="row g-2">
                    <div class="col-md-8"><label class="form-label w-100" id="link-label"><i class="bi bi-link-45deg"></i> ลิงก์ผลงาน / YouTube (ไม่บังคับ)
                        <input class="form-control mt-1" name="link_url" inputmode="url" maxlength="1000" placeholder="https://"></label></div>
                    <div class="col-md-4" id="section-wrap" hidden><label class="form-label w-100">หัวข้อ/คอลัมน์
                        <select class="form-select mt-1" name="section_id"></select></label></div>
                </div>
                <div id="location-wrap" class="small mb-2" hidden>
                    <i class="bi bi-geo-alt"></i> ตำแหน่ง: <span id="location-text">ยังไม่ได้เลือก (คลิกบนแผนที่ก่อนกดส่ง หรือใช้ตำแหน่งปัจจุบัน)</span>
                    <button type="button" class="btn btn-sm btn-link" id="use-location">ใช้ตำแหน่งปัจจุบัน</button>
                </div>
                <div id="files-section">
                    <div class="drop-zone" id="drop-zone" tabindex="0" role="button" aria-label="เลือกไฟล์หรือลากไฟล์มาวาง">
                        <i class="bi bi-cloud-arrow-up fs-2"></i>
                        <div class="when-mouse"><b>ลากไฟล์มาวาง</b> หรือ <u>เลือกไฟล์</u> · วางรูปจากคลิปบอร์ด (Ctrl+V) ได้</div>
                        <div class="when-touch"><b>แตะเพื่อเลือกรูปหรือไฟล์</b> จากเครื่อง / Google Drive</div>
                        <div class="small text-muted" id="file-rules"></div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-2 media-btns">
                        <label class="btn btn-sm btn-outline-primary mb-0"><i class="bi bi-camera"></i> ถ่ายรูป<input type="file" accept="image/*" capture="environment" hidden id="camera-input"></label>
                        <label class="btn btn-sm btn-outline-primary mb-0"><i class="bi bi-camera-video"></i> ถ่ายวิดีโอ<input type="file" accept="video/*" capture="environment" hidden id="video-input"></label>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="record-btn" hidden><i class="bi bi-mic"></i> อัดเสียง</button>
                        <input type="file" multiple hidden id="file-input">
                    </div>
                    <ul class="list-unstyled mt-2 mb-0" id="file-list"></ul>
                </div>
            </div>
            <div class="modal-footer">
                <span class="small text-muted me-auto" id="composer-hint"></span>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">ปิด</button>
                <button class="btn btn-vt" id="composer-submit"><i class="bi bi-send"></i> ส่ง</button>
            </div>
        </form>
    </div>
</div>

<!-- ดูรูป/PDF เต็มจอ -->
<div class="modal fade" id="viewer" tabindex="-1" aria-label="ดูไฟล์">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-fullscreen-md-down">
        <div class="modal-content bg-dark">
            <div class="modal-header border-0"><span class="text-white small text-truncate" id="viewer-name"></span><a class="btn btn-sm btn-outline-light ms-auto me-2" id="viewer-download" download>ดาวน์โหลด</a><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
            <div class="modal-body p-0 text-center" id="viewer-body"></div>
        </div>
    </div>
</div>
<script>window.BOARD = <?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script defer src="<?= url('assets/js/board.js') ?>?v=<?= (int)@filemtime(ROOT_PATH . '/assets/js/board.js') ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
