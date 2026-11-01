<?php
// โหลดทุกหน้า: config + session + ฐานข้อมูล + ฟังก์ชันช่วย
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lesson_media.php';
require_once __DIR__ . '/quiz.php';
require_once __DIR__ . '/assignments.php';
require_once __DIR__ . '/schedule.php';
require_once __DIR__ . '/gradebook.php';
require_once __DIR__ . '/progress.php';

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
        $conn->query("SET time_zone = '+07:00'");
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

// โหมดมุมมองสำหรับผู้เรียน: ดูได้อย่างเดียว ไม่บันทึกข้อมูลใด ๆ (ทำแบบทดสอบ ส่งงาน ลงทะเบียน ฯลฯ)
if (!defined('INSTALLING') && $_SERVER['REQUEST_METHOD'] === 'POST' && student_preview()
    && !in_array(basename($_SERVER['SCRIPT_NAME']), ['consent.php', 'login.php'], true)) {
    $previewMsg = 'มุมมองสำหรับผู้เรียนเป็นโหมดดูอย่างเดียว ไม่บันทึกข้อมูล';
    if (basename($_SERVER['SCRIPT_NAME']) === 'ai_learn.php') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => $previewMsg], JSON_UNESCAPED_UNICODE));
    }
    flash('warning', $previewMsg);
    redirect((string)$_SERVER['REQUEST_URI']);
}
