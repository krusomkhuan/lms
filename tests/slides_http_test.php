<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/functions.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');
$base = 'http://localhost/lms/';
function ensure($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function request_page($client, $path, $data = null, $multipart = false) {
    global $base;
    curl_setopt_array($client, [CURLOPT_URL => $base . $path, CURLOPT_POST => $data !== null, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20]);
    if ($data !== null) curl_setopt($client, CURLOPT_POSTFIELDS, $multipart ? $data : http_build_query($data));
    $body = curl_exec($client);
    ensure($body !== false, curl_error($client));
    ensure(!str_contains($body, 'Fatal error') && !str_contains($body, 'Warning:') && !str_contains($body, 'Notice:'), 'PHP output error on ' . $path);
    return $body;
}
function field($html, $name) {
    preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m);
    ensure(isset($m[1]), 'Missing field: ' . $name);
    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}

// แปลงลิงก์สไลด์เป็นลิงก์ฝัง
$g = slides_embed(['media_url' => 'https://docs.google.com/presentation/d/AbC_123-x/edit?usp=sharing'], '');
ensure($g['embed'] === 'https://docs.google.com/presentation/d/AbC_123-x/embed?start=false&loop=false', 'Google Slides embed');
$o = slides_embed(['media_url' => ''], 'https://school.example.ac.th/lms/uploads/lessons/a.pptx');
ensure($o['embed'] === 'https://view.officeapps.live.com/op/embed.aspx?src=' . rawurlencode('https://school.example.ac.th/lms/uploads/lessons/a.pptx') && !$o['local'], 'Office viewer for public file');
ensure(slides_embed(['media_url' => ''], 'http://localhost/lms/uploads/lessons/a.pptx')['local'] === true, 'Localhost file cannot be previewed');
$_SERVER['HTTP_HOST'] = 'school.example.ac.th'; $_SERVER['HTTPS'] = 'on';
ensure(slides_embed(['media_url' => ''], '/lms/uploads/lessons/a.pptx')['embed'] === 'https://view.officeapps.live.com/op/embed.aspx?src=' . rawurlencode('https://school.example.ac.th/lms/uploads/lessons/a.pptx'), 'Path-only base URL gets current host');
unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);

$users = []; $clients = []; $course = null; $files = [];
try {
    ensure(slides_ready(), 'Run database/migrations/1.15.2.sql first');
    $password = bin2hex(random_bytes(20));
    foreach (['teacher', 'student'] as $role) {
        $username = 'slides_http_' . bin2hex(random_bytes(6));
        $users[] = db_exec('INSERT INTO users (username,password,fullname,role,consent_version,consent_at) VALUES (?,?,?,?,?,NOW())', [$username, password_hash($password, PASSWORD_DEFAULT), 'Slides fixture', $role, setting('privacy_version', '1')]);
        $client = curl_init(); curl_setopt($client, CURLOPT_COOKIEFILE, ''); $clients[] = $client;
        $html = request_page($client, 'login.php');
        request_page($client, 'login.php', ['csrf' => field($html, 'csrf'), 'username' => $username, 'password' => $password]);
    }
    [$teacher, $student] = $clients;
    $course = db_exec("INSERT INTO courses (code,title,status,ai_student_enabled,sequential_lessons) VALUES ('SLIDE-TEST','Slides fixture','published',0,0)");
    db_exec('INSERT INTO course_teachers VALUES (?,?)', [$course, $users[0]]);
    db_exec('INSERT INTO enrollments (course_id,user_id) VALUES (?,?)', [$course, $users[1]]);

    $form = 'admin/lesson_form.php?course_id=' . $course;
    $html = request_page($teacher, $form);
    ensure(str_contains($html, 'data-media-type="slides"') && str_contains($html, 'งานนำเสนอ PowerPoint'), 'Slides type offered');
    $pptx = sys_get_temp_dir() . '/slides_' . bin2hex(random_bytes(4)) . '.pptx';
    file_put_contents($pptx, "PK\x03\x04 fixture");
    // อัปโหลดเป็นสื่อหลัก + ลิงก์ Google Slides เป็นสื่อเพิ่มเติม
    request_page($teacher, $form, ['csrf' => field($html, 'csrf'), 'title' => 'Slides lesson', 'media_type' => 'slides', 'is_published' => '1',
        'file' => new CURLFile($pptx, 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'deck.pptx'),
        'extra_types[0]' => 'slides', 'extra_titles[0]' => 'Google deck', 'extra_urls[0]' => 'https://docs.google.com/presentation/d/AbC_123-x/edit'], true);
    unlink($pptx);
    $lesson = db_one("SELECT * FROM lessons WHERE course_id = ? AND title = 'Slides lesson'", [$course]);
    ensure($lesson && $lesson['media_type'] === 'slides' && str_ends_with((string)$lesson['file_path'], '.pptx') && is_file(UPLOAD_PATH . '/' . $lesson['file_path']), 'Saved uploaded pptx');
    $files[] = UPLOAD_PATH . '/' . $lesson['file_path'];
    $media = db_one("SELECT * FROM lesson_media WHERE lesson_id = ?", [$lesson['id']]);
    ensure($media && $media['media_type'] === 'slides' && $media['media_url'] === 'https://docs.google.com/presentation/d/AbC_123-x/edit', 'Saved Google Slides link');

    // ไฟล์ชนิดอื่นถูกปฏิเสธ
    $bad = sys_get_temp_dir() . '/slides_' . bin2hex(random_bytes(4)) . '.exe';
    file_put_contents($bad, 'MZ');
    $result = request_page($teacher, $form, ['csrf' => field($html, 'csrf'), 'title' => 'Bad slides', 'media_type' => 'slides', 'is_published' => '1', 'file' => new CURLFile($bad, 'application/octet-stream', 'deck.exe')], true);
    unlink($bad);
    ensure(str_contains($result, 'ไม่อนุญาตไฟล์ชนิด .exe') && !db_value("SELECT 1 FROM lessons WHERE course_id = ? AND title = 'Bad slides'", [$course]), 'Reject non-slide file');

    // หน้าบทเรียนของนักเรียน: localhost ไม่มีตัวอย่าง (แจ้ง + ดาวน์โหลด) · ลิงก์ Google Slides ฝังได้
    $page = request_page($student, 'lesson.php?id=' . $lesson['id']);
    ensure(str_contains($page, 'ตัวอย่างสไลด์แสดงได้เมื่อเว็บอยู่บนเซิร์ฟเวอร์จริง') && str_contains($page, 'ดาวน์โหลดไฟล์ (.pptx)'), 'Uploaded slides on localhost');
    $page = request_page($student, 'lesson.php?id=' . $lesson['id'] . '&media_id=' . $media['id']);
    ensure(str_contains($page, 'docs.google.com/presentation/d/AbC_123-x/embed'), 'Google Slides embedded');
    echo "Slides HTTP tests passed: embed URLs, pptx upload, Google Slides link, file type check, lesson page\n";
} finally {
    if ($course) db_exec('DELETE FROM courses WHERE id = ?', [$course]);
    foreach ($users as $id) db_exec('DELETE FROM users WHERE id = ?', [$id]);
    foreach ($clients as $client) curl_close($client);
    foreach ($files as $file) if (is_file($file)) unlink($file);
    echo "Test fixtures and files removed\n";
}
