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
$users = []; $clients = []; $course = null;
try {
    $password = bin2hex(random_bytes(20));
    foreach (['teacher', 'student', 'student'] as $role) {
        $username = 'quiz_http_' . bin2hex(random_bytes(6));
        $users[] = db_exec('INSERT INTO users (username,password,fullname,role,consent_version,consent_at) VALUES (?,?,?,?,?,NOW())', [$username, password_hash($password, PASSWORD_DEFAULT), 'Quiz HTTP fixture', $role, setting('privacy_version', '1')]);
        $client = curl_init(); curl_setopt($client, CURLOPT_COOKIEFILE, ''); $clients[] = $client;
        $html = request_page($client, 'login.php');
        request_page($client, 'login.php', ['csrf' => field($html, 'csrf'), 'username' => $username, 'password' => $password]);
    }
    [$teacher, $student, $outsider] = $clients;
    $course = db_exec("INSERT INTO courses (code,title,status,ai_student_enabled) VALUES ('QUIZ-TEST','Quiz HTTP fixture','published',0)");
    db_exec('INSERT INTO course_teachers VALUES (?,?)', [$course, $users[0]]);
    db_exec('INSERT INTO enrollments (course_id,user_id) VALUES (?,?)', [$course, $users[1]]);
    $quiz = json_encode([['text' => '2 + 2', 'options' => ['3', '4'], 'correct' => 1], ['text' => '3 + 3', 'options' => ['6', '7'], 'correct' => 0]]);
    $path = 'admin/lesson_form.php?course_id=' . $course;
    $html = request_page($teacher, $path);
    ensure(str_contains($html, 'data-media-type="quiz"') && !str_contains($html, 'data-media-type="file"'), 'New media menu');
    request_page($teacher, $path, ['csrf' => field($html, 'csrf'), 'title' => 'Quiz HTTP fixture', 'media_type' => 'quiz', 'quiz_data' => $quiz, 'is_published' => '1', 'extra_types' => ['quiz'], 'extra_quizzes' => [$quiz], 'extra_titles' => ['Extra fixture']]);
    $lesson = (int)db_value('SELECT id FROM lessons WHERE course_id = ?', [$course]);
    $media = (int)db_value('SELECT id FROM lesson_media WHERE lesson_id = ?', [$lesson]);
    ensure($lesson > 0 && $media > 0, 'Save primary and additional quiz');
    foreach (['lesson.php?id=' . $lesson, 'lesson.php?id=' . $lesson . '&media_id=' . $media] as $path) {
        $html = request_page($student, $path);
        ensure(!str_contains($html, '"correct"') && !str_contains($html, '&quot;correct&quot;'), 'Answer key exposed');
        $payload = ['csrf' => field($html, 'csrf'), 'action' => 'submit_quiz', 'submission_token' => field($html, 'submission_token'), 'answers' => ['0', '0'], 'score' => '999'];
        $result = request_page($student, $path, $payload);
        ensure(str_contains($result, 'บันทึกคะแนนแล้ว: 1 / 2'), 'Grade/save response');
        request_page($student, $path, $payload);
    }
    ensure((int)db_value('SELECT COUNT(*) FROM quiz_attempts WHERE lesson_id = ?', [$lesson]) === 2, 'Duplicate submissions saved');
    ensure((int)db_value('SELECT SUM(score) FROM quiz_attempts WHERE lesson_id = ?', [$lesson]) === 2, 'Client score trusted');
    $report = request_page($teacher, 'admin/quiz_results.php?lesson_id=' . $lesson);
    ensure(str_contains($report, 'Extra fixture') && str_contains($report, '50%'), 'Teacher report');
    request_page($student, 'admin/quiz_results.php?lesson_id=' . $lesson);
    ensure(curl_getinfo($student, CURLINFO_HTTP_CODE) === 403, 'Student report access');
    $html = request_page($outsider, 'lesson.php?id=' . $lesson);
    ensure(!str_contains($html, 'name="submission_token"'), 'Unenrolled quiz access');
    db_exec('UPDATE lesson_media SET is_published = 0 WHERE id = ?', [$media]);
    request_page($student, 'lesson.php?id=' . $lesson . '&media_id=' . $media);
    ensure(curl_getinfo($student, CURLINFO_HTTP_CODE) === 404, 'Hidden quiz access');
    $path = 'lesson.php?id=' . $lesson;
    $html = request_page($student, $path);
    db_exec('UPDATE lessons SET quiz_data = ? WHERE id = ?', [str_replace('2 + 2', 'Updated question', $quiz), $lesson]);
    request_page($student, $path, ['csrf' => field($html, 'csrf'), 'action' => 'submit_quiz', 'submission_token' => field($html, 'submission_token'), 'answers' => ['1', '0']]);
    ensure((int)db_value('SELECT COUNT(*) FROM quiz_attempts WHERE lesson_id = ?', [$lesson]) === 2, 'Stale quiz saved');
    echo "HTTP tests passed: authoring, primary/additional quiz, server grading, history, duplicate/stale protection, report and access control\n";
} finally {
    if ($course) db_exec('DELETE FROM courses WHERE id = ?', [$course]);
    foreach ($users as $id) db_exec('DELETE FROM users WHERE id = ?', [$id]);
    foreach ($clients as $client) curl_close($client);
    echo "Test fixtures removed\n";
}
