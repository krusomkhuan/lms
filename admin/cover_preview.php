<?php
// ตัวอย่างภาพปกอัตโนมัติ (แสดงสดในฟอร์มรายวิชา)
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/cover.php';

require_role('admin', 'teacher');

header('Content-Type: image/svg+xml; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
header('Cache-Control: no-store');
echo cover_svg(
    mb_substr((string)($_GET['code'] ?? ''), 0, 30),
    mb_substr((string)($_GET['title'] ?? ''), 0, 200) ?: 'ชื่อรายวิชา',
    mb_substr((string)($_GET['desc'] ?? ''), 0, 2000)
);
