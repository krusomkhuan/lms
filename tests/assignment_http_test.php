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
    if ($data !== null) curl_setopt($client, CURLOPT_POSTFIELDS, (isset($data['submission_file']) ? $data : http_build_query($data)));
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
$users = []; $clients = []; $course = null; $uploadedPaths = [];
$localFile = tempnam(sys_get_temp_dir(), 'lms_assignment_');
file_put_contents($localFile, 'Assignment upload test');
try {
    require __DIR__ . '/../includes/assignments.php';
    ensure(assignment_points('25.50') === '25.50' && assignment_score('0', '25.50') === '0.00', 'Decimal/zero score');
    foreach (['-1', '10001', 'NaN', '2.333', []] as $bad) {
        try { assignment_points($bad); throw new LogicException('Invalid max score accepted'); } catch (RuntimeException $expected) {}
    }
    $password = bin2hex(random_bytes(20));
    foreach (['teacher', 'student', 'student', 'teacher'] as $role) {
        $username = 'assignment_http_' . bin2hex(random_bytes(6));
        $users[] = db_exec('INSERT INTO users (username,password,fullname,role,consent_version,consent_at) VALUES (?,?,?,?,?,NOW())', [$username, password_hash($password, PASSWORD_DEFAULT), 'Assignment HTTP fixture', $role, setting('privacy_version', '1')]);
        $client = curl_init(); curl_setopt($client, CURLOPT_COOKIEFILE, ''); $clients[] = $client;
        $html = request_page($client, 'login.php');
        request_page($client, 'login.php', ['csrf' => field($html, 'csrf'), 'username' => $username, 'password' => $password]);
    }
    [$teacher, $student, $outsider, $otherTeacher] = $clients;
    $course = db_exec("INSERT INTO courses (code,title,status,ai_student_enabled) VALUES ('WORK-TEST','Assignment HTTP fixture','published',0)");
    db_exec('INSERT INTO course_teachers VALUES (?,?)', [$course, $users[0]]);
    db_exec('INSERT INTO enrollments (course_id,user_id) VALUES (?,?)', [$course, $users[1]]);
    $authorPath = 'admin/lesson_form.php?course_id=' . $course;
    $html = request_page($teacher, $authorPath);
    ensure(str_contains($html, 'data-media-type="assignment"'), 'Assignment menu');
    request_page($teacher, $authorPath, ['csrf' => field($html, 'csrf'), 'title' => 'Assignment fixture', 'media_type' => 'assignment', 'assignment_points' => '25.50', 'content' => 'Write a report', 'is_published' => '1', 'extra_types' => ['assignment'], 'extra_assignment_points' => ['10'], 'extra_titles' => ['Extra assignment'], 'extra_contents' => ['Extra instructions']]);
    $lesson = (int)db_value('SELECT id FROM lessons WHERE course_id = ?', [$course]);
    $media = (int)db_value('SELECT id FROM lesson_media WHERE lesson_id = ?', [$lesson]);
    ensure($lesson && $media, 'Save primary and extra assignments');
    ensure(db_value('SELECT assignment_points FROM lessons WHERE id = ?', [$lesson]) === '25.50', 'Max score saved');
    if (in_array('--preview', $argv, true)) {
        $preview = request_page($teacher, 'admin/lesson_form.php?id=' . $lesson);
        $preview = preg_replace('/name="csrf" value="[^"]*"/', 'name="csrf" value="preview-only"', $preview);
        file_put_contents(__DIR__ . '/assignment-preview.html', $preview);
    }
    $path = 'lesson.php?id=' . $lesson;
    $html = request_page($student, $path);
    $payload = ['csrf' => field($html, 'csrf'), 'action' => 'submit_assignment', 'submission_token' => field($html, 'submission_token'), 'submission_text' => 'My report', 'score' => '999'];
    $result = request_page($student, $path, $payload);
    ensure(str_contains($result, 'ส่งงานเรียบร้อยแล้ว'), 'Text submission');
    request_page($student, $path, $payload);
    $first = db_one('SELECT * FROM assignment_submissions WHERE lesson_id = ?', [$lesson]);
    ensure($first && $first['score'] === null && (int)db_value('SELECT COUNT(*) FROM assignment_submissions WHERE lesson_id = ?', [$lesson]) === 1, 'Duplicate or fake score accepted');
    $reportPath = 'admin/assignments.php?lesson_id=' . $lesson;
    $html = request_page($teacher, $reportPath);
    $grade = ['csrf' => field($html, 'csrf'), 'submission_id' => $first['id'], 'grade_version' => '0', 'status' => 'graded', 'score' => '26', 'feedback' => 'Good'];
    request_page($teacher, $reportPath, $grade);
    ensure(db_value('SELECT score FROM assignment_submissions WHERE id = ?', [$first['id']]) === null, 'Over max grade accepted');
    $grade['score'] = '20.25';
    request_page($teacher, $reportPath, $grade);
    ensure(db_value('SELECT score FROM assignment_submissions WHERE id = ?', [$first['id']]) === '20.25', 'Grade persistence');
    $result = request_page($student, $path);
    ensure(str_contains($result, '20.25 / 25.50') && str_contains($result, 'Good'), 'Student grade/feedback visibility');
    $grade['score'] = '1'; request_page($teacher, $reportPath, $grade);
    ensure(db_value('SELECT score FROM assignment_submissions WHERE id = ?', [$first['id']]) === '20.25', 'Stale teacher update overwrote grade');
    // A file-only resubmission is separate from the previously graded report.
    $html = request_page($student, $path);
    $result = request_page($student, $path, ['csrf' => field($html, 'csrf'), 'action' => 'submit_assignment', 'submission_token' => field($html, 'submission_token'), 'submission_file' => new CURLFile($localFile, 'text/plain', 'report.txt')]);
    ensure(str_contains($result, 'ส่งงานเรียบร้อยแล้ว'), 'File upload');
    $fileSubmission = db_one('SELECT * FROM assignment_submissions WHERE lesson_id = ? ORDER BY id DESC LIMIT 1', [$lesson]);
    ensure($fileSubmission['file_path'] && $fileSubmission['score'] === null, 'New work should await grading');
    $uploadedPaths[] = $fileSubmission['file_path'];
    ensure(db_value('SELECT score FROM assignment_submissions WHERE id = ?', [$first['id']]) === '20.25', 'Old score lost after resubmission');
    foreach ([$student, $teacher] as $client) {
        ensure(request_page($client, 'assignment_file.php?id=' . $fileSubmission['id']) === 'Assignment upload test', 'Authorized file download');
    }
    foreach ([$outsider, $otherTeacher] as $client) {
        request_page($client, 'assignment_file.php?id=' . $fileSubmission['id']);
        ensure(curl_getinfo($client, CURLINFO_HTTP_CODE) === 403, 'Unauthorized download');
        request_page($client, $reportPath);
        ensure(curl_getinfo($client, CURLINFO_HTTP_CODE) === 403, 'Unauthorized teacher report');
    }
    request_page($student, $reportPath);
    ensure(curl_getinfo($student, CURLINFO_HTTP_CODE) === 403, 'Student can grade');
    request_page($outsider, 'uploads/' . $fileSubmission['file_path']);
    ensure(curl_getinfo($outsider, CURLINFO_HTTP_CODE) === 403, 'Private file publicly exposed');
    $html = request_page($teacher, $reportPath);
    request_page($teacher, $reportPath, ['csrf' => field($html, 'csrf'), 'submission_id' => $fileSubmission['id'], 'grade_version' => '0', 'status' => 'revision', 'feedback' => 'Please revise']);
    ensure(db_value('SELECT status FROM assignment_submissions WHERE id = ?', [$fileSubmission['id']]) === 'revision', 'Request revision');
    // Empty submission is refused; a text of zero is valid and preserved.
    $extraPath = $path . '&media_id=' . $media;
    $html = request_page($student, $extraPath);
    $payload = ['csrf' => field($html, 'csrf'), 'action' => 'submit_assignment', 'submission_token' => field($html, 'submission_token')];
    request_page($student, $extraPath, $payload);
    ensure((int)db_value('SELECT COUNT(*) FROM assignment_submissions WHERE lesson_id = ?', [$lesson]) === 2, 'Empty submission saved');
    $payload['submission_text'] = '0'; request_page($student, $extraPath, $payload);
    ensure(db_value('SELECT submission_text FROM assignment_submissions WHERE media_id = ?', [$media]) === '0', 'Zero text lost');
    db_exec('DELETE FROM lesson_media WHERE id = ?', [$media]);
    ensure((int)db_value('SELECT COUNT(*) FROM assignment_submissions WHERE lesson_id = ?', [$lesson]) === 3, 'Submissions lost on media deletion');
    $html = request_page($student, $path);
    db_exec('UPDATE lessons SET assignment_points = 30 WHERE id = ?', [$lesson]);
    request_page($student, $path, ['csrf' => field($html, 'csrf'), 'action' => 'submit_assignment', 'submission_token' => field($html, 'submission_token'), 'submission_text' => 'Stale']);
    ensure((int)db_value('SELECT COUNT(*) FROM assignment_submissions WHERE lesson_id = ?', [$lesson]) === 3, 'Stale assignment submitted');
    ensure(db_value('SELECT max_score FROM assignment_submissions WHERE id = ?', [$first['id']]) === '25.50', 'Max score snapshot changed');
    // A zero grade is a real score; missing CSRF cannot change it.
    $html = request_page($teacher, $reportPath);
    request_page($teacher, $reportPath, ['csrf' => field($html, 'csrf'), 'submission_id' => $first['id'], 'grade_version' => '1', 'status' => 'graded', 'score' => '0', 'feedback' => 'Zero score']);
    ensure(db_value('SELECT score FROM assignment_submissions WHERE id = ?', [$first['id']]) === '0.00', 'Zero score not saved');
    request_page($teacher, $reportPath, ['submission_id' => $first['id'], 'grade_version' => '2', 'status' => 'graded', 'score' => '25']);
    ensure(curl_getinfo($teacher, CURLINFO_HTTP_CODE) === 400 && db_value('SELECT score FROM assignment_submissions WHERE id = ?', [$first['id']]) === '0.00', 'CSRF grade accepted');
    db_exec('UPDATE lessons SET is_published = 0 WHERE id = ?', [$lesson]);
    $hidden = request_page($student, $path);
    ensure(!str_contains($hidden, 'value="submit_assignment"'), 'Hidden assignment access');
    $html = request_page($outsider, $path);
    ensure(!str_contains($html, 'value="submit_assignment"'), 'Unenrolled student assignment access');
    echo "Assignment HTTP tests passed: authoring, text/files, scores, feedback, revisions, private downloads, permissions, snapshots and duplicate/stale protection\n";
} finally {
    if ($course) {
        foreach (db_all('SELECT s.file_path FROM assignment_submissions s JOIN lessons l ON l.id=s.lesson_id WHERE l.course_id = ? AND s.file_path IS NOT NULL', [$course]) as $r) $uploadedPaths[] = $r['file_path'];
        db_exec('DELETE FROM courses WHERE id = ?', [$course]);
    }
    foreach ($users as $id) db_exec('DELETE FROM users WHERE id = ?', [$id]);
    foreach (array_unique($uploadedPaths) as $path) delete_upload($path);
    foreach ($clients as $client) curl_close($client);
    unlink($localFile);
    echo "Test fixtures and files removed\n";
}
