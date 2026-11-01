<?php
// Included only after lesson and selected media access checks in lesson.php.
$quizError = '';
// นักเรียนออกจากหน้าสอบ (สลับแท็บ/แอป/หน้าต่าง): นับในเซสชันต่อแบบทดสอบ รีโหลดหน้าแล้วไม่ล้าง
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'quiz_leave') {
    verify_csrf();
    header('Content-Type: application/json; charset=utf-8');
    if ($me['role'] !== 'student' || $media['media_type'] !== 'quiz' || !quiz_parse((string)$media['quiz_data'])['lockdown']) {
        http_response_code(403);
        exit(json_encode(['ok' => false]));
    }
    $leaveKey = $id . ':' . $selectedMediaId;
    $_SESSION['quiz_leaves'][$leaveKey] = min(QUIZ_LEAVE_LIMIT, ($_SESSION['quiz_leaves'][$leaveKey] ?? 0) + 1);
    exit(json_encode(['ok' => true, 'leaves' => $_SESSION['quiz_leaves'][$leaveKey], 'limit' => QUIZ_LEAVE_LIMIT]));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'submit_quiz') {
    verify_csrf();
    try {
        if ($me['role'] !== 'student' || $media['media_type'] !== 'quiz' || !quiz_ready()) throw new RuntimeException('ไม่สามารถส่งแบบทดสอบนี้ได้');
        $key = $id . ':' . $selectedMediaId;
        $token = post('submission_token');
        $pending = is_string($token) ? ($_SESSION['quiz_tokens'][$token] ?? null) : null;
        if (!$pending || $pending['key'] !== $key || !hash_equals($pending['hash'], hash('sha256', (string)$media['quiz_data'])) || !is_array($pending['questions'] ?? null)) {
            throw new RuntimeException('แบบทดสอบมีการเปลี่ยนแปลงหรือส่งคำตอบแล้ว กรุณาเปิดแบบทดสอบใหม่');
        }
        $quizConfig = quiz_parse($media['quiz_data']);
        $quizState = quiz_schedule_state($quizConfig);
        if ($quizState !== 'open') throw new RuntimeException($quizState === 'upcoming' ? 'แบบทดสอบยังไม่เปิด · ' . quiz_schedule_label($quizConfig) : 'แบบทดสอบปิดแล้ว · ' . quiz_schedule_label($quizConfig));
        $maxAttempts = (int)$quizConfig['max_attempts'];
        if ($maxAttempts && (int)db_value('SELECT COUNT(*) FROM quiz_attempts WHERE lesson_id = ? AND source_media_id = ? AND user_id = ?', [$id, $selectedMediaId, (int)$me['id']]) >= $maxAttempts) {
            throw new RuntimeException('ทำแบบทดสอบนี้ครบ ' . $maxAttempts . ' ครั้งแล้ว');
        }
        // ตรวจกับชุดข้อที่นักเรียนได้รับจริง (สุ่ม/สลับไว้ตอนเปิดแบบทดสอบ และเก็บไว้ฝั่งเซิร์ฟเวอร์)
        $questions = $pending['questions'];
        $answers = post('answers', []);
        $leaves = $quizConfig['lockdown'] ? (int)($_SESSION['quiz_leaves'][$key] ?? 0) : 0;
        $autoSubmit = $leaves >= QUIZ_LEAVE_LIMIT;
        $score = quiz_grade($questions, $answers, $autoSubmit);
        $snapshot = json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        db_exec('INSERT INTO quiz_attempts (lesson_id,media_id,source_media_id,user_id,quiz_title,score,total,answers,quiz_snapshot,submission_token) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$id, $selectedMediaId ?: null, $selectedMediaId, (int)$me['id'], $media['title'], $score, count($questions),
                json_encode($quizConfig['lockdown'] ? ['answers' => $answers, 'leaves' => $leaves, 'auto' => $autoSubmit] : $answers), $snapshot, $token]);
        unset($_SESSION['quiz_tokens'][$token], $_SESSION['quiz_leaves'][$key]);
        flash($autoSubmit ? 'warning' : 'success', ($autoSubmit ? 'ออกจากหน้าสอบครบ ' . QUIZ_LEAVE_LIMIT . ' ครั้ง ระบบส่งคำตอบอัตโนมัติ · ' : '') . 'บันทึกคะแนนแล้ว: ' . $score . ' / ' . count($questions) . ' คะแนน');
        redirect('lesson.php?id=' . $id . ($selectedMediaId ? '&media_id=' . $selectedMediaId : '') . '#quiz');
    } catch (RuntimeException $ex) {
        $quizError = $ex->getMessage();
    }
}
