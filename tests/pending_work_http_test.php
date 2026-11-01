<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/functions.php';
foreach (['lesson_media', 'quiz', 'assignments', 'schedule', 'gradebook', 'progress'] as $inc) require_once __DIR__ . '/../includes/' . $inc . '.php';
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
$users = []; $clients = []; $course = null;
try {
    $password = bin2hex(random_bytes(20));
    foreach (['student', 'teacher'] as $role) {
        $username = 'pend_http_' . bin2hex(random_bytes(6));
        $users[] = db_exec('INSERT INTO users (username,password,fullname,role,consent_version,consent_at) VALUES (?,?,?,?,?,NOW())', [$username, password_hash($password, PASSWORD_DEFAULT), 'Pending fixture', $role, setting('privacy_version', '1')]);
        $client = curl_init(); curl_setopt($client, CURLOPT_COOKIEFILE, ''); $clients[] = $client;
        $html = request_page($client, 'login.php');
        request_page($client, 'login.php', ['csrf' => field($html, 'csrf'), 'username' => $username, 'password' => $password]);
    }
    [$student, $teacher] = $clients;
    $course = db_exec("INSERT INTO courses (code,title,status,ai_student_enabled,sequential_lessons) VALUES ('PEND-1','Pending course','published',0,0)");
    db_exec('INSERT INTO enrollments (course_id,user_id) VALUES (?,?)', [$course, $users[0]]);
    $lesson = fn($title, $extra = []) => db_exec('INSERT INTO lessons (course_id,title,media_type,is_published,sort_order,assignment_points,submission_due_at,accept_late,submission_opens_at,available_until) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$course, $title, 'assignment', $extra['published'] ?? 1, 1, 10, $extra['due'] ?? null, $extra['late'] ?? 0, $extra['opens'] ?? null, $extra['until'] ?? null]);
    $open = $lesson('Open work', ['due' => date('Y-m-d H:i:s', time() + 86400)]);
    $late = $lesson('Late work', ['due' => date('Y-m-d H:i:s', time() - 3600), 'late' => 1]);
    $lesson('Closed work', ['due' => date('Y-m-d H:i:s', time() - 3600)]);
    $lesson('Upcoming work', ['opens' => date('Y-m-d H:i:s', time() + 86400)]);
    $lesson('Hidden work', ['published' => 0]);
    $done = $lesson('Done work');
    $redo = $lesson('Redo work');
    foreach ([[$done, 'submitted'], [$redo, 'revision']] as [$l, $status]) {
        db_exec('INSERT INTO assignment_submissions (lesson_id,source_media_id,user_id,assignment_title,max_score,status,submission_token) VALUES (?,0,?,?,10,?,?)', [$l, $users[0], 'x', $status, bin2hex(random_bytes(32))]);
    }
    $titles = array_column(student_pending_work($users[0]), 'title');
    ensure($titles === ['Late work', 'Open work', 'Redo work'], 'Pending list and order: ' . implode(',', $titles));

    $page = request_page($student, 'my-courses.php');
    ensure(str_contains($page, 'คุณมีงานค้างส่ง 3 งาน (เลยกำหนดแล้ว 1 งาน)') && str_contains($page, 'ครูให้แก้ไขแล้วส่งใหม่') && str_contains($page, 'lesson.php?id=' . $late), 'My courses alert');
    ensure(str_contains($page, 'aria-label="งานค้างส่ง 3 งาน"') && str_contains($page, 'bg-danger" style="margin-top'), 'Bell badge');
    ensure(!str_contains(request_page($teacher, 'my-courses.php'), 'aria-label="งานค้างส่ง'), 'No bell for teacher');
    db_exec("DELETE FROM lessons WHERE course_id = ? AND title IN ('Open work','Late work')", [$course]);
    db_exec("UPDATE assignment_submissions SET status = 'submitted' WHERE lesson_id = ?", [$redo]);
    $page = request_page($student, 'my-courses.php');
    ensure(!str_contains($page, 'คุณมีงานค้างส่ง') && str_contains($page, 'ไม่มีงานค้างส่ง'), 'No pending work state');
    echo "Pending work HTTP tests passed: open/late/revision listed, closed/upcoming/hidden/done skipped, bell and alert, teacher excluded\n";
} finally {
    if ($course) db_exec('DELETE FROM courses WHERE id = ?', [$course]);
    foreach ($users as $id) db_exec('DELETE FROM users WHERE id = ?', [$id]);
    foreach ($clients as $client) curl_close($client);
    echo "Test fixtures removed\n";
}
