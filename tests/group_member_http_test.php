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
$users = []; $groups = []; $client = null;
try {
    $password = bin2hex(random_bytes(20));
    $admin = 'grp_http_' . bin2hex(random_bytes(6));
    $users[] = db_exec('INSERT INTO users (username,password,fullname,role,consent_version,consent_at) VALUES (?,?,?,?,?,NOW())', [$admin, password_hash($password, PASSWORD_DEFAULT), 'Group fixture admin', 'admin', setting('privacy_version', '1')]);
    $code = 'GRP' . random_int(100000, 999999);
    $student = $users[] = db_exec("INSERT INTO users (username,password,fullname,student_code,role) VALUES (?,?,?,?,'student')", ['grp_s_' . bin2hex(random_bytes(4)), 'x', 'Group fixture student', $code]);
    $other = $users[] = db_exec("INSERT INTO users (username,password,fullname,role) VALUES (?,?,?,'student')", ['grp_o_' . bin2hex(random_bytes(4)), 'x', 'Group fixture other']);
    $g1 = $groups[] = db_exec("INSERT INTO student_groups (name) VALUES ('GRP fixture 1')");
    $g2 = $groups[] = db_exec("INSERT INTO student_groups (name) VALUES ('GRP fixture 2')");
    db_exec('INSERT INTO group_members VALUES (?,?)', [$g1, $student]);
    $client = curl_init(); curl_setopt($client, CURLOPT_COOKIEFILE, '');
    $html = request_page($client, 'login.php');
    request_page($client, 'login.php', ['csrf' => field($html, 'csrf'), 'username' => $admin, 'password' => $password]);

    // เพิ่มด้วยรหัสนักศึกษาเข้ากลุ่ม 2 → ย้ายออกจากกลุ่ม 1 พร้อมแจ้งชื่อ
    $form = request_page($client, 'admin/group_form.php?id=' . $g2);
    $result = request_page($client, 'admin/group_form.php?id=' . $g2, ['csrf' => field($form, 'csrf'), 'action' => 'add', 'codes' => $code, 'user_ids' => [$other]]);
    $in = fn($uid) => array_map('intval', array_column(db_all('SELECT group_id FROM group_members WHERE user_id = ?', [$uid]), 'group_id'));
    ensure($in($student) === [$g2] && $in($other) === [$g2], 'Student moved, not duplicated');
    ensure(str_contains($result, 'เพิ่มสมาชิก 2 คน') && str_contains($result, 'Group fixture student (จาก GRP fixture 1)'), 'Move reported');
    // เพิ่มซ้ำในกลุ่มเดิม: ไม่นับและไม่ย้าย
    $result = request_page($client, 'admin/group_form.php?id=' . $g2, ['csrf' => field($form, 'csrf'), 'action' => 'add', 'user_ids' => [$student]]);
    ensure(str_contains($result, 'เพิ่มสมาชิก 0 คน') && $in($student) === [$g2], 'Re-adding same group is a no-op');
    // หน้าแก้ไขสมาชิก: อยู่ 2 กลุ่ม → แจ้งเตือน + ค่าเริ่มต้น "ยังไม่เปลี่ยน" · เลือกกลุ่มแล้วเหลือกลุ่มเดียว · บันทึกโดยไม่เลือกไม่กระทบ
    db_exec('INSERT INTO group_members VALUES (?,?)', [$g1, $student]);
    $edit = request_page($client, 'admin/user_form.php?id=' . $student);
    ensure(str_contains($edit, 'ตอนนี้อยู่ 2 กลุ่ม') && str_contains($edit, '<option value="keep" selected'), 'Edit form flags two groups');
    $fields = ['csrf' => field($edit, 'csrf'), 'role' => 'student', 'status' => 'active', 'fullname' => 'Group fixture student', 'student_code' => $code, 'username' => db_value('SELECT username FROM users WHERE id = ?', [$student]), 'password' => ''];
    request_page($client, 'admin/user_form.php?id=' . $student, $fields + ['group_id' => 'keep']);
    ensure(count($in($student)) === 2, 'Keep leaves memberships unchanged');
    request_page($client, 'admin/user_form.php?id=' . $student, $fields + ['group_id' => (string)$g1]);
    ensure($in($student) === [$g1], 'Choosing a group leaves exactly one');
    ensure(str_contains(request_page($client, 'admin/user_form.php?id=' . $student), '<option value="' . $g1 . '" selected'), 'Current group preselected');
    request_page($client, 'admin/user_form.php?id=' . $student, $fields + ['group_id' => '0']);
    ensure($in($student) === [], 'No group removes membership');
    echo "Group member HTTP tests passed: one group per student, move with notice, re-add no-op, member form group select\n";
} finally {
    foreach ($groups as $id) db_exec('DELETE FROM student_groups WHERE id = ?', [$id]);
    foreach ($users as $id) db_exec('DELETE FROM users WHERE id = ?', [$id]);
    if ($client) curl_close($client);
    echo "Test fixtures removed\n";
}
