<?php
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();
$submission = assignments_ready() ? db_one('SELECT s.*, l.course_id FROM assignment_submissions s JOIN lessons l ON l.id = s.lesson_id WHERE s.id = ?', [get_int('id')]) : null;
if (!$submission || ((int)$submission['user_id'] !== (int)$me['id'] && !can_manage_course((int)$submission['course_id']))) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ดาวน์โหลดงานนี้');
}
$path = (string)$submission['file_path'];
if (!preg_match('~^submissions/[a-f0-9]{48}\.[a-z0-9]+$~D', $path) || !is_file(UPLOAD_PATH . '/' . $path)) {
    http_response_code(404);
    exit('ไม่พบไฟล์งาน');
}
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="submission-' . (int)$submission['id'] . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"; filename*=UTF-8\'\'' . rawurlencode($submission['original_name']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Length: ' . filesize(UPLOAD_PATH . '/' . $path));
session_write_close();
readfile(UPLOAD_PATH . '/' . $path);
