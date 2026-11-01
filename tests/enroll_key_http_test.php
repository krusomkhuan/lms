<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/functions.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');
$base = 'http://localhost/lms/';
function ensure($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function request_page($client, $path, $data = null) {
    global $base;
    curl_setopt_array($client, [CURLOPT_URL => $base . $path, CURLOPT_POST => $data !== null, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20]);
    if ($data !== null) curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($data));
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

// ค่าเริ่มต้นเป็นรหัสวิชา · ไม่สนตัวพิมพ์/ช่องว่าง · ครูตั้งรหัสเองได้
ensure(course_enroll_key(['code' => '31910-2008', 'enroll_key' => null]) === '31910-2008' && enroll_key_matches(['code' => '31910-2008'], ' 31910-2008 '), 'Default key is course code');
ensure(enroll_key_matches(['code' => 'X', 'enroll_key' => 'AI 2569'], 'ai2569') && !enroll_key_matches(['code' => 'X', 'enroll_key' => 'AI2569'], 'X') && !enroll_key_matches(['code' => 'X'], ''), 'Custom key replaces course code');

$users = []; $clients = []; $course = null;
try {
    ensure(enroll_key_ready(), 'Run database/migrations/1.15.4.sql first');
    $password = bin2hex(random_bytes(20));
    foreach (['teacher', 'student', 'student'] as $role) {
        $username = 'ekey_http_' . bin2hex(random_bytes(6));
        $users[] = db_exec('INSERT INTO users (username,password,fullname,role,consent_version,consent_at) VALUES (?,?,?,?,?,NOW())', [$username, password_hash($password, PASSWORD_DEFAULT), 'Enroll key fixture', $role, setting('privacy_version', '1')]);
        $client = curl_init(); curl_setopt($client, CURLOPT_COOKIEFILE, ''); $clients[] = $client;
        $html = request_page($client, 'login.php');
        request_page($client, 'login.php', ['csrf' => field($html, 'csrf'), 'username' => $username, 'password' => $password]);
    }
    [$teacher, $student, $outsider] = $clients;
    $course = db_exec("INSERT INTO courses (code,title,status,enroll_type,ai_student_enabled) VALUES ('EKEY-2008','Enroll key fixture','published','open',0)");
    db_exec('INSERT INTO course_teachers VALUES (?,?)', [$course, $users[0]]);
    $page = 'course.php?id=' . $course;
    $enrolled = fn($uid) => (bool)db_value('SELECT 1 FROM enrollments WHERE course_id = ? AND user_id = ?', [$course, $uid]);

    // ไม่กรอก/ผิด → ไม่ได้ลงทะเบียน · รหัสวิชาถูก → ลงทะเบียนได้
    $html = request_page($student, $page);
    ensure(str_contains($html, 'name="enroll_key"'), 'Enroll key field shown');
    $result = request_page($student, $page, ['csrf' => field($html, 'csrf'), 'action' => 'enroll']);
    ensure(!$enrolled($users[1]) && str_contains($result, 'รหัสลงทะเบียนไม่ถูกต้อง'), 'Missing key rejected');
    request_page($student, $page, ['csrf' => field($html, 'csrf'), 'action' => 'enroll', 'enroll_key' => 'ekey-2008']);
    ensure($enrolled($users[1]), 'Course code enrolls (case-insensitive)');

    // ครูตั้งรหัสเอง: รหัสวิชาใช้ไม่ได้แล้ว
    $form = request_page($teacher, 'admin/course_form.php?id=' . $course);
    ensure(str_contains($form, 'name="enroll_key"') && str_contains($form, 'placeholder="EKEY-2008"'), 'Teacher sees enroll key field');
    request_page($teacher, 'admin/course_form.php?id=' . $course, ['csrf' => field($form, 'csrf'), 'code' => 'EKEY-2008', 'title' => 'Enroll key fixture', 'enroll_type' => 'open', 'status' => 'published', 'enroll_key' => 'Secret 99', 'teachers' => [$users[0]], 'sequential_lessons' => '1']);
    ensure(db_value('SELECT enroll_key FROM courses WHERE id = ?', [$course]) === 'Secret 99', 'Custom key saved');
    $html = request_page($outsider, $page);
    request_page($outsider, $page, ['csrf' => field($html, 'csrf'), 'action' => 'enroll', 'enroll_key' => 'EKEY-2008']);
    ensure(!$enrolled($users[2]), 'Course code no longer works after custom key');

    // ผิดครบ 5 ครั้ง ล็อก 10 นาที แม้รหัสถูก
    for ($i = 0; $i < 4; $i++) request_page($outsider, $page, ['csrf' => field($html, 'csrf'), 'action' => 'enroll', 'enroll_key' => 'guess' . $i]);
    $result = request_page($outsider, $page, ['csrf' => field($html, 'csrf'), 'action' => 'enroll', 'enroll_key' => 'secret99']);
    ensure(!$enrolled($users[2]) && str_contains($result, 'กรอกรหัสลงทะเบียนผิดหลายครั้ง'), 'Lockout after 5 wrong keys');

    // ลบรหัส → กลับไปใช้รหัสวิชา
    db_exec('UPDATE courses SET enroll_key = NULL WHERE id = ?', [$course]);
    ensure(enroll_key_matches(db_one('SELECT * FROM courses WHERE id = ?', [$course]), 'EKEY-2008'), 'Empty key falls back to course code');
    echo "Enroll key HTTP tests passed: default course code, custom key, case/space tolerance, missing/wrong key, lockout\n";
} finally {
    if ($course) db_exec('DELETE FROM courses WHERE id = ?', [$course]);
    foreach ($users as $id) db_exec('DELETE FROM users WHERE id = ?', [$id]);
    foreach ($clients as $client) curl_close($client);
    echo "Test fixtures removed\n";
}
