<?php
// PDPA: สิทธิขอรับสำเนา/โอนย้ายข้อมูล — ดาวน์โหลดข้อมูลส่วนบุคคลของตนเองเป็น JSON
require_once __DIR__ . '/includes/bootstrap.php';

$me = require_login();
$data = pdpa_export_user((int)$me['id']);

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="my-data_' . $me['username'] . '_' . date('Ymd') . '.json"');
header('Cache-Control: no-store');
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
