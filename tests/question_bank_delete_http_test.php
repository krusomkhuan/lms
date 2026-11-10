<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/functions.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME); $conn->set_charset('utf8mb4');
function ensure($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function request_page($client, $path, $data = null) {
    curl_setopt_array($client, [CURLOPT_URL => 'http://localhost/lms/' . $path, CURLOPT_POST => $data !== null, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20]);
    if ($data !== null) curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($data));
    $body = curl_exec($client); ensure($body !== false, curl_error($client));
    ensure(!str_contains($body, 'Fatal error') && !str_contains($body, 'Warning:'), 'PHP error: ' . $path);
    return $body;
}
function field($html, $name) {
    preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $matches);
    ensure(isset($matches[1]), 'Missing ' . $name);
    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}
$users = []; $clients = []; $courses = [];
try {
    $password = bin2hex(random_bytes(20));
    foreach (['teacher', 'teacher'] as $role) {
        $username = 'bankdel_http_' . bin2hex(random_bytes(6));
        $users[] = db_exec('INSERT INTO users (username,password,fullname,role,consent_version,consent_at) VALUES (?,?,?,?,?,NOW())', [$username, password_hash($password, PASSWORD_DEFAULT), 'Bank delete ' . count($users), $role, setting('privacy_version', '1')]);
        $client = curl_init(); curl_setopt($client, CURLOPT_COOKIEFILE, ''); $clients[] = $client;
        $html = request_page($client, 'login.php'); request_page($client, 'login.php', ['csrf' => field($html, 'csrf'), 'username' => $username, 'password' => $password]);
    }
    [$teacher, $otherTeacher] = $clients;
    foreach (['BANKDEL-A', 'BANKDEL-B'] as $code) $courses[] = db_exec("INSERT INTO courses (code,title,status) VALUES (?,'Bank delete fixture','published')", [$code]);
    [$course, $foreign] = $courses;
    db_exec('INSERT INTO course_teachers VALUES (?,?)', [$course, $users[0]]);
    $add = fn($c, $cat) => db_exec("INSERT INTO question_bank (course_id,category,question_text,options_json,correct) VALUES (?,?,'Q','[\"a\",\"b\"]',0)", [$c, $cat]);
    $pre = [$add($course, 'Pre 30'), $add($course, 'Pre 30'), $add($course, 'Pre 30')];
    $post = [$add($course, 'Post 30'), $add($course, 'Post 30')];
    $foreignId = $add($foreign, 'Pre 30');
    db_exec("INSERT INTO lessons (course_id,title,media_type,quiz_data,is_published,sort_order) VALUES (?,'Bank pretest','quiz',?,1,1)", [$course, json_encode(['source' => 'bank', 'categories' => ['Pre 30'], 'count' => 3, 'kind' => 'pre'])]);
    $count = fn($cat) => (int)db_value('SELECT COUNT(*) FROM question_bank WHERE course_id = ? AND category = ?', [$course, $cat]);
    $page = 'admin/question_bank.php?course_id=' . $course;

    $html = request_page($teacher, $page . '&cat=' . urlencode('Post 30'));
    ensure(str_contains($html, 'value="delete_category"') && str_contains($html, 'value="delete_selected"') && str_contains($html, 'form="bulkDelete"'), 'Delete controls');

    // Another course's teacher cannot delete, and ids from another course are ignored.
    request_page($otherTeacher, $page, ['csrf' => field(request_page($otherTeacher, 'profile.php'), 'csrf'), 'action' => 'delete_category', 'cat' => 'Pre 30']);
    ensure(curl_getinfo($otherTeacher, CURLINFO_HTTP_CODE) === 403 && $count('Pre 30') === 3, 'Other teacher deleted');

    $result = request_page($teacher, $page, ['csrf' => field($html, 'csrf'), 'action' => 'delete_selected', 'cat' => 'Pre 30', 'ids' => [$pre[0], $pre[1], $foreignId]]);
    ensure($count('Pre 30') === 1 && db_value('SELECT 1 FROM question_bank WHERE id = ?', [$foreignId]), 'Selected delete');
    ensure(str_contains($result, 'ลบข้อสอบ 2 ข้อ') && str_contains($result, 'Bank pretest (ต้องการ 3 เหลือ 1 ข้อ)'), 'Shortage warning');

    $html = request_page($teacher, $page);
    request_page($teacher, $page, ['csrf' => field($html, 'csrf'), 'action' => 'delete_selected', 'cat' => '', 'ids' => []]);
    ensure($count('Pre 30') === 1, 'Empty selection');

    $result = request_page($teacher, $page, ['csrf' => field($html, 'csrf'), 'action' => 'delete_category', 'cat' => 'Post 30']);
    ensure($count('Post 30') === 0 && $count('Pre 30') === 1 && str_contains($result, 'ลบข้อสอบ 2 ข้อ'), 'Category delete');

    request_page($teacher, $page, ['csrf' => field($html, 'csrf'), 'action' => 'delete', 'id' => $pre[2], 'cat' => 'Pre 30']);
    ensure($count('Pre 30') === 0, 'Single delete');
    echo "Question bank delete HTTP tests passed: single, selected, whole category, shortage warning, course isolation, permissions\n";
} finally {
    foreach ($courses as $id) db_exec('DELETE FROM courses WHERE id = ?', [$id]);
    foreach ($users as $id) db_exec('DELETE FROM users WHERE id = ?', [$id]);
    foreach ($clients as $client) curl_close($client);
    echo "Test fixtures removed\n";
}
