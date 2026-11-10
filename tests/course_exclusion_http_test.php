<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/gradebook.php';
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
$users = []; $clients = []; $courses = []; $group = 0;
try {
    ensure(course_exclusions_ready(), 'Run database/migrations/1.16.2.sql first');
    $password = bin2hex(random_bytes(20));
    foreach (['teacher', 'student', 'student', 'teacher'] as $role) {
        $username = 'excl_http_' . bin2hex(random_bytes(6));
        $users[] = db_exec('INSERT INTO users (username,password,fullname,role,consent_version,consent_at) VALUES (?,?,?,?,?,NOW())', [$username, password_hash($password, PASSWORD_DEFAULT), 'Exclusion ' . count($users), $role, setting('privacy_version', '1')]);
        $client = curl_init(); curl_setopt($client, CURLOPT_COOKIEFILE, ''); $clients[] = $client;
        $html = request_page($client, 'login.php'); request_page($client, 'login.php', ['csrf' => field($html, 'csrf'), 'username' => $username, 'password' => $password]);
    }
    [$teacher, $student, $soloStudent, $otherTeacher] = $clients;
    [$teacherId, $studentId, $soloId] = $users;
    foreach (['EXCL-A' => 'Exclusion A fixture', 'EXCL-B' => 'Exclusion B fixture'] as $code => $title) {
        $courses[] = db_exec("INSERT INTO courses (code,title,status,enroll_type,ai_student_enabled) VALUES (?,?,'published','open',0)", [$code, $title]);
        db_exec('INSERT INTO course_teachers VALUES (?,?)', [end($courses), $teacherId]);
    }
    [$courseA, $courseB] = $courses;
    $lessonA = db_exec("INSERT INTO lessons (course_id,title,media_type,content,is_published,sort_order) VALUES (?,'Exclusion lesson','text','Hello',1,1)", [$courseA]);
    $group = db_exec("INSERT INTO student_groups (name) VALUES ('Exclusion HTTP group')");
    db_exec('INSERT INTO course_groups VALUES (?,?),(?,?)', [$courseA, $group, $courseB, $group]);
    db_exec('INSERT INTO group_members VALUES (?,?)', [$group, $studentId]);
    // The group student is also enrolled individually: removal must take away both routes.
    db_exec('INSERT INTO enrollments (course_id,user_id) VALUES (?,?),(?,?)', [$courseA, $studentId, $courseA, $soloId]);
    $page = 'admin/course_students.php?course_id=' . $courseA;
    $rosterIds = fn($course) => array_map('intval', array_column(grade_roster($course), 'id'));
    $excludedRow = fn() => (bool)db_value('SELECT 1 FROM course_exclusions WHERE course_id = ? AND user_id = ?', [$courseA, $studentId]);

    $html = request_page($teacher, $page);
    ensure(substr_count($html, 'value="remove"') === 2, 'Remove button for group and individual students');
    $remove = fn($client, $uid) => request_page($client, $page, ['csrf' => field(request_page($client, $page), 'csrf'), 'action' => 'remove', 'user_id' => $uid]);

    // A teacher of another course cannot remove students here.
    $other = request_page($otherTeacher, $page);
    ensure(curl_getinfo($otherTeacher, CURLINFO_HTTP_CODE) === 403, 'Other teacher can open roster');
    $html = request_page($teacher, $page);
    request_page($otherTeacher, $page, ['csrf' => field(request_page($otherTeacher, 'profile.php'), 'csrf'), 'action' => 'remove', 'user_id' => $studentId]);
    ensure(!$excludedRow(), 'Other teacher removed a student');

    $result = $remove($teacher, $studentId);
    ensure($excludedRow(), 'Exclusion not saved');
    ensure(!db_value('SELECT 1 FROM enrollments WHERE course_id = ? AND user_id = ?', [$courseA, $studentId]), 'Individual enrollment kept');
    ensure((bool)db_value('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?', [$group, $studentId]), 'Student removed from group');
    ensure(str_contains($result, 'นำออกจากรายวิชาแล้ว 1 คน') && str_contains($result, 'value="restore"'), 'Excluded list');
    ensure(!in_array($studentId, $rosterIds($courseA), true) && in_array($studentId, $rosterIds($courseB), true), 'Roster filter');

    $mine = request_page($student, 'my-courses.php');
    ensure(!str_contains($mine, 'Exclusion A fixture') && str_contains($mine, 'Exclusion B fixture'), 'My courses filter');
    $noAccess = 'คุณยังไม่มีสิทธิ์เข้าเรียนบทเรียนนี้';
    ensure(str_contains(request_page($student, 'lesson.php?id=' . $lessonA), $noAccess), 'Excluded student opened lesson');

    // Self-enrolment with the course key cannot undo the teacher's removal.
    $html = request_page($student, 'course.php?id=' . $courseA);
    request_page($student, 'course.php?id=' . $courseA, ['csrf' => field($html, 'csrf'), 'action' => 'enroll', 'enroll_key' => 'EXCL-A']);
    ensure(!db_value('SELECT 1 FROM enrollments WHERE course_id = ? AND user_id = ?', [$courseA, $studentId]), 'Self-enrolment bypassed removal');

    // An individually enrolled student is simply unenrolled, with no exclusion row.
    $remove($teacher, $soloId);
    ensure(!db_value('SELECT 1 FROM enrollments WHERE course_id = ? AND user_id = ?', [$courseA, $soloId]) && !db_value('SELECT 1 FROM course_exclusions WHERE user_id = ?', [$soloId]), 'Individual removal');

    $html = request_page($teacher, $page);
    request_page($teacher, $page, ['csrf' => field($html, 'csrf'), 'action' => 'restore', 'user_id' => $studentId]);
    ensure(!$excludedRow() && in_array($studentId, $rosterIds($courseA), true), 'Restore');
    $lessonPage = request_page($student, 'lesson.php?id=' . $lessonA);
    ensure(!str_contains($lessonPage, $noAccess) && str_contains($lessonPage, 'Exclusion lesson'), 'Restored student blocked');

    // Adding the student individually also clears an exclusion.
    $remove($teacher, $studentId);
    $html = request_page($teacher, $page);
    request_page($teacher, $page, ['csrf' => field($html, 'csrf'), 'action' => 'add', 'user_ids' => [$studentId]]);
    ensure(!$excludedRow() && in_array($studentId, $rosterIds($courseA), true), 'Add clears exclusion');
    echo "Course exclusion HTTP tests passed: group/individual removal, other courses kept, roster, lessons, self-enrol block, restore, permissions\n";
} finally {
    foreach ($courses as $id) db_exec('DELETE FROM courses WHERE id = ?', [$id]);
    if ($group) db_exec('DELETE FROM student_groups WHERE id = ?', [$group]);
    foreach ($users as $id) db_exec('DELETE FROM users WHERE id = ?', [$id]);
    foreach ($clients as $client) curl_close($client);
    echo "Test fixtures removed\n";
}
