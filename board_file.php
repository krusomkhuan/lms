<?php
// ไฟล์แนบในกระดาน: ส่งให้เฉพาะผู้ที่เห็นโพสต์นั้นได้ รูป/วิดีโอ/เสียง/PDF แสดงในหน้า (วิดีโอรองรับ Range เพื่อเลื่อนดู)
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();
$file = boards_ready() ? db_one('SELECT a.*, p.board_id, p.user_id AS owner_id FROM board_attachments a JOIN board_posts p ON p.id = a.post_id WHERE a.id = ? AND p.deleted_at IS NULL', [get_int('id')]) : null;
$board = $file ? board_get((int)$file['board_id']) : null;
$role = $board ? board_role($board, $me) : null;
if (!$file || !$role || !board_can_view_post($board, $role, (int)$me['id'], (int)$file['post_id'])) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์เปิดไฟล์นี้');
}
$path = (string)$file['path'];
$full = UPLOAD_PATH . '/' . $path;
if (!preg_match('~^boards/[a-f0-9]{48}\.[a-z0-9]+$~D', $path) || !is_file($full)) {
    http_response_code(404);
    exit('ไม่พบไฟล์');
}
session_write_close();
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$inline = isset(BOARD_MIME[$ext]) && empty($_GET['download']);
$size = filesize($full);
header('Content-Type: ' . ($inline ? BOARD_MIME[$ext] : 'application/octet-stream'));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="file-' . (int)$file['id'] . '.' . $ext . '"; filename*=UTF-8\'\'' . rawurlencode($file['original_name']));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: private, max-age=3600');
header('Accept-Ranges: bytes');
$start = 0;
$end = $size - 1;
if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); }
    else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
$fh = fopen($full, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) {
    $chunk = fread($fh, min(1048576, $left));
    echo $chunk;
    $left -= strlen($chunk);
    flush();
}
fclose($fh);
