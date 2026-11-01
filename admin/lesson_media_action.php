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
}
redirect('admin/lesson_form.php?id=' . $lessonId . '#media-list');
