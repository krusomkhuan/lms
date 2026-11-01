<?php if (!isset($media, $me, $canManage)) { http_response_code(404); exit; } ?>
<section id="assignment">
<?php if (!assignments_ready()): ?>
    <div class="alert alert-warning">ระบบส่งงานยังไม่พร้อมใช้งาน กรุณาติดต่อครูผู้สอน</div>
<?php else:
    $submissions = db_all('SELECT s.*, u.fullname AS grader FROM assignment_submissions s LEFT JOIN users u ON u.id = s.graded_by WHERE s.lesson_id = ? AND s.source_media_id = ? AND s.user_id = ? ORDER BY s.id DESC LIMIT 50', [$id, $selectedMediaId, (int)$me['id']]);
?>
    <?php $scheduleState = assignment_schedule_state($media); ?>
    <div class="small mb-3">
        <?php if (!empty($media['submission_opens_at'])): ?><div>เริ่มรับงาน: <?= e(thai_date($media['submission_opens_at'], true)) ?></div><?php endif; ?>
        <?php if (!empty($media['submission_due_at'])): ?><div>กำหนดส่ง: <?= e(thai_date($media['submission_due_at'], true)) ?> · <?= !empty($media['accept_late']) ? 'รับงานล่าช้า' : 'ไม่รับงานล่าช้า' ?></div><?php endif; ?>
        <?php if ($scheduleState !== 'open'): ?><div class="alert alert-warning mt-2"><?= ['upcoming' => 'ยังไม่ถึงเวลาเริ่มรับงาน', 'closed' => 'ปิดรับงานแล้ว', 'late' => 'พ้นกำหนดแล้ว ยังส่งได้โดยติดสถานะส่งล่าช้า'][$scheduleState] ?></div><?php endif; ?>
    </div>
    <p class="fw-semibold">คะแนนเต็ม <?= e($media['assignment_points']) ?> คะแนน</p>
    <?php if ($canManage): ?>
        <a class="btn btn-vt" href="<?= url('admin/assignments.php?lesson_id=' . $id) ?>">ตรวจงานและให้คะแนน</a>
        <p class="small text-muted mt-2">นักเรียนส่งข้อความหรือไฟล์ได้ และส่งฉบับแก้ไขเป็นรายการใหม่ โดยเก็บประวัติและคะแนนแต่ละครั้ง</p>
    <?php elseif ($me['role'] === 'student' && in_array($scheduleState, ['open', 'late'], true)):
        $token = bin2hex(random_bytes(32));
        $_SESSION['assignment_tokens'] = array_slice($_SESSION['assignment_tokens'] ?? [], -19, null, true);
        $_SESSION['assignment_tokens'][$token] = ['key' => $id . ':' . $selectedMediaId, 'hash' => assignment_fingerprint($media)];
    ?>
        <?php if ($assignmentError): ?><div class="alert alert-danger"><?= e($assignmentError) ?></div><?php endif; ?>
        <p>ส่งข้อความหรือไฟล์อย่างน้อยหนึ่งอย่าง ส่งฉบับแก้ไขได้โดยเก็บงานและคะแนนเดิมไว้ทุกครั้ง</p>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="submit_assignment">
            <input type="hidden" name="submission_token" value="<?= e($token) ?>">
            <label class="form-label w-100">ข้อความส่งงาน / ลิงก์ผลงาน
                <textarea class="form-control mt-1" name="submission_text" rows="5" maxlength="20000"><?= e($assignmentError ? post('submission_text') : '') ?></textarea>
            </label>
            <label class="form-label w-100">ไฟล์งาน (ไม่บังคับ)
                <input type="file" name="submission_file" class="form-control mt-1" accept=".<?= e(implode(',.', allowed_ext('file'))) ?>">
            </label>
            <p class="small text-muted">ไฟล์ละไม่เกิน <?= MAX_UPLOAD_MB ?> MB · <?= e(implode(', ', allowed_ext('file'))) ?><?= $assignmentError ? ' · หากแนบไฟล์ กรุณาเลือกใหม่ก่อนส่ง' : '' ?></p>
            <button class="btn btn-vt">ส่งงานให้ครูตรวจ</button>
        </form>
    <?php endif; ?>
    <?php if ($submissions): ?><h5 class="mt-4">ประวัติการส่งงานและคะแนนของฉัน (ล่าสุด 50 ครั้ง)</h5>
        <?php foreach ($submissions as $s): ?>
            <article class="border rounded p-3 mb-3">
                <p class="mb-2"><strong><?= e(assignment_status($s['status'])) ?></strong> <?= !empty($s['is_late']) ? '<span class="badge bg-warning text-dark">ส่งล่าช้า</span>' : '' ?> · <?= e(thai_date($s['submitted_at'], true)) ?></p>
                <?php if ($s['submission_text']): ?><p class="text-break" style="white-space:pre-wrap"><?= e($s['submission_text']) ?></p><?php endif; ?>
                <?php if ($s['file_path']): ?><a class="btn btn-sm btn-outline-primary mb-2" href="<?= url('assignment_file.php?id=' . $s['id']) ?>">ดาวน์โหลด <?= e($s['original_name']) ?></a><?php endif; ?>
                <p class="mb-1">คะแนน: <?= $s['score'] !== null ? e($s['score']) . ' / ' . e($s['max_score']) : 'ยังไม่ได้ให้คะแนน' ?></p>
                <?php if ($s['feedback']): ?><div class="text-break" style="white-space:pre-wrap">ข้อเสนอแนะ: <?= e($s['feedback']) ?></div><?php endif; ?>
                <?php if ($s['graded_at']): ?><p class="small text-muted mb-0">ตรวจโดย <?= e($s['grader'] ?? 'ครูผู้สอน') ?> · <?= e(thai_date($s['graded_at'], true)) ?></p><?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>
</section>
