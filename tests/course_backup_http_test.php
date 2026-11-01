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
    curl_setopt_array($client, [CURLOPT_URL => $base . $path, CURLOPT_POST => $data !== null, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
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
$users = []; $clients = []; $courses = []; $files = []; $zipPath = null;
try {
    $password = bin2hex(random_bytes(20));
    foreach (['teacher', 'teacher', 'student'] as $role) {
        $username = 'cbak_http_' . bin2hex(random_bytes(6));
        $users[] = db_exec('INSERT INTO users (username,password,fullname,role,student_code,consent_version,consent_at) VALUES (?,?,?,?,?,?,NOW())', [$username, password_hash($password, PASSWORD_DEFAULT), 'Backup fixture', $role, $role === 'student' ? 'S-BAK-1' : null, setting('privacy_version', '1')]);
        $client = curl_init(); curl_setopt($client, CURLOPT_COOKIEFILE, ''); $clients[] = $client;
        $html = request_page($client, 'login.php');
        request_page($client, 'login.php', ['csrf' => field($html, 'csrf'), 'username' => $username, 'password' => $password]);
    }
    [$teacher, $otherTeacher, $student] = $clients;

    // รายวิชาต้นฉบับ: ภาพปก ไฟล์บทเรียน รูปในเนื้อหา คลังข้อสอบ คู่ก่อน–หลังเรียน น้ำหนักคะแนน และคะแนนนักเรียน
    foreach (['covers/cbak_cover.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>', 'lessons/cbak_file.pdf' => '%PDF-1.4 fixture', 'editor/cbak/pic.png' => 'PNGDATA'] as $path => $content) {
        if (!is_dir(dirname(UPLOAD_PATH . '/' . $path))) mkdir(dirname(UPLOAD_PATH . '/' . $path), 0775, true);
        file_put_contents(UPLOAD_PATH . '/' . $path, $content); $files[] = UPLOAD_PATH . '/' . $path;
    }
    $course = $courses[] = db_exec("INSERT INTO courses (code,title,status,cover,ai_student_enabled) VALUES ('BAK-1','Backup fixture','published','covers/cbak_cover.svg',0)");
    db_exec('INSERT INTO course_teachers VALUES (?,?)', [$course, $users[0]]);
    db_exec('INSERT INTO enrollments (course_id,user_id) VALUES (?,?)', [$course, $users[2]]);
    $bankQuiz = fn($kind, $pair) => json_encode(['questions' => [], 'source' => 'bank', 'categories' => ['U1'], 'count' => 1, 'kind' => $kind, 'pair' => $pair, 'max_attempts' => 0, 'shuffle' => false, 'opens_at' => '', 'closes_at' => '']);
    $pre = db_exec("INSERT INTO lessons (course_id,title,media_type,content,file_path,is_published,sort_order,quiz_data) VALUES (?,?,?,?,?,?,?,?)",
        [$course, 'Pre', 'quiz', '<p><img src="http://old-school.example/lms/uploads/editor/cbak/pic.png"></p>', 'lessons/cbak_file.pdf', 1, 1, $bankQuiz('pre', '')]);
    $post = db_exec("INSERT INTO lessons (course_id,title,media_type,is_published,sort_order,quiz_data) VALUES (?,?,?,?,?,?)", [$course, 'Post', 'quiz', 1, 2, $bankQuiz('post', $pre . ':0')]);
    db_exec("INSERT INTO question_bank (course_id,category,question_text,options_json,correct) VALUES (?,'U1','Q1',?,0),(?,'U1','Q2',?,1)", [$course, '["a","b"]', $course, '["a","b"]']);
    db_exec("INSERT INTO quiz_attempts (lesson_id,source_media_id,user_id,quiz_title,score,total,answers,quiz_snapshot,submission_token) VALUES (?,0,?,'Pre',1,1,'[]','[]',?)", [$pre, $users[2], bin2hex(random_bytes(32))]);
    db_exec("INSERT INTO grade_items (course_id,source_key,kind,lesson_id,source_media_id,title,max_score,weight) VALUES (?,?,'quiz',?,0,'Post',1,60),(?,?,'manual',NULL,0,'Attendance',10,40)", [$course, 'quiz:' . $post . ':0', $post, $course, 'manual:' . bin2hex(random_bytes(16))]);

    // ส่งออก
    $html = request_page($teacher, 'admin/course_backup.php?course_id=' . $course);
    $zipData = request_page($teacher, 'admin/course_backup.php?course_id=' . $course, ['csrf' => field($html, 'csrf'), 'action' => 'export', 'with_students' => '1']);
    ensure(str_starts_with($zipData, "PK"), "Export returns zip: " . (preg_match("/alert-danger[^>]*>([^<]*)/u", $zipData, $em) ? $em[1] : "no error shown"));
    $zipPath = sys_get_temp_dir() . '/cbak_' . bin2hex(random_bytes(6)) . '.zip';
    file_put_contents($zipPath, $zipData);
    $zip = new PharData($zipPath);
    $get = fn($name) => isset($zip[$name]) ? $zip[$name]->getContent() : false;
    $data = json_decode($get('data.json'), true);
    ensure(count($data['lessons']) === 2 && count($data['question_bank']) === 2 && count($data['quiz_attempts']) === 1 && $data['students'][0]['student_code'] === 'S-BAK-1', 'Export data');
    foreach (['files/covers/cbak_cover.svg', 'files/lessons/cbak_file.pdf', 'files/editor/cbak/pic.png', 'scores.csv', 'manifest.json'] as $entry) ensure($get($entry) !== false, 'Zip entry ' . $entry);
    ensure(str_contains($get('scores.csv'), 'S-BAK-1,"Backup fixture",,0,100'), 'Scores CSV');
    unset($zip, $get);
    request_page($otherTeacher, 'admin/course_backup.php?course_id=' . $course);
    ensure(curl_getinfo($otherTeacher, CURLINFO_HTTP_CODE) === 403, 'Other teacher export access');
    request_page($student, 'admin/course_backup.php');
    ensure(curl_getinfo($student, CURLINFO_HTTP_CODE) === 403, 'Student access');

    // นำเข้าเป็นรายวิชาใหม่ โดยครูอีกคน
    $html = request_page($otherTeacher, 'admin/course_backup.php');
    request_page($otherTeacher, 'admin/course_backup.php', ['csrf' => field($html, 'csrf'), 'action' => 'import', 'code' => 'BAK-2', 'title' => 'Imported fixture', 'backup' => new CURLFile($zipPath, 'application/zip', 'course.zip')], true);
    $new = (int)db_value("SELECT id FROM courses WHERE code = 'BAK-2' AND title = 'Imported fixture'");
    ensure($new > 0, 'Import creates course');
    $courses[] = $new;
    $c = db_one('SELECT * FROM courses WHERE id = ?', [$new]);
    ensure($c['status'] === 'draft' && (int)db_value('SELECT teacher_id FROM course_teachers WHERE course_id = ?', [$new]) === $users[1], 'Imported course is draft and owned by importer');
    $lessons = db_all('SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order', [$new]);
    ensure(count($lessons) === 2 && (int)db_value('SELECT COUNT(*) FROM question_bank WHERE course_id = ?', [$new]) === 2, 'Imported lessons and bank');
    ensure(json_decode($lessons[1]['quiz_data'], true)['pair'] === $lessons[0]['id'] . ':0', 'Pre/post pair remapped');
    foreach ([$c['cover'], $lessons[0]['file_path']] as $path) { ensure($path && is_file(UPLOAD_PATH . '/' . $path), 'Imported file ' . $path); $files[] = UPLOAD_PATH . '/' . $path; }
    preg_match('~/uploads/(editor/[^"]+)~', $lessons[0]['content'], $m);
    ensure(isset($m[1]) && $m[1] !== 'editor/cbak/pic.png' && is_file(UPLOAD_PATH . '/' . $m[1]), 'Content image copied and rewritten');
    $files[] = UPLOAD_PATH . '/' . $m[1];
    ensure(!db_value('SELECT 1 FROM quiz_attempts a JOIN lessons l ON l.id = a.lesson_id WHERE l.course_id = ?', [$new]) && !db_value('SELECT 1 FROM enrollments WHERE course_id = ?', [$new]), 'Student data not imported');
    $items = db_all('SELECT * FROM grade_items WHERE course_id = ? ORDER BY id', [$new]);
    ensure(count($items) === 2 && $items[0]['source_key'] === 'quiz:' . $lessons[1]['id'] . ':0' && (float)$items[0]['weight'] === 60.0 && $items[1]['kind'] === 'manual', 'Grade weights imported');
    $bad = sys_get_temp_dir() . '/bad_' . bin2hex(random_bytes(6)) . '.zip';
    $z = new PharData($bad, 0, null, Phar::ZIP); $z->addFromString('x.txt', 'x'); unset($z);
    $result = request_page($otherTeacher, 'admin/course_backup.php', ['csrf' => field($html, 'csrf'), 'action' => 'import', 'code' => 'BAK-3', 'title' => 'Bad', 'backup' => new CURLFile($bad, 'application/zip', 'bad.zip')], true);
    unlink($bad);
    ensure(str_contains($result, 'ไม่ใช่ไฟล์สำรองรายวิชา') && !db_value("SELECT 1 FROM courses WHERE code = 'BAK-3'"), 'Reject non-backup zip');
    echo "Course backup HTTP tests passed: export (data, files, scores.csv), access control, import as new draft course, file copy/rewrite, pair and grade remap, no student data\n";
} finally {
    foreach ($courses as $id) db_exec('DELETE FROM courses WHERE id = ?', [$id]);
    foreach ($users as $id) db_exec('DELETE FROM users WHERE id = ?', [$id]);
    foreach ($clients as $client) curl_close($client);
    foreach ($files as $file) if (is_file($file)) unlink($file);
    @rmdir(UPLOAD_PATH . '/editor/cbak');
    if ($zipPath && is_file($zipPath)) unlink($zipPath);
    echo "Test fixtures and files removed\n";
}
