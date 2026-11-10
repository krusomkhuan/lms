<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/quiz.php';
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
    foreach (['teacher', 'student', 'student', 'teacher'] as $role) {
        $username = 'pretest_http_' . bin2hex(random_bytes(6));
        $users[] = db_exec('INSERT INTO users (username,password,fullname,role,consent_version,consent_at) VALUES (?,?,?,?,?,NOW())', [$username, password_hash($password, PASSWORD_DEFAULT), 'Pretest ' . count($users), $role, setting('privacy_version', '1')]);
        $client = curl_init(); curl_setopt($client, CURLOPT_COOKIEFILE, ''); $clients[] = $client;
        $html = request_page($client, 'login.php'); request_page($client, 'login.php', ['csrf' => field($html, 'csrf'), 'username' => $username, 'password' => $password]);
    }
    [$teacher, $early, $late, $otherTeacher] = $clients;
    [$teacherId, $earlyId, $lateId] = $users;
    $course = db_exec("INSERT INTO courses (code,title,status,ai_student_enabled) VALUES ('PRE-X','Pretest extract fixture','published',0)");
    db_exec('INSERT INTO course_teachers VALUES (?,?)', [$course, $teacherId]);
    db_exec('INSERT INTO enrollments (course_id,user_id) VALUES (?,?),(?,?)', [$course, $earlyId, $course, $lateId]);
    $questions = [['text' => '1 + 1', 'options' => ['2', '3'], 'correct' => 0], ['text' => '2 + 2', 'options' => ['4', '5'], 'correct' => 0]];
    $preQuiz = json_encode(['questions' => $questions, 'kind' => 'pre', 'max_attempts' => 1]);
    $unit1 = db_exec("INSERT INTO lessons (course_id,title,media_type,content,is_published,sort_order) VALUES (?,'Unit 1','text','Unit one',1,1)", [$course]);
    $unit2 = db_exec("INSERT INTO lessons (course_id,title,media_type,content,is_published,sort_order) VALUES (?,'Unit 2','text','Unit two',1,2)", [$course]);
    $pre = db_exec("INSERT INTO lesson_media (lesson_id,title,media_type,quiz_data,is_published,sort_order) VALUES (?,'Course pretest','quiz',?,1,1)", [$unit1, $preQuiz]);
    $postQuiz = json_encode(['questions' => $questions, 'kind' => 'post', 'pair' => $unit1 . ':' . $pre, 'max_attempts' => 1]);
    $post = db_exec("INSERT INTO lesson_media (lesson_id,title,media_type,quiz_data,is_published,sort_order) VALUES (?,'Unit 2 posttest','quiz',?,1,1)", [$unit2, $postQuiz]);
    db_exec("INSERT INTO grade_items (course_id,source_key,kind,lesson_id,source_media_id,title,max_score) VALUES (?,?,'quiz',?,?,'Course pretest',2)", [$course, 'quiz:' . $unit1 . ':' . $pre, $unit1, $pre]);

    // One student already took the pretest while it was inside Unit 1.
    $path = 'lesson.php?id=' . $unit1 . '&media_id=' . $pre;
    request_page($early, 'lesson.php?id=' . $unit1); // items inside a lesson open in order: main content first
    $html = request_page($early, $path);
    request_page($early, $path, ['csrf' => field($html, 'csrf'), 'action' => 'submit_quiz', 'submission_token' => field($html, 'submission_token'), 'answers' => ['0', '1']]);
    ensure((int)db_value('SELECT COUNT(*) FROM quiz_attempts WHERE lesson_id = ? AND source_media_id = ?', [$unit1, $pre]) === 1, 'Fixture attempt');

    $form = 'admin/lesson_form.php?id=' . $unit1;
    $html = request_page($teacher, $form);
    ensure(str_contains($html, 'value="extract"'), 'Extract button');
    request_page($otherTeacher, 'admin/lesson_media_action.php', ['csrf' => field(request_page($otherTeacher, 'profile.php'), 'csrf'), 'media_id' => $pre, 'action' => 'extract']);
    ensure(curl_getinfo($otherTeacher, CURLINFO_HTTP_CODE) === 403 && db_value('SELECT 1 FROM lesson_media WHERE id = ?', [$pre]), 'Other teacher extracted');
    $result = request_page($teacher, 'admin/lesson_media_action.php', ['csrf' => field($html, 'csrf'), 'media_id' => $pre, 'action' => 'extract']);
    ensure(str_contains($result, 'ออกเป็นบทเรียนแยกแล้ว'), 'Extract message');

    $order = array_map('intval', array_column(db_all('SELECT id FROM lessons WHERE course_id = ? ORDER BY sort_order, id', [$course]), 'id'));
    ensure(count($order) === 3 && $order[1] === $unit1 && $order[2] === $unit2, 'New lesson placed before Unit 1');
    $new = $order[0];
    $lesson = db_one('SELECT * FROM lessons WHERE id = ?', [$new]);
    ensure($lesson['title'] === 'Course pretest' && $lesson['media_type'] === 'quiz' && quiz_parse($lesson['quiz_data'])['kind'] === 'pre', 'Quiz copied');
    ensure(!db_value('SELECT 1 FROM lesson_media WHERE id = ?', [$pre]), 'Old media kept');
    ensure((int)db_value('SELECT COUNT(*) FROM quiz_attempts WHERE lesson_id = ? AND source_media_id = 0 AND user_id = ?', [$new, $earlyId]) === 1, 'Attempt moved');
    ensure(quiz_parse(db_value('SELECT quiz_data FROM lesson_media WHERE id = ?', [$post]))['pair'] === $new . ':0', 'Post-test pair remapped');
    ensure(db_value('SELECT source_key FROM grade_items WHERE course_id = ?', [$course]) === 'quiz:' . $new . ':0', 'Grade item remapped');
    ensure((bool)db_value('SELECT 1 FROM lesson_progress WHERE lesson_id = ? AND user_id = ?', [$new, $earlyId]), 'Earlier taker not marked done');

    // The student who already took it is not asked again and can open Unit 1.
    ensure(str_contains(request_page($early, 'lesson.php?id=' . $unit1), 'Unit one'), 'Earlier taker locked');
    // A student who has not taken it is held at the pretest and cannot skip it.
    ensure(str_contains(request_page($late, 'lesson.php?id=' . $unit1), 'ต้องเรียนตามลำดับ'), 'Unit 1 not locked');
    $html = request_page($late, 'lesson.php?id=' . $new);
    ensure(str_contains($html, 'ทำแบบทดสอบก่อนเรียนให้เสร็จก่อน') && !str_contains($html, 'value="complete"'), 'Skip button shown');
    request_page($late, 'lesson.php?id=' . $new, ['csrf' => field($html, 'csrf'), 'action' => 'complete']);
    ensure(!db_value('SELECT 1 FROM lesson_progress WHERE lesson_id = ? AND user_id = ?', [$new, $lateId]), 'Pretest skipped by posting complete');
    $html = request_page($late, 'lesson.php?id=' . $new);
    request_page($late, 'lesson.php?id=' . $new, ['csrf' => field($html, 'csrf'), 'action' => 'submit_quiz', 'submission_token' => field($html, 'submission_token'), 'answers' => ['0', '0']]);
    ensure((bool)db_value('SELECT 1 FROM lesson_progress WHERE lesson_id = ? AND user_id = ?', [$new, $lateId]), 'Submitting pretest did not complete lesson');
    ensure(str_contains(request_page($late, 'lesson.php?id=' . $unit1), 'Unit one'), 'Unit 1 still locked after pretest');

    // Course post-test button: a quiz lesson after the last unit, paired with the course pretest.
    ensure(str_contains(request_page($teacher, 'admin/lessons.php?course_id=' . $course), 'quiz=post'), 'Post-test button');
    $postForm = 'admin/lesson_form.php?course_id=' . $course . '&quiz=post';
    $html = request_page($teacher, $postForm);
    $preset = json_decode(field($html, 'quiz_data'), true);
    ensure($preset['kind'] === 'post' && $preset['pair'] === $new . ':0' && count($preset['questions']) === 2, 'Post-test preset');
    ensure(str_contains($html, 'value="แบบทดสอบหลังเรียน Pretest extract fixture"'), 'Post-test title');
    ensure((bool)preg_match('/id="mt_quiz" value="quiz"\s+checked/', $html), 'Post-test type not preselected');
    ensure(!str_contains($html, 'id="remove-primary-media"'), 'Remove-primary button on a new lesson');
    request_page($teacher, $postForm, ['csrf' => field($html, 'csrf'), 'title' => 'Course posttest', 'media_type' => 'quiz', 'quiz_data' => json_encode($preset), 'is_published' => '1']);
    $order = array_map('intval', array_column(db_all('SELECT id FROM lessons WHERE course_id = ? ORDER BY sort_order, id', [$course]), 'id'));
    $final = end($order);
    $finalLesson = db_one('SELECT * FROM lessons WHERE id = ?', [$final]);
    ensure(count($order) === 4 && $finalLesson['title'] === 'Course posttest' && $finalLesson['media_type'] === 'quiz' && quiz_parse($finalLesson['quiz_data'])['pair'] === $new . ':0', 'Post-test saved last');
    ensure(str_contains(request_page($late, 'lesson.php?id=' . $final), 'ต้องเรียนตามลำดับ'), 'Post-test open before units done');
    foreach ([$unit1, $unit2] as $unit) db_exec('INSERT IGNORE INTO lesson_progress (user_id, lesson_id) VALUES (?, ?)', [$lateId, $unit]);
    $html = request_page($late, 'lesson.php?id=' . $final);
    ensure(str_contains($html, 'ทำแบบทดสอบหลังเรียนให้เสร็จก่อน'), 'Post-test can be skipped');
    request_page($late, 'lesson.php?id=' . $final, ['csrf' => field($html, 'csrf'), 'action' => 'submit_quiz', 'submission_token' => field($html, 'submission_token'), 'answers' => ['0', '0']]);
    ensure((bool)db_value('SELECT 1 FROM lesson_progress WHERE lesson_id = ? AND user_id = ?', [$final, $lateId]), 'Post-test did not complete lesson');
    ensure(str_contains(request_page($teacher, 'admin/prepost.php?course_id=' . $course), 'Course posttest'), 'Pre/post report pair');

    // Course pretest button places the new lesson before every unit.
    $preForm = 'admin/lesson_form.php?course_id=' . $course . '&quiz=pre';
    $html = request_page($teacher, $preForm);
    request_page($teacher, $preForm, ['csrf' => field($html, 'csrf'), 'title' => 'Second pretest', 'media_type' => 'quiz', 'quiz_data' => json_encode(['questions' => $questions, 'kind' => 'pre', 'max_attempts' => 1]), 'is_published' => '1']);
    ensure(db_value('SELECT title FROM lessons WHERE course_id = ? ORDER BY sort_order, id LIMIT 1', [$course]) === 'Second pretest', 'Pretest button placed first');
    echo "Pretest extract HTTP tests passed: move out of unit, ordering, attempts, pairs, gradebook, progress, sequential lock, no skipping, permissions\n";
} finally {
    if ($course) db_exec('DELETE FROM courses WHERE id = ?', [$course]);
    foreach ($users as $id) db_exec('DELETE FROM users WHERE id = ?', [$id]);
    foreach ($clients as $client) curl_close($client);
    echo "Test fixtures removed\n";
}
