<?php
// โหลดทุกหน้า: config + session + ฐานข้อมูล + ฟังก์ชันช่วย
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

if (!file_exists(ROOT_PATH . '/config/installed.lock') && !defined('INSTALLING')) {
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : detect_base_url()) . '/install.php');
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!defined('INSTALLING')) {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $conn->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $ex) {
        http_response_code(500);
        exit('ไม่สามารถเชื่อมต่อฐานข้อมูลได้: ' . htmlspecialchars($ex->getMessage()));
    }
}

// URL ของเว็บ: config.php (ถ้ากำหนด) > ค่าในหลังบ้าน > ตรวจหาอัตโนมัติ
define('BASE_URL_LOCKED', defined('BASE_URL'));
if (!BASE_URL_LOCKED) {
    $siteUrl = defined('INSTALLING') ? '' : setting('site_url');
    define('BASE_URL', $siteUrl !== '' ? rtrim($siteUrl, '/') : detect_base_url());
    unset($siteUrl);
}
define('BASE_PATH', rtrim((string)parse_url(BASE_URL, PHP_URL_PATH), '/'));
define('UPLOAD_URL', BASE_URL . '/uploads');

require_once __DIR__ . '/pdpa.php';
if (!defined('INSTALLING')) {
    pdpa_enforce_consent();
}
