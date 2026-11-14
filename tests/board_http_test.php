<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/boards.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME); $conn->set_charset('utf8mb4');
function ensure($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function request_page($client, $path, $data = null) {
    curl_setopt_array($client, [CURLOPT_URL => 'http://localhost/lms/' . $path, CURLOPT_POST => $data !== null, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
    if ($data !== null) curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($data));
    $body = curl_exec($client); ensure($body !== false, curl_error($client));
    ensure(!str_contains($body, 'Fatal error') && !str_contains($body, 'Warning:') && !str_contains($body, 'Notice:'), 'PHP error: ' . $path . "\n" . substr($body, 0, 600));
    return $body;
}
function field($html, $name) {
    preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m);
    ensure(isset($m[1]), 'Missing ' . $name);
    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}
$users = []; $clients = []; $course = 0; $csrf = [];
function api($client, string $action, array $fields = []): array {
    global $board, $csrf;
    $json = json_decode(request_page($client, 'board_api.php', ['csrf' => $csrf[spl_object_id($client)], 'board_id' => $board, 'action' => $action] + $fields), true);
    ensure(is_array($json), 'Not JSON');
    return $json + ['http' => curl_getinfo($client, CURLINFO_HTTP_CODE)];
}
function feed($client): array { global $board; return json_decode(request_page($client, 'board_api.php?board=' . $board), true) ?? []; }
try {
    $password = bin2hex(random_bytes(20));
    foreach (['teacher', 'student', 'student', 'student', 'student', 'student'] as $role) {
        $username = 'board2_http_' . bin2hex(random_bytes(6));
        $users[] = db_exec('INSERT INTO users (username,password,fullname,role,consent_version,consent_at) VALUES (?,?,?,?,?,NOW())', [$username, password_hash($password, PASSWORD_DEFAULT), 'Team ' . count($users), $role, setting('privacy_version', '1')]);
        $client = curl_init(); curl_setopt($client, CURLOPT_COOKIEFILE, ''); $clients[] = $client;
        $html = request_page($client, 'login.php'); request_page($client, 'login.php', ['csrf' => field($html, 'csrf'), 'username' => $username, 'password' => $password]);
        $csrf[spl_object_id($client)] = field(request_page($client, 'profile.php'), 'csrf');
    }
    [$teacher, $s1, $s2, $s3, $s4, $removed] = $clients;
    [$teacherId, $id1, $id2, $id3, $id4, $removedId] = $users;
    $course = db_exec("INSERT INTO courses (code,title,status) VALUES ('BOARD-2','Board team fixture','published')");
    db_exec('INSERT INTO course_teachers VALUES (?,?)', [$course, $teacherId]);
    $group = db_exec("INSERT INTO student_groups (name) VALUES ('Board team group')");
    db_exec('INSERT INTO course_groups VALUES (?,?)', [$course, $group]);
    foreach ([$id1, $id2, $id3, $id4, $removedId] as $uid) db_exec('INSERT INTO group_members VALUES (?,?)', [$group, $uid]);
    $lesson = db_exec("INSERT INTO lessons (course_id,title,media_type,content,is_published,sort_order) VALUES (?,'Unit with board','text','Body',1,1)", [$course]);

    // Jigsaw board with private team spaces.
    $form = 'admin/board_form.php?course_id=' . $course . '&template=jigsaw&lesson_id=' . $lesson;
    $html = request_page($teacher, $form);
    request_page($teacher, $form, ['csrf' => field($html, 'csrf'), 'action' => 'save', 'title' => 'Jigsaw', 'lesson_id' => $lesson, 'layout' => 'columns', 'status' => 'open', 'allow_comments' => '1',
        'reaction_mode' => 'star', 'student_role' => 'contributor', 'group_mode' => 'teams', 'team_visibility' => 'own', 'max_points' => '10', 'sections' => '']);
    $board = (int)db_value('SELECT id FROM boards WHERE course_id = ?', [$course]);
    $settings = 'admin/board_form.php?id=' . $board;
    $html = request_page($teacher, $settings);
    request_page($teacher, $settings, ['csrf' => field($html, 'csrf'), 'action' => 'teams_auto', 'team_count' => 2]);
    ensure((int)db_value('SELECT COUNT(*) FROM board_teams WHERE board_id = ?', [$board]) === 2 && (int)db_value('SELECT COUNT(*) FROM board_team_members WHERE board_id = ?', [$board]) === 5, 'Random teams');
    [$teamA, $teamB] = array_map('intval', array_column(db_all('SELECT id FROM board_teams WHERE board_id = ? ORDER BY id', [$board]), 'id'));
    // Fixed teams + roles: s1,s2 → A, s3,s4 → B; s2 moderator; s4 viewer.
    request_page($teacher, $settings, ['csrf' => field($html, 'csrf'), 'action' => 'roster_save',
        'team' => [$id1 => $teamA, $id2 => $teamA, $id3 => $teamB, $id4 => $teamB, $removedId => $teamB], 'role' => [$id2 => 'moderator', $id4 => 'viewer']]);
    ensure(board_user_team($board, $id3) === $teamB && db_value('SELECT role FROM board_members WHERE board_id = ? AND user_id = ?', [$board, $id4]) === 'viewer', 'Roster saved');

    // Lesson page links the board.
    request_page($s1, 'lesson.php?id=' . $lesson);
    ensure(str_contains(request_page($s1, 'lesson.php?id=' . $lesson), 'board.php?id=' . $board), 'Lesson link');

    $a = api($s1, 'post_save', ['body' => 'Team A idea']);
    $b = api($s3, 'post_save', ['body' => 'Team B idea']);
    ensure($a['ok'] && $b['ok'] && (int)db_value('SELECT team_id FROM board_posts WHERE id = ?', [$a['post_id']]) === $teamA, 'Team posts');
    ensure(array_column(feed($s1)['posts'], 'id') === [$a['post_id']] && array_column(feed($s3)['posts'], 'id') === [$b['post_id']], 'Team isolation');
    ensure(count(feed($teacher)['posts']) === 2, 'Teacher sees all teams');
    ensure(!api($s1, 'comment_add', ['post_id' => $b['post_id'], 'body' => 'peek'])['ok'], 'Commented on hidden team post');

    // Roles: moderator can moderate (not grade/manage); viewer read-only.
    ensure(feed($s2)['me']['can']['moderate'] === true && count(feed($s2)['posts']) === 2, 'Moderator sees all');
    ensure(api($s2, 'pin', ['post_id' => $b['post_id']])['ok'], 'Moderator pin');
    ensure(api($s2, 'set_status', ['status' => 'closed'])['http'] === 403 && api($s2, 'award_star', ['post_id' => $a['post_id']])['http'] === 403, 'Moderator managed');
    ensure(api($s4, 'post_save', ['body' => 'viewer'])['http'] === 403 && api($s4, 'comment_add', ['post_id' => $b['post_id'], 'body' => 'x'])['http'] === 403, 'Viewer wrote');
    ensure(feed($s4)['me']['can']['post'] === false, 'Viewer can flag');

    // Star reactions 1–5 and moving cards (columns/freeform/map).
    ensure(api($s1, 'react', ['post_id' => $a['post_id'], 'value' => 4])['ok'] === false, 'Self star');
    db_exec("UPDATE boards SET team_visibility = 'all' WHERE id = ?", [$board]);
    ensure(api($s1, 'react', ['post_id' => $b['post_id'], 'value' => 9])['ok'] && (int)db_value('SELECT value FROM board_reactions WHERE post_id = ? AND user_id = ?', [$b['post_id'], $id1]) === 5, 'Star clamp');
    ensure(api($s1, 'post_move', ['post_id' => $a['post_id'], 'x' => 340, 'y' => 120])['ok'] && (int)db_value('SELECT pos_x FROM board_posts WHERE id = ?', [$a['post_id']]) === 340, 'Freeform move');
    ensure(api($s1, 'post_move', ['post_id' => $b['post_id'], 'x' => 1])['http'] === 403, 'Moved classmate post');
    ensure(api($s1, 'post_move', ['post_id' => $a['post_id'], 'lat' => '13.7563', 'lng' => '100.5018'])['ok'] && db_value('SELECT lat FROM board_posts WHERE id = ?', [$a['post_id']]) === '13.756300', 'Map pin');

    // Removed from course → no access; archived board hidden from students; student preview read-only.
    db_exec('INSERT INTO course_exclusions (course_id,user_id) VALUES (?,?)', [$course, $removedId]);
    $csrf[spl_object_id($removed)] = field(request_page($removed, 'profile.php'), 'csrf');
    ensure(api($removed, 'post_save', ['body' => 'x'])['http'] === 403, 'Removed student posted');
    request_page($teacher, 'admin/boards.php?course_id=' . $course, ['csrf' => field(request_page($teacher, 'admin/boards.php?course_id=' . $course), 'csrf'), 'id' => $board, 'action' => 'archived']);
    ensure(db_value('SELECT status FROM boards WHERE id = ?', [$board]) === 'archived' && !str_contains(request_page($s1, 'course.php?id=' . $course), 'board.php?id=' . $board), 'Archived hidden');
    ensure(api($s1, 'post_save', ['body' => 'after archive'])['http'] === 409, 'Posted to archived board');
    request_page($teacher, 'admin/boards.php?course_id=' . $course, ['csrf' => field(request_page($teacher, 'admin/boards.php?course_id=' . $course), 'csrf'), 'id' => $board, 'action' => 'copy']);
    ensure((int)db_value('SELECT COUNT(*) FROM boards WHERE course_id = ?', [$course]) === 2 && (int)db_value('SELECT COUNT(*) FROM board_teams t JOIN boards b ON b.id = t.board_id WHERE b.course_id = ?', [$course]) === 4, 'Copy board');

    // Pages render for every role.
    foreach ([[$teacher, 'admin/board_report.php?id=' . $board], [$teacher, 'admin/boards.php'], [$teacher, $settings], [$s1, 'my-activities.php?course_id=' . $course], [$teacher, 'leaderboard.php?course_id=' . $course], [$s1, 'board.php?id=' . $board]] as [$c, $p]) {
        request_page($c, $p);
        ensure(curl_getinfo($c, CURLINFO_HTTP_CODE) === 200, 'Page ' . $p);
    }
    echo "Board team/role HTTP tests passed: templates, random/fixed teams, team isolation, moderator/viewer roles, star reactions, freeform/map moves, course removal, archive, copy, page rendering\n";
} finally {
    if ($course) db_exec('DELETE FROM courses WHERE id = ?', [$course]);
    if (!empty($group)) db_exec('DELETE FROM student_groups WHERE id = ?', [$group]);
    foreach ($users as $id) db_exec('DELETE FROM users WHERE id = ?', [$id]);
    foreach ($clients as $client) curl_close($client);
    echo "Test fixtures removed\n";
}
