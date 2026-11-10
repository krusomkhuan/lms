<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_role('admin', 'teacher');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
verify_csrf();
$item = lesson_media_ready() ? db_one('SELECT m.*, l.course_id FROM lesson_media m JOIN lessons l ON l.id = m.lesson_id WHERE m.id = ?', [(int)post('media_id')]) : null;
if (!$item || !can_manage_course((int)$item['course_id'])) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์จัดการสื่อนี้');
}
$mediaId = (int)$item['id'];
$lessonId = (int)$item['lesson_id'];
$action = post('action');
if ($action === 'delete') {
    db_exec('DELETE FROM lesson_media WHERE id = ?', [$mediaId]);
    delete_upload($item['file_path']);
    flash('success', 'ลบสื่อแล้ว');
} elseif ($action === 'toggle') {
    db_exec('UPDATE lesson_media SET is_published = 1 - is_published WHERE id = ?', [$mediaId]);
} elseif (in_array($action, ['up', 'down'], true)) {
    $conn->begin_transaction();
    try {
        $ids = array_map('intval', array_column(db_all('SELECT id FROM lesson_media WHERE lesson_id = ? ORDER BY sort_order, id FOR UPDATE', [$lessonId]), 'id'));
        $position = array_search($mediaId, $ids, true);
        $other = $action === 'up' ? $position - 1 : $position + 1;
        if ($position !== false && isset($ids[$other])) [$ids[$position], $ids[$other]] = [$ids[$other], $ids[$position]];
        foreach ($ids as $order => $id) db_exec('UPDATE lesson_media SET sort_order = ? WHERE id = ?', [$order + 1, $id]);
        $conn->commit();
    } catch (Throwable $ex) {
        $conn->rollback();
        throw $ex;
    }
} elseif ($action === 'extract') {
    // ย้ายสื่อออกเป็นบทเรียนแยก วางไว้ก่อนบทเรียนเดิม พร้อมย้ายคะแนน/งานที่ส่ง คู่ก่อน–หลังเรียน และรายการในสมุดคะแนน
    require_once ROOT_PATH . '/includes/gradebook.php';
    $courseId = (int)$item['course_id'];
    $conn->begin_transaction();
    try {
        $order = array_map('intval', array_column(db_all('SELECT id FROM lessons WHERE course_id = ? ORDER BY sort_order, id FOR UPDATE', [$courseId]), 'id'));
        $newId = db_exec('INSERT INTO lessons (course_id,title,media_type,content,media_url,file_path,is_published,sort_order) VALUES (?,?,?,?,?,?,?,0)',
            [$courseId, $item['title'], $item['media_type'], $item['content'], $item['media_url'], $item['file_path'], (int)$item['is_published']]);
        if (quiz_ready()) db_exec('UPDATE lessons SET quiz_data = ? WHERE id = ?', [$item['quiz_data'], $newId]);
        if (assignments_ready()) db_exec('UPDATE lessons SET assignment_points = ? WHERE id = ?', [$item['assignment_points'], $newId]);
        if (schedule_ready()) db_exec('UPDATE lessons SET submission_opens_at = ?, submission_due_at = ?, accept_late = ? WHERE id = ?', [$item['submission_opens_at'], $item['submission_due_at'], (int)$item['accept_late'], $newId]);
        array_splice($order, (int)array_search($lessonId, $order, true), 0, [$newId]);
        foreach ($order as $n => $id) db_exec('UPDATE lessons SET sort_order = ? WHERE id = ?', [$n + 1, $id]);

        $oldKey = $lessonId . ':' . $mediaId;
        $newKey = $newId . ':0';
        $moveRows = fn(string $table) => db_exec("UPDATE $table SET lesson_id = ?, media_id = NULL, source_media_id = 0 WHERE lesson_id = ? AND source_media_id = ?", [$newId, $lessonId, $mediaId]);
        if (quiz_ready()) {
            $moveRows('quiz_attempts');
            // นักเรียนที่ทำแบบทดสอบนี้แล้วถือว่าผ่านบทเรียนใหม่ ไม่ถูกล็อกบทถัดไป
            db_exec('INSERT IGNORE INTO lesson_progress (user_id, lesson_id) SELECT DISTINCT user_id, ? FROM quiz_attempts WHERE lesson_id = ?', [$newId, $newId]);
            foreach (['lessons' => 'course_id = ?', 'lesson_media' => 'lesson_id IN (SELECT id FROM lessons WHERE course_id = ?)'] as $table => $where) {
                foreach (db_all("SELECT id, quiz_data FROM $table WHERE $where AND quiz_data LIKE ?", [$courseId, '%' . $oldKey . '%']) as $row) {
                    $config = json_decode((string)$row['quiz_data'], true);
                    if (!is_array($config) || array_is_list($config) || ($config['pair'] ?? '') !== $oldKey) continue;
                    $config['pair'] = $newKey;
                    db_exec("UPDATE $table SET quiz_data = ? WHERE id = ?", [json_encode($config, JSON_UNESCAPED_UNICODE), (int)$row['id']]);
                }
            }
        }
        if (assignments_ready()) $moveRows('assignment_submissions');
        if (gradebook_ready()) {
            db_exec("UPDATE grade_items SET source_key = CONCAT(kind, ':', ?), lesson_id = ?, source_media_id = 0 WHERE course_id = ? AND source_key IN (?, ?)",
                [$newKey, $newId, $courseId, 'quiz:' . $oldKey, 'assignment:' . $oldKey]);
        }
        // ไฟล์แนบย้ายไปอยู่กับบทเรียนใหม่ จึงลบเฉพาะแถวสื่อเดิม ไม่ลบไฟล์
        db_exec('DELETE FROM lesson_media WHERE id = ?', [$mediaId]);
        $conn->commit();
    } catch (Throwable $ex) {
        $conn->rollback();
        throw $ex;
    }
    flash('success', 'ย้าย “' . $item['title'] . '” ออกเป็นบทเรียนแยกแล้ว วางไว้ก่อนบทเรียนเดิม ใช้ปุ่ม ↑ ↓ เพื่อเปลี่ยนลำดับได้');
    redirect('admin/lessons.php?course_id=' . $courseId);
}
redirect('admin/lesson_form.php?id=' . $lessonId . '#media-list');
