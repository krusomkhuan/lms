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
    ensure(!str_contains($body, 'Fatal error') && !str_contains($body, 'Warning:'), 'PHP output error on ' . $path);
    return $body;
}
function field($html, $name) {
    preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m);
    ensure(isset($m[1]), 'Missing field: ' . $name);
    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}
function final_url($client) { return (string)curl_getinfo($client, CURLINFO_EFFECTIVE_URL); }
function has_link($html, $lesson) { return (bool)preg_match('/href="[^"]*lesson\.php\?id=' . $lesson . '(&|")/', $html); }
function complete($client, $lesson) {
    $html = request_page($client, 'lesson.php?id=' . $lesson);
    return request_page($client, 'lesson.php?id=' . $lesson, ['csrf' => field($html, 'csrf'), 'action' => 'complete', 'next_id' => '0']);
}
$users = []; $clients = []; $course = null;
try {
    $password = bin2hex(random_bytes(20));
    foreach (['teacher', 'student'] as $role) {
        $username = 'lock_http_' . bin2hex(random_bytes(6));
        $users[] = db_exec('INSERT INTO users (username,password,fullname,role,consent_version,consent_at) VALUES (?,?,?,?,?,NOW())', [$username, password_hash($password, PASSWORD_DEFAULT), 'Lock HTTP fixture', $role, setting('privacy_version', '1')]);
        $client = curl_init(); curl_setopt($client, CURLOPT_COOKIEFILE, ''); $clients[] = $client;
        $html = request_page($client, 'login.php');
        request_page($client, 'login.php', ['csrf' => field($html, 'csrf'), 'username' => $username, 'password' => $password]);
    }
    [$teacher, $student] = $clients;
    $course = db_exec("INSERT INTO courses (code,title,status,ai_student_enabled) VALUES ('LOCK-TEST','Lock HTTP fixture','published',0)");
    db_exec('INSERT INTO course_teachers VALUES (?,?)', [$course, $users[0]]);
    db_exec('INSERT INTO enrollments (course_id,user_id) VALUES (?,?)', [$course, $users[1]]);
    $add = fn($title, $sort, $published = 1, $until = null) => db_exec("INSERT INTO lessons (course_id,title,media_type,content,sort_order,is_published,available_until) VALUES (?,?,'text','<p>x</p>',?,?,?)", [$course, $title, $sort, $published, $until]);
    // ลำดับตาม sort_order, id: L1, L2, L3 (ปิดการเรียนแล้ว), L4 ; บทที่ซ่อนไม่ขวาง
    $l2 = $add('Lock lesson two', 2);
    $l1 = $add('Lock lesson one', 1);
    $hidden = $add('Lock hidden lesson', 1, 0);
    $l3 = $add('Lock closed lesson', 3, 1, date('Y-m-d H:i:s', time() - 3600));
    $l4 = $add('Lock lesson four', 4);

    $html = request_page($student, 'lesson.php?id=' . $l1);
    ensure(str_contains(final_url($student), 'lesson.php?id=' . $l1), 'First lesson open');
    ensure(!has_link($html, $l2) && !has_link($html, $l4) && str_contains($html, 'bi-lock'), 'Sidebar shows locked lessons without link');
    $html = request_page($student, 'course.php?id=' . $course);
    ensure(has_link($html, $l1) && !has_link($html, $l2) && !has_link($html, $l4), 'Course page links only unlocked lessons');
    ensure(str_contains($html, 'เรียน “Lock lesson one” ให้จบก่อน'), 'Course page lock hint');

    $html = request_page($student, 'lesson.php?id=' . $l2);
    ensure(str_contains(final_url($student), 'course.php?id=' . $course), 'Locked lesson redirects to course');
    ensure(str_contains($html, 'ต้องเรียนตามลำดับ') && str_contains($html, 'Lock lesson one'), 'Flash names lesson to finish first');
    $html = request_page($student, 'lesson.php?id=' . $l1);
    request_page($student, 'lesson.php?id=' . $l4, ['csrf' => field($html, 'csrf'), 'action' => 'complete', 'next_id' => '0']);
    ensure(!db_value('SELECT 1 FROM lesson_progress WHERE user_id = ? AND lesson_id = ?', [$users[1], $l4]), 'Locked lesson cannot be completed by POST');

    complete($student, $l1);
    request_page($student, 'lesson.php?id=' . $l2);
    ensure(str_contains(final_url($student), 'lesson.php?id=' . $l2), 'Next lesson unlocks after completion');
    request_page($student, 'lesson.php?id=' . $l4);
    ensure(str_contains(final_url($student), 'course.php'), 'Later lesson still locked');
    complete($student, $l2);
    request_page($student, 'lesson.php?id=' . $l4);
    ensure(str_contains(final_url($student), 'lesson.php?id=' . $l4), 'Closed lesson does not block');

    db_exec('DELETE FROM lesson_progress WHERE user_id = ?', [$users[1]]);
    request_page($teacher, 'lesson.php?id=' . $l4);
    ensure(str_contains(final_url($teacher), 'lesson.php?id=' . $l4), 'Teacher never locked');
    $html = request_page($teacher, 'course.php?id=' . $course);
    ensure(has_link($html, $l4) && has_link($html, $hidden), 'Teacher course page links all lessons');

    db_exec('UPDATE courses SET sequential_lessons = 0 WHERE id = ?', [$course]);
    request_page($student, 'lesson.php?id=' . $l4);
    ensure(str_contains(final_url($student), 'lesson.php?id=' . $l4), 'Sequential setting off');
    $html = request_page($teacher, 'admin/course_form.php?id=' . $course);
    ensure(str_contains($html, 'name="sequential_lessons"') && !preg_match('/id="seqLessons" checked/', $html), 'Course form shows setting');
    echo "HTTP tests passed: sequential lock, redirect + flash, locked UI, POST guard, closed lesson skip, staff bypass, per-course switch\n";
} finally {
    if ($course) db_exec('DELETE FROM courses WHERE id = ?', [$course]);
    foreach ($users as $id) db_exec('DELETE FROM users WHERE id = ?', [$id]);
    foreach ($clients as $client) curl_close($client);
    echo "Test fixtures removed\n";
}
