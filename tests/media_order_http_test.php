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
$users = []; $clients = []; $course = 0;
try {
    $password = bin2hex(random_bytes(20));
    foreach (['teacher', 'student', 'student', 'student'] as $role) {
        $username = 'order_http_' . bin2hex(random_bytes(6));
        $users[] = db_exec('INSERT INTO users (username,password,fullname,role,consent_version,consent_at) VALUES (?,?,?,?,?,NOW())', [$username, password_hash($password, PASSWORD_DEFAULT), 'Order ' . count($users), $role, setting('privacy_version', '1')]);
        $client = curl_init(); curl_setopt($client, CURLOPT_COOKIEFILE, ''); $clients[] = $client;
        $html = request_page($client, 'login.php'); request_page($client, 'login.php', ['csrf' => field($html, 'csrf'), 'username' => $username, 'password' => $password]);
    }
    [$teacher, $student, $finished, $relaxed] = $clients;
    [$teacherId, $studentId, $finishedId, $relaxedId] = $users;
    $course = db_exec("INSERT INTO courses (code,title,status,ai_student_enabled) VALUES ('ORDER-T','Media order fixture','published',0)");
    db_exec('INSERT INTO course_teachers VALUES (?,?)', [$course, $teacherId]);
    foreach ([$studentId, $finishedId, $relaxedId] as $uid) db_exec('INSERT INTO enrollments (course_id,user_id) VALUES (?,?)', [$course, $uid]);
    $lesson = db_exec("INSERT INTO lessons (course_id,title,media_type,content,is_published,sort_order) VALUES (?,'Unit order','text','Main body text',1,1)", [$course]);
    $quiz = json_encode([['text' => '1 + 1', 'options' => ['2', '3'], 'correct' => 0]]);
    $mediaRow = fn($title, $type, $order, $quizData = null) => db_exec('INSERT INTO lesson_media (lesson_id,title,media_type,media_url,quiz_data,is_published,sort_order) VALUES (?,?,?,?,?,1,?)', [$lesson, $title, $type, $type === 'link' ? 'https://example.com/' . $order : null, $quizData, $order]);
    $first = $mediaRow('Step one link', 'link', 1);
    $quizId = $mediaRow('Step two quiz', 'quiz', 2, $quiz);
    $last = $mediaRow('Step three link', 'link', 3);
    $url = fn($mid = 0) => 'lesson.php?id=' . $lesson . ($mid ? '&media_id=' . $mid : '');
    $lessonDone = fn($uid) => (bool)db_value('SELECT 1 FROM lesson_progress WHERE lesson_id = ? AND user_id = ?', [$lesson, $uid]);

    // Jumping straight to a later item sends the student back to the first unfinished one.
    $html = request_page($student, $url($quizId));
    ensure(str_contains($html, 'ต้องเรียนตามลำดับ') && str_contains($html, 'Main body text'), 'Skip to quiz allowed');
    ensure(str_contains($html, 'ต่อไป: Step one link') && substr_count($html, 'ล็อก · เรียนรายการก่อนหน้าให้เสร็จก่อน') === 2, 'Next button / sidebar locks');
    request_page($student, $url(), ['csrf' => field($html, 'csrf'), 'action' => 'complete', 'next_id' => 0]);
    ensure(!$lessonDone($studentId), 'Completed lesson with unfinished items');

    $html = request_page($student, $url($first));
    ensure(str_contains($html, 'https://example.com/1') && str_contains($html, 'ต่อไป: Step two quiz') && substr_count($html, 'ล็อก · เรียนรายการก่อนหน้าให้เสร็จก่อน') === 1, 'Step one');
    ensure(str_contains(request_page($student, $url($last)), 'กรุณาเรียน “Step two quiz” ให้เสร็จก่อน'), 'Skipped unanswered quiz');
    $html = request_page($student, $url($quizId));
    ensure(str_contains($html, 'ทำแบบทดสอบปรนัยนี้ให้เสร็จก่อน'), 'Quiz must be submitted');
    request_page($student, $url($quizId), ['csrf' => field($html, 'csrf'), 'action' => 'submit_quiz', 'submission_token' => field($html, 'submission_token'), 'answers' => ['0']]);
    $html = request_page($student, $url($last));
    ensure(str_contains($html, 'https://example.com/3') && !str_contains($html, 'ล็อก · เรียนรายการก่อนหน้า') && str_contains($html, 'value="complete"'), 'Last step');
    request_page($student, $url($last), ['csrf' => field($html, 'csrf'), 'action' => 'complete', 'next_id' => 0]);
    ensure($lessonDone($studentId), 'Complete after all steps');

    // A lesson finished before this rule existed stays fully open.
    db_exec('INSERT INTO lesson_progress (user_id, lesson_id) VALUES (?, ?)', [$finishedId, $lesson]);
    ensure(str_contains(request_page($finished, $url($last)), 'https://example.com/3'), 'Finished lesson relocked');
    // Teachers are never locked.
    ensure(str_contains(request_page($teacher, $url($last)), 'https://example.com/3'), 'Teacher locked');
    // A closed quiz does not block the items after it.
    db_exec('UPDATE lesson_media SET quiz_data = ? WHERE id = ?', [json_encode(['questions' => json_decode($quiz, true), 'closes_at' => '2020-01-01T00:00']), $quizId]);
    request_page($relaxed, $url()); request_page($relaxed, $url($first));
    ensure(str_contains(request_page($relaxed, $url($last)), 'https://example.com/3'), 'Closed quiz blocked');
    // Courses that do not require order leave every item open.
    db_exec('DELETE FROM media_progress WHERE user_id = ?', [$relaxedId]);
    db_exec('UPDATE courses SET sequential_lessons = 0 WHERE id = ?', [$course]);
    ensure(str_contains(request_page($relaxed, $url($last)), 'https://example.com/3'), 'Non-sequential course locked');
    echo "Media order HTTP tests passed: in-lesson order, direct-link skip, quiz gate, completion gate, finished lessons, teachers, closed quiz, non-sequential course\n";
} finally {
    if ($course) db_exec('DELETE FROM courses WHERE id = ?', [$course]);
    foreach ($users as $id) db_exec('DELETE FROM users WHERE id = ?', [$id]);
    foreach ($clients as $client) curl_close($client);
    echo "Test fixtures removed\n";
}
