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
function login($username, $password) {
    $client = curl_init(); curl_setopt($client, CURLOPT_COOKIEFILE, '');
    $html = request_page($client, 'login.php');
    $page = request_page($client, 'login.php', ['csrf' => field($html, 'csrf'), 'username' => $username, 'password' => $password]);
    return [$client, !str_contains($page, 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง')];
}
$users = []; $clients = [];
try {
    $old = bin2hex(random_bytes(10));
    $names = [];
    foreach (['admin', 'student'] as $role) {
        $names[$role] = 'rpw_http_' . bin2hex(random_bytes(6));
        $users[$role] = db_exec('INSERT INTO users (username,password,fullname,role,consent_version,consent_at) VALUES (?,?,?,?,?,NOW())', [$names[$role], password_hash($old, PASSWORD_DEFAULT), 'Reset fixture ' . $role, $role, setting('privacy_version', '1')]);
    }
    [$admin] = login($names['admin'], $old); $clients[] = $admin;
    $dash = request_page($admin, 'admin/index.php');
    ensure(curl_getinfo($admin, CURLINFO_HTTP_CODE) === 200 && !str_contains($dash, 'อัปโหลดไฟล์ไม่ได้'), 'Dashboard loads without upload warning when folders are writable');
    $list = request_page($admin, 'admin/users.php?q=Reset+fixture');
    ensure(str_contains($list, 'value="reset_password"') && substr_count($list, 'value="reset_password"') === 1, 'Reset button shown (not for own account)');
    $page = request_page($admin, 'admin/users.php', ['csrf' => field($list, 'csrf'), 'action' => 'reset_password', 'id' => $users['student'], 'back_role' => 'student']);
    ensure(preg_match('~id="tempPassword">([A-Za-z0-9]{8})</code>~', $page, $m), 'Temporary password shown');
    ensure(!str_contains(request_page($admin, 'admin/users.php'), 'id="tempPassword"'), 'Shown only once');
    [$c1, $ok] = login($names['student'], $m[1]); $clients[] = $c1;
    ensure($ok, 'Student logs in with new password');
    [$c2, $ok] = login($names['student'], $old); $clients[] = $c2;
    ensure(!$ok, 'Old password rejected');
    request_page($admin, 'admin/users.php', ['csrf' => field($list, 'csrf'), 'action' => 'reset_password', 'id' => $users['admin']]);
    [$c3, $ok] = login($names['admin'], $old); $clients[] = $c3;
    ensure($ok, 'Admin cannot reset own password here');
    echo "Reset password HTTP tests passed: button, one-time temp password, login with new, old rejected, own account blocked\n";
} finally {
    foreach ($users as $id) db_exec('DELETE FROM users WHERE id = ?', [$id]);
    foreach ($clients as $client) curl_close($client);
    echo "Test fixtures removed\n";
}
