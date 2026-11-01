<?php if (!isset($media, $me, $canManage)) exit; ?>
<section id="quiz">
<?php if (!quiz_ready()): ?>
    <div class="alert alert-warning">ระบบแบบทดสอบยังไม่พร้อมใช้งาน กรุณาติดต่อครูผู้สอน</div>
<?php else:
    $quizConfig = quiz_parse((string)($media['quiz_data'] ?? ''));
    $quizKey = $id . ':' . $selectedMediaId;
    $quizHash = hash('sha256', (string)$media['quiz_data']);
    $history = db_all('SELECT score,total,submitted_at FROM quiz_attempts WHERE lesson_id = ? AND source_media_id = ? AND user_id = ? ORDER BY id DESC LIMIT 50', [$id, $selectedMediaId, (int)$me['id']]);
    $attemptCount = (int)db_value('SELECT COUNT(*) FROM quiz_attempts WHERE lesson_id = ? AND source_media_id = ? AND user_id = ?', [$id, $selectedMediaId, (int)$me['id']]);
    $maxAttempts = (int)$quizConfig['max_attempts'];
    $attemptsLeft = $maxAttempts ? max(0, $maxAttempts - $attemptCount) : null;
    $quizState = quiz_schedule_state($quizConfig);
    // ส่งไม่ผ่าน (เช่น ตอบไม่ครบ) → แสดงชุดข้อเดิมที่ได้รับ ไม่สุ่มใหม่
    $token = is_string($_POST['submission_token'] ?? null) ? $_POST['submission_token'] : '';
    $pending = $token !== '' ? ($_SESSION['quiz_tokens'][$token] ?? null) : null;
    if ($pending && $pending['key'] === $quizKey && $pending['hash'] === $quizHash && is_array($pending['questions'] ?? null)) {
        $questions = $pending['questions'];
    } else {
        $questions = quiz_draw((string)$media['quiz_data'], $courseId);
        $token = bin2hex(random_bytes(32));
        $_SESSION['quiz_tokens'] = array_slice($_SESSION['quiz_tokens'] ?? [], -19, null, true);
        $_SESSION['quiz_tokens'][$token] = ['key' => $quizKey, 'hash' => $quizHash, 'questions' => $questions];
    }
