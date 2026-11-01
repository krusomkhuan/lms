<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/schedule.php';
require __DIR__ . '/../includes/quiz.php';
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

// นำเข้า: ข้อความ (เลขข้อ + ก. ข. + * หรือ "เฉลย ข" + "หมวด:") และตาราง CSV/คัดลอกจาก Excel (รวม CSV ภาษาไทยแบบ ANSI)
$parsed = question_import("หมวด: U9
1. โจทย์หนึ่ง
ต่อบรรทัด
ก. ผิด
ข. ถูก *

ข้อ 2) โจทย์สอง
a) right
b) wrong
เฉลย a");
ensure(count($parsed) === 2 && $parsed[0]['correct'] === 1 && $parsed[0]['text'] === "โจทย์หนึ่ง
ต่อบรรทัด" && $parsed[0]['category'] === 'U9' && $parsed[1]['options'] === ['right', 'wrong'] && $parsed[1]['correct'] === 0, 'Import text format');
$csv = "ï»¿หมวด,โจทย์,เฉลย,ตัวเลือก ก,ตัวเลือก ข,ตัวเลือก ค
U1,\"Q, with comma\",ค,a,b,c
,Q2,1,x,y,
";
$parsed = question_import($csv, true);
ensure(count($parsed) === 2 && $parsed[0]['text'] === 'Q, with comma' && $parsed[0]['correct'] === 2 && $parsed[1]['category'] === '' && $parsed[1]['options'] === ['x', 'y'], 'Import CSV');
$parsed = question_import("หมวด	โจทย์	เฉลย	ก	ข
U1	Tab Q	ข	p	q");
ensure(count($parsed) === 1 && $parsed[0]['correct'] === 1, 'Import pasted Excel table');
$parsed = question_import(iconv('UTF-8', 'WINDOWS-874', "หมวด,โจทย์,เฉลย,ก,ข
หน่วย,คำถาม,ก,ใช่,ไม่"), true);
ensure($parsed[0]['text'] === 'คำถาม' && $parsed[0]['category'] === 'หน่วย', 'Import ANSI Thai CSV');
foreach (["1. no answer
ก. a
ข. b", "stray line
1. q
ก. a*
ข. b", "หมวด,โจทย์,เฉลย,ก,ข
U,Q,ค,a,b"] as $bad) {
    try { question_import($bad); throw new LogicException('accepted: ' . $bad); } catch (RuntimeException $ex) {}
}

$users = []; $clients = []; $course = null;
try {
    $password = bin2hex(random_bytes(20));
    foreach (['teacher', 'student', 'student'] as $role) {
        $username = 'bank_http_' . bin2hex(random_bytes(6));
        $users[] = db_exec('INSERT INTO users (username,password,fullname,role,consent_version,consent_at) VALUES (?,?,?,?,?,NOW())', [$username, password_hash($password, PASSWORD_DEFAULT), 'Bank HTTP fixture', $role, setting('privacy_version', '1')]);
        $client = curl_init(); curl_setopt($client, CURLOPT_COOKIEFILE, ''); $clients[] = $client;
        $html = request_page($client, 'login.php');
        request_page($client, 'login.php', ['csrf' => field($html, 'csrf'), 'username' => $username, 'password' => $password]);
    }
    [$teacher, $student, $outsider] = $clients;
    $course = db_exec("INSERT INTO courses (code,title,status,ai_student_enabled) VALUES ('BANK-TEST','Bank HTTP fixture','published',0)");
    db_exec('UPDATE courses SET sequential_lessons = 0 WHERE id = ?', [$course]);
    db_exec('INSERT INTO course_teachers VALUES (?,?)', [$course, $users[0]]);
    db_exec('INSERT INTO enrollments (course_id,user_id) VALUES (?,?)', [$course, $users[1]]);

    // คลังข้อสอบ: นำเข้า 4 ข้อ (คำตอบถูกคือตัวเลือกแรกทุกข้อ) + เพิ่มทีละข้อ 1 ข้อ ในหมวด U1, อีก 1 ข้อในหมวด U2
    $bank = 'admin/question_bank.php?course_id=' . $course;
    $html = request_page($teacher, $bank);
    $text = '';
    for ($i = 1; $i <= 4; $i++) $text .= "$i. Bank question $i\nก. right $i *\nข. wrong $i\nค. other $i\n\n";
    request_page($teacher, $bank, ['csrf' => field($html, 'csrf'), 'action' => 'import', 'category' => 'U1', 'import_text' => $text]);
    request_page($teacher, $bank, ['csrf' => field($html, 'csrf'), 'action' => 'save', 'category' => 'U1', 'question_text' => 'Bank question 5', 'options' => ['right 5', 'wrong 5'], 'correct' => '0']);
    request_page($teacher, $bank, ['csrf' => field($html, 'csrf'), 'action' => 'save', 'category' => 'U2', 'question_text' => 'Other unit', 'options' => ['right', 'wrong'], 'correct' => '0']);
    ensure(question_bank_count($course, ['U1']) === 5 && question_bank_count($course) === 6, 'Bank import/save');
    // อัปโหลด CSV ลงคลัง (หมวดจากคอลัมน์ A) · endpoint ของตัวสร้างแบบทดสอบ · แม่แบบ
    $tmp = tempnam(sys_get_temp_dir(), 'bank') . '.csv';
    file_put_contents($tmp, "หมวด,โจทย์,เฉลย,ก,ข
U3,CSV question,ข,no,yes
");
    curl_setopt_array($teacher, [CURLOPT_URL => $base . $bank, CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POSTFIELDS => ['csrf' => field($html, 'csrf'), 'action' => 'import', 'category' => '', 'import_text' => '', 'import_file' => new CURLFile($tmp, 'text/csv', 'bank.csv')]]);
    ensure(str_contains((string)curl_exec($teacher), 'นำเข้าข้อสอบ 1 ข้อ') && question_bank_count($course, ['U3']) === 1, 'Bank CSV upload');
    unlink($tmp);
    db_exec("DELETE FROM question_bank WHERE course_id = ? AND category = 'U3'", [$course]);
    $json = json_decode(request_page($teacher, 'admin/quiz_import.php', ['csrf' => field($html, 'csrf'), 'text' => "1. Imported
ก. a
ข. b
เฉลย ข"]), true);
    ensure(($json['questions'][0] ?? null) === ['text' => 'Imported', 'options' => ['a', 'b'], 'correct' => 1], 'Quiz import endpoint');
    $json = json_decode(request_page($teacher, 'admin/quiz_import.php', ['csrf' => field($html, 'csrf'), 'text' => "1. Broken
ก. a
ข. b"]), true);
    ensure(str_contains($json['error'] ?? '', 'ยังไม่ได้ระบุคำตอบ'), 'Quiz import error');
    ensure(count(question_import(request_page($teacher, 'admin/quiz_import.php?template=csv'), true)) === 3 && count(question_import(request_page($teacher, 'admin/quiz_import.php?template=txt'))) === 3, 'Templates import cleanly');
    ensure(str_contains(request_page($teacher, 'admin/question_bank.php'), 'BANK-TEST'), 'Bank menu course list');
    request_page($student, 'admin/quiz_import.php');
    ensure(curl_getinfo($student, CURLINFO_HTTP_CODE) === 403, 'Student import access');
    request_page($student, $bank);
    ensure(curl_getinfo($student, CURLINFO_HTTP_CODE) === 403, 'Student bank access');

    // แบบทดสอบก่อนเรียน: สุ่ม 3 ข้อจาก U1 ทำได้ 1 ครั้ง · ขอสุ่มเกินจำนวนในคลังต้องถูกปฏิเสธ
    $form = 'admin/lesson_form.php?course_id=' . $course;
    $html = request_page($teacher, $form);
    $quiz = fn(array $extra) => json_encode(array_merge(['questions' => [], 'source' => 'bank', 'categories' => ['U1'], 'count' => 3, 'kind' => '', 'pair' => '', 'max_attempts' => 0, 'shuffle' => false], $extra));
    $result = request_page($teacher, $form, ['csrf' => field($html, 'csrf'), 'title' => 'Pre fixture', 'media_type' => 'quiz', 'quiz_data' => $quiz(['count' => 9, 'kind' => 'pre']), 'is_published' => '1']);
    ensure(str_contains($result, 'มี 5 ข้อ') && !db_value('SELECT 1 FROM lessons WHERE course_id = ?', [$course]), 'Bank size check');
    request_page($teacher, $form, ['csrf' => field($html, 'csrf'), 'title' => 'Pre fixture', 'media_type' => 'quiz', 'quiz_data' => $quiz(['kind' => 'pre', 'max_attempts' => 1]), 'is_published' => '1']);
    $pre = (int)db_value('SELECT id FROM lessons WHERE course_id = ?', [$course]);
    ensure($pre > 0 && quiz_total((string)db_value('SELECT quiz_data FROM lessons WHERE id = ?', [$pre])) === 3, 'Save bank pre-test');
    $html = request_page($teacher, $form);
    ensure(str_contains($html, 'value="' . $pre . ':0">Pre fixture'), 'Pre-test offered as pair');
    // หน้าแก้ไขบทเรียนก่อนเรียน: กล่องก่อน–หลังเรียน, ไม่เสนอตัวเองเป็นคู่, บันทึกจับคู่ตัวเองไม่ได้, ปุ่มสร้างหลังเรียนตั้งค่าให้
    $edit = request_page($teacher, 'admin/lesson_form.php?id=' . $pre);
    foreach (['summary' => 'สุ่ม 3 ข้อจากคลัง (U1) · ทำได้ 1 ครั้ง', 'button' => 'quiz=post'] as $what => $needle) ensure(str_contains($edit, $needle), 'Lesson pre/post box: ' . $what); ensure(!str_contains(explode('id="box-url"', explode('id="box-quiz"', $edit)[1])[0], 'value="' . $pre . ':0">'), 'No self pair');
    $result = request_page($teacher, 'admin/lesson_form.php?id=' . $pre, ['csrf' => field($edit, 'csrf'), 'title' => 'Pre fixture', 'media_type' => 'quiz', 'quiz_data' => $quiz(['kind' => 'post', 'pair' => $pre . ':0', 'max_attempts' => 1]), 'is_published' => '1']);
    ensure(str_contains($result, 'จับคู่กับตัวเองไม่ได้') && quiz_parse((string)db_value('SELECT quiz_data FROM lessons WHERE id = ?', [$pre]))['kind'] === 'pre', 'Self pair rejected');
    $preset = request_page($teacher, 'admin/lesson_form.php?lesson_id=' . $pre . '&quiz=post');
    $presetConfig = json_decode(field($preset, 'quiz_data'), true);
    ensure($presetConfig['kind'] === 'post' && $presetConfig['pair'] === $pre . ':0' && $presetConfig['source'] === 'bank' && $presetConfig['max_attempts'] === 1 && $presetConfig['count'] === 3 && $presetConfig['categories'] === ['U1'] && str_contains($preset, 'value="แบบทดสอบหลังเรียน Pre fixture"'), 'Post-test preset');
    request_page($teacher, $form, ['csrf' => field($html, 'csrf'), 'title' => 'Post fixture', 'media_type' => 'quiz', 'quiz_data' => $quiz(['kind' => 'post', 'pair' => $pre . ':0']), 'is_published' => '1']);
    $post = (int)db_value('SELECT id FROM lessons WHERE course_id = ? AND id <> ?', [$course, $pre]);
    ensure($post > 0, 'Save bank post-test');

    // นักเรียน: ได้ 3 ข้อจาก U1 ไม่เห็นเฉลย · ตอบไม่ครบแล้วได้ชุดเดิม · ก่อนเรียนตอบผิดหมด ทำซ้ำไม่ได้
    $path = 'lesson.php?id=' . $pre;
    $html = request_page($student, $path);
    preg_match_all('/<legend class="fs-6">\d+\. (Bank question \d)/', $html, $m);
    ensure(count($m[1]) === 3 && count(array_unique($m[1])) === 3, 'Draw 3 distinct U1 questions');
    ensure(!str_contains($html, 'Other unit') && !str_contains($html, 'correct') && str_contains($html, 'ก. right'), 'Category filter / answer key hidden / letters');
    $token = field($html, 'submission_token');
    $retry = request_page($student, $path, ['csrf' => field($html, 'csrf'), 'action' => 'submit_quiz', 'submission_token' => $token, 'answers' => ['1', '1']]);
    preg_match_all('/<legend class="fs-6">\d+\. (Bank question \d)/', $retry, $m2);
    ensure(str_contains($retry, 'กรุณาตอบให้ครบทุกข้อ') && $m2[1] === $m[1] && field($retry, 'submission_token') === $token, 'Same draw after failed submit');
    $result = request_page($student, $path, ['csrf' => field($html, 'csrf'), 'action' => 'submit_quiz', 'submission_token' => $token, 'answers' => ['1', '1', '1']]);
    ensure(str_contains($result, 'บันทึกคะแนนแล้ว: 0 / 3') && str_contains($result, 'ส่งคำตอบแล้ว · คะแนนของคุณ') && str_contains($result, '0 <span class="fs-4 text-muted">/ 3</span>'), 'Pre-test score card');
    ensure(!str_contains($result, 'name="answers[0]"') && !str_contains($result, 'right 1') && !str_contains($result, 'correct'), 'Score card shows no questions or answer key');
    request_page($student, $path, ['csrf' => field($html, 'csrf'), 'action' => 'submit_quiz', 'submission_token' => $token, 'answers' => ['0', '0', '0']]);
    ensure((int)db_value('SELECT COUNT(*) FROM quiz_attempts WHERE lesson_id = ?', [$pre]) === 1, 'Attempt limit enforced');
    $snapshot = json_decode((string)db_value('SELECT quiz_snapshot FROM quiz_attempts WHERE lesson_id = ?', [$pre]), true);
    ensure(array_column($snapshot, 'text') === $m[1], 'Snapshot stores drawn questions');

    // เวลาเปิด–ปิดสอบ: ยังไม่เปิด / ปิดแล้ว นักเรียนเห็นแต่ส่งไม่ได้ · ปิดก่อนเปิดต้องถูกปฏิเสธ
    $html = request_page($teacher, $form);
    $result = request_page($teacher, $form, ['csrf' => field($html, 'csrf'), 'title' => 'Bad window', 'media_type' => 'quiz', 'quiz_data' => $quiz(['opens_at' => '2030-01-02T10:00', 'closes_at' => '2030-01-01T10:00']), 'is_published' => '1']);
    ensure(str_contains($result, 'เวลาสิ้นสุดต้องอยู่หลังเวลาเริ่มต้น'), 'Window order check');
    $config = json_decode((string)db_value('SELECT quiz_data FROM lessons WHERE id = ?', [$post]), true);
    foreach ([['opens_at' => date('Y-m-d\TH:i', time() + 86400), 'closes_at' => '', 'expect' => 'ยังไม่เปิด'], ['opens_at' => '', 'closes_at' => date('Y-m-d\TH:i', time() - 60), 'expect' => 'ปิดแล้ว']] as $window) {
        db_exec('UPDATE lessons SET quiz_data = ? WHERE id = ?', [quiz_validate(json_encode(array_merge($config, $window))), $post]);
        $html = request_page($student, 'lesson.php?id=' . $post);
        ensure(str_contains($html, 'แบบทดสอบ' . $window['expect']) && !str_contains($html, 'name="submission_token"'), 'Quiz window view: ' . $window['expect']);
    }
    $html = request_page($teacher, 'lesson.php?id=' . $post);
    ensure(str_contains($html, 'name="submission_token"') && str_contains($html, 'ปิดสอบ'), 'Teacher preview outside window');
    db_exec('UPDATE lessons SET quiz_data = ? WHERE id = ?', [quiz_validate(json_encode(array_merge($config, ['closes_at' => date('Y-m-d\TH:i', time() + 3600)]))), $post]);
    $html = request_page($student, 'lesson.php?id=' . $post);
    db_exec('UPDATE lessons SET quiz_data = ? WHERE id = ?', [quiz_validate(json_encode(array_merge($config, ['closes_at' => date('Y-m-d\TH:i', time() - 60)]))), $post]);
    $count = (int)db_value('SELECT COUNT(*) FROM quiz_attempts WHERE lesson_id = ?', [$post]);
    request_page($student, 'lesson.php?id=' . $post, ['csrf' => field($html, 'csrf'), 'action' => 'submit_quiz', 'submission_token' => field($html, 'submission_token'), 'answers' => ['0', '0', '0']]);
    ensure((int)db_value('SELECT COUNT(*) FROM quiz_attempts WHERE lesson_id = ?', [$post]) === $count, 'Closed quiz rejects submit');
    db_exec('UPDATE lessons SET quiz_data = ? WHERE id = ?', [json_encode($config), $post]);


    // หลังเรียน: สอบได้ 1 ครั้งแม้บันทึกค่า max_attempts = 0 ไว้ · การ์ดคะแนนเทียบกับก่อนเรียน
    ensure(quiz_parse((string)db_value('SELECT quiz_data FROM lessons WHERE id = ?', [$post]))['max_attempts'] === 1, 'Pre/post forced to one attempt');
    $path = 'lesson.php?id=' . $post;
    $html = request_page($student, $path);
    ensure(str_contains($html, 'สอบได้ <b>1 ครั้ง</b>') && str_contains($html, 'ส่งแล้วสอบซ้ำไม่ได้'), 'One-attempt warning');
    $result = request_page($student, $path, ['csrf' => field($html, 'csrf'), 'action' => 'submit_quiz', 'submission_token' => field($html, 'submission_token'), 'answers' => ['0', '0', '0']]);
    ensure(str_contains($result, 'บันทึกคะแนนแล้ว: 3 / 3') && str_contains($result, 'ก่อนเรียน 0/3 (0%) → หลังเรียน 100%') && str_contains($result, '+100%'), 'Post-test score card with comparison');
    $html = request_page($student, $path);
    ensure(!str_contains($html, 'name="submission_token"'), 'No second post-test attempt');

    // รายงานครู + สิทธิ์
    $report = request_page($teacher, 'admin/prepost.php?course_id=' . $course);
    ensure(str_contains($report, 'Pre fixture') && str_contains($report, '+100%'), 'Pre/post report');
    $csv = request_page($teacher, 'admin/prepost.php?course_id=' . $course . '&pair=0&export=csv');
    ensure(str_contains($csv, ',0,3,0,3,3,100,100'), 'Pre/post CSV');
    // ความก้าวหน้าของนักเรียน + แดชบอร์ดครู
    $progress = request_page($student, 'my-progress.php?course_id=' . $course);
    ensure(str_contains($progress, 'ความก้าวหน้าของฉัน') && str_contains($progress, '+100%') && str_contains($progress, 'แบบทดสอบก่อนเรียน') && str_contains($progress, '2 / 2'), 'Student progress page');
    $dashboard = request_page($teacher, 'admin/score_dashboard.php?course_id=' . $course);
    ensure(str_contains($dashboard, 'id="itemChart"') && str_contains($dashboard, 'id="prepostChart"') && str_contains($dashboard, '"pre":0') && str_contains($dashboard, '"post":100'), 'Teacher dashboard');
    ensure(str_contains($dashboard, '>50%<') && substr_count($dashboard, 'class="cell"') === 2, 'Dashboard heat table');
    request_page($student, 'admin/score_dashboard.php?course_id=' . $course);
    ensure(curl_getinfo($student, CURLINFO_HTTP_CODE) === 403, 'Student dashboard access');
    request_page($student, 'admin/prepost.php?course_id=' . $course);
    ensure(curl_getinfo($student, CURLINFO_HTTP_CODE) === 403, 'Student report access');
    $html = request_page($outsider, 'lesson.php?id=' . $pre);
    ensure(!str_contains($html, 'name="submission_token"'), 'Unenrolled quiz access');
    echo "Question bank HTTP tests passed: parse/import, bank size check, random draw by category, stable draw, attempt limit, pre/post report and access control\n";
} finally {
    if ($course) db_exec('DELETE FROM courses WHERE id = ?', [$course]);
    foreach ($users as $id) db_exec('DELETE FROM users WHERE id = ?', [$id]);
    foreach ($clients as $client) curl_close($client);
    echo "Test fixtures removed\n";
}
