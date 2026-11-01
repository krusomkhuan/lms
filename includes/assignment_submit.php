<?php
if (!isset($media, $me, $id, $selectedMediaId)) { http_response_code(404); exit; }
$assignmentError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'submit_assignment') {
    verify_csrf();
    $uploaded = null;
    try {
        if ($me['role'] !== 'student' || $media['media_type'] !== 'assignment' || !assignments_ready()) throw new RuntimeException('ไม่สามารถส่งงานนี้ได้');
        $scheduleState = assignment_schedule_state($media);
        if (in_array($scheduleState, ['upcoming', 'closed'], true)) throw new RuntimeException($scheduleState === 'upcoming' ? 'ยังไม่ถึงเวลาเริ่มรับงาน' : 'พ้นกำหนดส่งงานแล้ว');
        $token = post('submission_token');
        $pending = is_string($token) ? ($_SESSION['assignment_tokens'][$token] ?? null) : null;
        if (!$pending || $pending['key'] !== $id . ':' . $selectedMediaId || !hash_equals($pending['hash'], assignment_fingerprint($media))) throw new RuntimeException('งานมีการเปลี่ยนแปลงหรือส่งแล้ว กรุณาเปิดงานใหม่ก่อนส่ง');
        $text = post('submission_text');
        if (!is_string($text) || mb_strlen($text) > 20000) throw new RuntimeException('ข้อความส่งงานยาวได้ไม่เกิน 20,000 ตัวอักษร');
        $uploaded = assignment_upload();
        if ($text === '' && !$uploaded) throw new RuntimeException('กรุณากรอกข้อความหรือเลือกไฟล์งานอย่างน้อยหนึ่งอย่าง');
        $conn->begin_transaction();
        try {
        $submissionId = db_exec('INSERT INTO assignment_submissions (lesson_id,media_id,source_media_id,user_id,assignment_title,instructions,max_score,submission_text,file_path,original_name,submission_token) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$id, $selectedMediaId ?: null, $selectedMediaId, (int)$me['id'], $media['title'], $media['content'], assignment_points($media['assignment_points']), $text !== '' ? $text : null, $uploaded['path'] ?? null, $uploaded['name'] ?? null, $token]);
        if (schedule_ready()) db_exec('UPDATE assignment_submissions SET is_late=?,due_at_snapshot=? WHERE id=?', [$scheduleState === 'late' ? 1 : 0, $media['submission_due_at'] ?? null, $submissionId]);
        $conn->commit();
        } catch (Throwable $ex) { $conn->rollback(); throw $ex; }
        $uploaded = null;
        unset($_SESSION['assignment_tokens'][$token]);
        flash('success', 'ส่งงานเรียบร้อยแล้ว รอครูผู้สอนตรวจและให้คะแนน');
        redirect('lesson.php?id=' . $id . ($selectedMediaId ? '&media_id=' . $selectedMediaId : '') . '#assignment');
    } catch (mysqli_sql_exception $ex) {
        if ($uploaded) delete_upload($uploaded['path']);
        $assignmentError = 'บันทึกงานไม่สำเร็จ หรือส่งรายการนี้แล้ว กรุณาตรวจสอบประวัติก่อนส่งอีกครั้ง';
    } catch (RuntimeException $ex) {
        if ($uploaded) delete_upload($uploaded['path']);
        $assignmentError = $ex->getMessage();
    }
}
