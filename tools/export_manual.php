<?php
// ส่งออกเนื้อหาคู่มือเป็น JSON สำหรับสร้างไฟล์ Word/PDF (รันจาก command line เท่านั้น)
// ใช้: php tools/export_manual.php > manual.json
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
define('INSTALLING', true);                       // ไม่ต้องเช็กล็อกอิน/consent
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
require __DIR__ . '/../includes/bootstrap.php';
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');
require __DIR__ . '/../includes/manual_data.php';

$months = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
$parts = [];
foreach (manual_content(true) as $key => $m) {
    $parts[] = [
        'key' => $key,
        'label' => $m['label'],
        'sections' => array_map(fn($s) => ['id' => $s[0], 'title' => $s[1], 'html' => $s[3]], $m['sections']),
    ];
}
echo json_encode([
    'site_name' => setting('site_name', 'Vocational Teacher'),
    'slogan' => setting('site_slogan', 'ครูสมควรออนไลน์'),
    'org' => setting('pdpa_org_name'),
    'version' => APP_VERSION,
    'date_th' => date('j') . ' ' . $months[(int)date('n')] . ' ' . (date('Y') + 543),
    'parts' => $parts,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
