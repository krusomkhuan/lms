<?php
// รับรูปภาพจากตัวแก้ไข WYSIWYG (TinyMCE) — ตอบกลับ JSON {location: url}
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$me = current_user();
if (!$me || !in_array($me['role'], ['admin', 'teacher'], true)) {
    http_response_code(403);
    exit(json_encode(['error' => 'ไม่มีสิทธิ์อัปโหลด']));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(csrf_token(), (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    http_response_code(400);
    exit(json_encode(['error' => 'คำขอไม่ถูกต้อง']));
}

try {
    $path = upload_file('file', 'editor/' . date('Y-m'), allowed_ext('image'));
    if (!$path) {
        throw new RuntimeException('ไม่พบไฟล์');
    }
    // ยืนยันว่าเป็นรูปภาพจริง
    if (!@getimagesize(UPLOAD_PATH . '/' . $path)) {
        delete_upload($path);
        throw new RuntimeException('ไฟล์ไม่ใช่รูปภาพ');
    }
    echo json_encode(['location' => upload_url($path)]);
} catch (RuntimeException $ex) {
    http_response_code(422);
    echo json_encode(['error' => $ex->getMessage()]);
}