?>
    <?php if ($canManage): ?><a class="btn btn-outline-primary mb-3" href="<?= url('admin/quiz_results.php?lesson_id=' . $id) ?>">ดูคะแนนนักเรียน</a><p>ตัวอย่างแบบทดสอบ (บัญชีครูไม่บันทึกคะแนน)<?= $quizConfig['source'] === 'bank' ? ' · สุ่มจากคลังข้อสอบ นักเรียนแต่ละคนได้ข้อไม่เหมือนกัน' : '' ?></p><?php endif; ?>
    <?php if ($quizError): ?><div class="alert alert-danger"><?= e($quizError) ?></div><?php endif; ?>
    <?php if ($quizConfig['kind'] !== ''): ?><span class="badge bg-info text-dark mb-2"><?= e(QUIZ_KINDS[$quizConfig['kind']] ?? '') ?></span><?php endif; ?>
    <?php if (quiz_schedule_label($quizConfig)): ?><div class="small text-muted mb-2"><i class="bi bi-calendar-event"></i> <?= e(quiz_schedule_label($quizConfig)) ?></div><?php endif; ?>
    <?php if ($me['role'] === 'student' && $attemptsLeft === 0 && $quizConfig['kind'] !== '' && $history):
        // ก่อน–หลังเรียน: สอบครั้งเดียว แสดงคะแนน (ไม่แสดงเฉลย) และหลังเรียนเทียบกับก่อนเรียน
        $result = $history[0];
        $percent = $result['total'] ? round($result['score'] * 100 / $result['total'], 1) : 0.0;
        $preResult = $quizConfig['kind'] === 'post' && $quizConfig['pair'] ? (quiz_scores_by_user($quizConfig['pair'], 'first', (int)$me['id'])[(int)$me['id']] ?? null) : null; ?>
        <div class="card border-0 shadow-sm rounded-4 mb-3"><div class="card-body text-center py-4">
            <div class="text-muted mb-1"><i class="bi bi-check-circle-fill text-success"></i> ส่งคำตอบแล้ว · คะแนนของคุณ</div>
            <div class="display-5 fw-semibold text-vt"><?= (int)$result['score'] ?> <span class="fs-4 text-muted">/ <?= (int)$result['total'] ?></span></div>
            <div class="fs-5 text-<?= progress_color($percent) ?>"><?= $percent ?>%</div>
            <?php if ($preResult): $diff = round($percent - $preResult['percent'], 1); ?>
                <div class="mt-3">ก่อนเรียน <?= (int)$preResult['score'] ?>/<?= (int)$preResult['total'] ?> (<?= $preResult['percent'] ?>%) → หลังเรียน <?= $percent ?>%
                    <span class="badge bg-<?= $diff > 0 ? 'success' : ($diff < 0 ? 'danger' : 'secondary') ?> ms-1"><?= ($diff > 0 ? '+' : '') . $diff ?>%</span></div>
            <?php endif; ?>
            <div class="small text-muted mt-3"><?= e(QUIZ_KINDS[$quizConfig['kind']]) ?>สอบได้ 1 ครั้ง · ส่งเมื่อ <?= e(thai_date($result['submitted_at'], true)) ?></div>
        </div></div>
    <?php elseif ($me['role'] === 'student' && $quizState !== 'open'): ?>
        <div class="alert alert-secondary"><?= $quizState === 'upcoming' ? 'แบบทดสอบยังไม่เปิด' : 'แบบทดสอบปิดแล้ว' ?></div>
    <?php elseif ($me['role'] === 'student' && $attemptsLeft === 0): ?>
        <div class="alert alert-secondary">คุณทำแบบทดสอบนี้ครบ <?= $maxAttempts ?> ครั้งแล้ว</div>
    <?php elseif ($questions): ?>
    <p><?= count($questions) ?> ข้อ · ข้อละ 1 คะแนน · <?= $maxAttempts ? 'ทำได้ ' . $maxAttempts . ' ครั้ง' . ($me['role'] === 'student' ? ' (เหลือ ' . $attemptsLeft . ' ครั้ง)' : '') : 'ทำซ้ำได้และเก็บคะแนนทุกครั้ง' ?></p>
    <?php if ($quizConfig['kind'] !== '' && $me['role'] === 'student'): ?><div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle"></i> <?= e(QUIZ_KINDS[$quizConfig['kind']]) ?>สอบได้ <b>1 ครั้ง</b> ตรวจคำตอบให้ครบก่อนกดส่ง</div><?php endif; ?>
    <?php $lockActive = $quizConfig['lockdown'] && $me['role'] === 'student';
    $quizLeaves = $lockActive ? (int)($_SESSION['quiz_leaves'][$quizKey] ?? 0) : 0; ?>
    <?php if ($quizConfig['lockdown']): ?><div class="alert alert-danger py-2"><i class="bi bi-shield-lock"></i> <b>ระบบป้องกันการออกจากหน้าสอบ:</b> ห้ามเปิดแท็บใหม่ สลับหน้าจอ หรือออกจากหน้านี้ระหว่างสอบ ระบบเตือน <?= QUIZ_LEAVE_LIMIT - 1 ?> ครั้ง ครั้งที่ <?= QUIZ_LEAVE_LIMIT ?> จะส่งคำตอบอัตโนมัติ<?= $quizLeaves ? ' · <b>ออกจากหน้าสอบไปแล้ว ' . $quizLeaves . ' ครั้ง</b>' : '' ?></div><?php endif; ?>
    <form method="post"<?= $lockActive ? ' data-lockdown data-limit="' . QUIZ_LEAVE_LIMIT . '" data-leaves="' . $quizLeaves . '"' : ($quizConfig['kind'] !== '' ? ' onsubmit="return confirm(&quot;ยืนยันส่งคำตอบ? ส่งแล้วสอบซ้ำไม่ได้&quot;)"' : '') ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="submit_quiz">
        <input type="hidden" name="submission_token" value="<?= e($token) ?>">
        <?php foreach ($questions as $i => $q): ?>
            <fieldset class="border rounded p-3 mb-3">
                <legend class="fs-6"><?= $i + 1 ?>. <?= nl2br(e($q['text'])) ?></legend>
                <?php foreach ($q['options'] as $j => $option): ?>
                    <label class="d-block mb-2"><input type="radio" name="answers[<?= $i ?>]" value="<?= $j ?>" required <?= isset($_POST['answers'][$i]) && is_scalar($_POST['answers'][$i]) && (string)$_POST['answers'][$i] === (string)$j ? 'checked' : '' ?>> <?= QUIZ_LETTERS[$j] ?? $j + 1 ?>. <?= e($option) ?></label>
                <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>
        <?php if ($me['role'] === 'student'): ?><button class="btn btn-vt" type="submit">ส่งคำตอบและบันทึกคะแนน</button><?php endif; ?>
    </form>
    <?php if ($lockActive): ?>
        <div id="quizLockOverlay" class="quiz-lock-overlay" role="alertdialog" aria-modal="true" aria-labelledby="quizLockTitle" hidden>
            <div class="quiz-lock-box">
                <i class="bi bi-exclamation-octagon-fill text-danger display-4"></i>
                <h2 class="h4 mt-2 lock-title" id="quizLockTitle"></h2>
                <p class="lock-text mb-3"></p>
                <button type="button" class="btn btn-danger lock-close">กลับไปทำข้อสอบต่อ</button>
            </div>
        </div>
        <script src="<?= url('assets/js/quiz-lockdown.js') ?>?v=<?= (int)@filemtime(ROOT_PATH . '/assets/js/quiz-lockdown.js') ?>"></script>
    <?php endif; ?>
    <?php else: ?><p>ยังไม่มีข้อสอบ</p><?php endif; ?>
    <?php if ($history && $quizConfig['kind'] === ''): ?>
        <h5 class="mt-4">ประวัติคะแนนของฉัน (ล่าสุด 50 ครั้ง)</h5>
        <ul class="list-group"><?php foreach ($history as $attempt): ?><li class="list-group-item"><?= e(thai_date($attempt['submitted_at'], true)) ?> — <?= (int)$attempt['score'] ?> / <?= (int)$attempt['total'] ?> คะแนน</li><?php endforeach; ?></ul>
    <?php endif; ?>
<?php endif; ?>
</section>
