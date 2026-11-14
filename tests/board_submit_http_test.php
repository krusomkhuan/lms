<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/schedule.php';
require __DIR__ . '/../includes/quiz.php';
require __DIR__ . '/../includes/assignments.php';
require __DIR__ . '/../includes/lesson_media.php';
require __DIR__ . '/../includes/gradebook.php';
require __DIR__ . '/../includes/boards.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME); $conn->set_charset('utf8mb4');
$conn->query("SET time_zone='+07:00'");
function ensure($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function request_page($client, $path, $data = null, bool $multipart = false) {
    curl_setopt_array($client, [CURLOPT_URL => 'http://localhost/lms/' . $path, CURLOPT_POST => $data !== null, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
    if ($data !== null) curl_setopt($client, CURLOPT_POSTFIELDS, $multipart ? $data : http_build_query($data));
    $body = curl_exec($client); ensure($body !== false, curl_error($client));
    ensure(!str_contains($body, 'Fatal error') && !str_contains($body, 'Warning:') && !str_contains($body, 'Notice:'), 'PHP error: ' . $path . "\n" . substr($body, 0, 600));
    return $body;
}
function field($html, $name) {
    preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m);
    ensure(isset($m[1]), 'Missing ' . $name);
    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}
$users = []; $clients = []; $course = 0; $tmpFiles = []; $csrf = [];
function api($client, string $action, array $fields = [], bool $multipart = false): array {
    global $board, $csrf;
    $body = request_page($client, 'board_api.php', ['csrf' => $csrf[spl_object_id($client)] ?? '', 'board_id' => $board, 'action' => $action] + $fields, $multipart);
    $json = json_decode($body, true);
    ensure(is_array($json), 'Not JSON: ' . substr($body, 0, 300));
    return $json + ['http' => curl_getinfo($client, CURLINFO_HTTP_CODE)];
}
function feed($client, int $since = 0): array {
    global $board;
    return json_decode(request_page($client, 'board_api.php?board=' . $board . '&since=' . $since), true) ?? [];
}
function tmp(string $name, string $content): CURLFile {
    global $tmpFiles;
    $path = sys_get_temp_dir() . '/' . bin2hex(random_bytes(4)) . '-' . $name;
    file_put_contents($path, $content);
    $tmpFiles[] = $path;
    return new CURLFile($path, 'application/octet-stream', $name);
}
try {
    ensure(boards_ready(), 'Run database/migrations/1.17.0.sql first');
    $password = bin2hex(random_bytes(20));
    foreach (['teacher', 'student', 'student', 'student', 'teacher'] as $role) {
        $username = 'board_http_' . bin2hex(random_bytes(6));
        $users[] = db_exec('INSERT INTO users (username,password,fullname,role,consent_version,consent_at) VALUES (?,?,?,?,?,NOW())', [$username, password_hash($password, PASSWORD_DEFAULT), 'Board ' . count($users), $role, setting('privacy_version', '1')]);
        $client = curl_init(); curl_setopt($client, CURLOPT_COOKIEFILE, ''); $clients[] = $client;
        $html = request_page($client, 'login.php'); request_page($client, 'login.php', ['csrf' => field($html, 'csrf'), 'username' => $username, 'password' => $password]);
    }
    [$teacher, $alice, $bob, $outsider, $otherTeacher] = $clients;
    [$teacherId, $aliceId, $bobId, $outsiderId] = $users;
    $course = db_exec("INSERT INTO courses (code,title,status) VALUES ('BOARD-T','Board fixture','published')");
    db_exec('INSERT INTO course_teachers VALUES (?,?)', [$course, $teacherId]);
    db_exec('INSERT INTO enrollments (course_id,user_id) VALUES (?,?),(?,?)', [$course, $aliceId, $course, $bobId]);

    // Teacher creates a worksheet board from the "assignment" template.
    $form = 'admin/board_form.php?course_id=' . $course . '&template=assignment';
    $html = request_page($teacher, $form);
    ensure(str_contains($html, 'value="wordcloud"') && str_contains($html, 'name="one_answer" value="1" checked'), 'Template defaults');
    request_page($teacher, $form, ['csrf' => field($html, 'csrf'), 'action' => 'save', 'title' => 'Worksheet 1', 'description' => '<p>Design a logo</p>', 'layout' => 'grid', 'status' => 'open',
        'due_at' => date('Y-m-d\TH:i', strtotime('+2 days')), 'accept_late' => '1', 'is_assignment' => '1', 'max_points' => '10', 'one_answer' => '1', 'hide_until_reveal' => '1',
        'allow_comments' => '1', 'reaction_mode' => 'like', 'student_role' => 'contributor', 'group_mode' => 'none', 'team_visibility' => 'all', 'sections' => "Draft\nFinal"]);
    $board = (int)db_value('SELECT id FROM boards WHERE course_id = ?', [$course]);
    ensure($board && (int)db_value('SELECT COUNT(*) FROM board_sections WHERE board_id = ?', [$board]) === 2, 'Board saved');
    ensure((bool)db_value("SELECT 1 FROM grade_items WHERE course_id = ? AND source_key = ? AND kind = 'board'", [$course, 'board:' . $board . ':0']), 'Gradebook item');

    foreach ([$alice, $bob, $outsider, $teacher, $otherTeacher] as $c) {
        $page = request_page($c, 'board.php?id=' . $board);
        if (preg_match('/"csrf":"([a-f0-9]+)"/', $page, $m)) $csrf[spl_object_id($c)] = $m[1];
        else $csrf[spl_object_id($c)] = field(request_page($c, 'profile.php'), 'csrf');
    }
    ensure(str_contains(request_page($alice, 'board.php?id=' . $board), 'Design a logo'), 'Question shown');

    // Pre-upload: allowed kinds, refused kinds, outsider refused, private storage.
    $up = api($alice, 'upload', ['file' => tmp('logo.png', "\x89PNG fake image")], true);
    ensure($up['ok'] && $up['upload']['kind'] === 'image', 'Image upload');
    $doc = api($alice, 'upload', ['file' => tmp('report.docx', 'docx bytes')], true);
    ensure($doc['ok'] && $doc['upload']['kind'] === 'document', 'Word upload');
    $voice = api($alice, 'upload', ['file' => tmp('recording.weba', 'audio')], true);
    ensure($voice['ok'] && $voice['upload']['kind'] === 'audio', 'Recorded audio upload');
    ensure(!api($alice, 'upload', ['file' => tmp('virus.exe', 'MZ')], true)['ok'], 'Exe accepted');
    ensure(api($outsider, 'upload', ['file' => tmp('x.png', 'x')], true)['http'] === 403, 'Outsider uploaded');
    $stored = db_value('SELECT path FROM board_uploads WHERE id = ?', [$up['upload']['id']]);
    request_page($outsider, 'uploads/' . $stored);
    ensure(curl_getinfo($outsider, CURLINFO_HTTP_CODE) === 403, 'Upload publicly reachable');

    // Bob cannot attach Alice's upload.
    ensure(!api($bob, 'post_save', ['body' => 'steal', 'uploads' => [$doc['upload']['id']]])['ok'], 'Foreign upload attached');
    ensure(!api($bob, 'post_save', [])['ok'], 'Empty answer accepted');

    $saved = api($alice, 'post_save', ['title' => 'My logo', 'body' => "Idea\nline 2", 'link_url' => 'youtu.be/dQw4w9WgXcQ', 'uploads' => [$up['upload']['id'], $doc['upload']['id'], $voice['upload']['id']]]);
    ensure($saved['ok'] && $saved['status'] === 'approved', 'Answer saved');
    $postId = (int)$saved['post_id'];
    ensure((int)db_value('SELECT COUNT(*) FROM board_attachments WHERE post_id = ?', [$postId]) === 3 && !db_value('SELECT 1 FROM board_uploads WHERE user_id = ?', [$aliceId]), 'Attachments linked');
    ensure(db_value('SELECT link_url FROM board_posts WHERE id = ?', [$postId]) === 'https://youtu.be/dQw4w9WgXcQ', 'Link normalised');

    // One answer per student: a second submit edits the first and keeps history.
    $again = api($alice, 'post_save', ['title' => 'My logo v2', 'body' => 'Improved']);
    ensure($again['ok'] && (int)$again['post_id'] === $postId && $again['edited'], 'One-answer edit');
    ensure((int)db_value('SELECT COUNT(*) FROM board_posts WHERE board_id = ? AND user_id = ?', [$board, $aliceId]) === 1 && (int)db_value('SELECT COUNT(*) FROM board_post_revisions WHERE post_id = ?', [$postId]) === 1, 'Revision history');

    // Hidden until reveal: Bob sees only his own posts; files follow the same rule.
    $attach = (int)db_value("SELECT id FROM board_attachments WHERE post_id = ? AND kind = 'image'", [$postId]);
    ensure(feed($bob)['posts'] === [], 'Answer leaked before reveal');
    request_page($bob, 'board_file.php?id=' . $attach);
    ensure(curl_getinfo($bob, CURLINFO_HTTP_CODE) === 403, 'File leaked before reveal');
    $teacherFeed = feed($teacher);
    ensure(count($teacherFeed['posts']) === 1 && $teacherFeed['posts'][0]['revisions'] === 1, 'Teacher sees answer');
    ensure(request_page($teacher, 'board_file.php?id=' . $attach) === "\x89PNG fake image", 'Teacher opens file');
    request_page($outsider, 'board_file.php?id=' . $attach);
    ensure(curl_getinfo($outsider, CURLINFO_HTTP_CODE) === 403, 'Outsider opened file');
    ensure(api($bob, 'reveal')['http'] === 403, 'Student revealed');
    ensure(api($teacher, 'reveal')['ok'] && count(feed($bob)['posts']) === 1, 'Reveal');
    ensure(request_page($bob, 'board_file.php?id=' . $attach) === "\x89PNG fake image", 'Classmate opens revealed file');
    $rev = feed($bob)['rev'];
    ensure(feed($bob, $rev)['changed'] === false, 'Unchanged feed');

    // Comments, likes and XP rules.
    ensure(api($bob, 'comment_add', ['post_id' => $postId, 'body' => 'Nice colours'])['ok'], 'Comment');
    ensure(!api($alice, 'react', ['post_id' => $postId, 'value' => 1])['ok'], 'Self like');
    ensure(api($bob, 'react', ['post_id' => $postId, 'value' => 1])['ok'] && api($bob, 'react', ['post_id' => $postId, 'value' => 1])['ok'], 'Like twice');
    ensure((int)db_value('SELECT COUNT(*) FROM board_reactions WHERE post_id = ?', [$postId]) === 1, 'Duplicate like');
    ensure(xp_total($course, $bobId) === XP_RULES['comment'], 'Comment XP');
    ensure(xp_total($course, $aliceId) === XP_RULES['post'] + XP_RULES['ontime'] + XP_RULES['reaction'], 'Post XP');
    api($bob, 'react', ['post_id' => $postId, 'value' => 0]);
    ensure(xp_total($course, $aliceId) === XP_RULES['post'] + XP_RULES['ontime'], 'Unlike revokes XP');

    // Late and closed windows.
    db_exec('UPDATE boards SET due_at = NOW() - INTERVAL 1 HOUR, rev = rev + 1 WHERE id = ?', [$board]);
    $late = api($bob, 'post_save', ['body' => 'Late answer']);
    ensure($late["ok"] && (int)db_value("SELECT is_late FROM board_posts WHERE id = ?", [$late["post_id"]]) === 1 && str_contains($late['message'], 'เกินเวลา'), 'Late submission');
    db_exec('UPDATE boards SET closes_at = NOW() - INTERVAL 1 MINUTE WHERE id = ?', [$board]);
    ensure(api($bob, 'post_save', ['body' => 'Too late'])['http'] === 409, 'Closed board accepted answer');
    ensure(api($teacher, 'post_save', ['body' => 'Teacher note'])['ok'], 'Teacher can still post');
    db_exec('UPDATE boards SET closes_at = NULL, due_at = NOW() + INTERVAL 1 DAY WHERE id = ?', [$board]);

    // Teacher review: grade Alice, ask Bob to revise; stale form refused.
    $report = 'admin/board_report.php?id=' . $board;
    $html = request_page($teacher, $report);
    ensure(str_contains($html, 'ส่งเกินเวลา') && str_contains($html, 'ส่งแล้ว'), 'Submission states');
    request_page($teacher, $report, ['csrf' => field($html, 'csrf'), 'action' => 'grade', 'user_id' => $aliceId, 'version' => 0, 'status' => 'graded', 'score' => '8', 'feedback' => 'Good work']);
    ensure(db_value('SELECT score FROM board_scores WHERE board_id = ? AND user_id = ?', [$board, $aliceId]) === '8.00', 'Score saved');
    request_page($teacher, $report, ['csrf' => field($html, 'csrf'), 'action' => 'grade', 'user_id' => $aliceId, 'version' => 0, 'status' => 'graded', 'score' => '1']);
    ensure(db_value('SELECT score FROM board_scores WHERE board_id = ? AND user_id = ?', [$board, $aliceId]) === '8.00', 'Stale grade overwrote');
    request_page($teacher, $report, ['csrf' => field($html, 'csrf'), 'action' => 'grade', 'user_id' => $aliceId, 'version' => 1, 'status' => 'graded', 'score' => '11']);
    ensure(db_value('SELECT score FROM board_scores WHERE board_id = ? AND user_id = ?', [$board, $aliceId]) === '8.00', 'Over max accepted');
    ensure(xp_total($course, $aliceId) === XP_RULES['post'] + XP_RULES['ontime'] + (int)round(8 / 10 * XP_RULES['grade']), 'Grade stars');
    $mine = feed($alice)['me']['score'];
    ensure($mine['score'] === '8.00' && $mine['feedback'] === 'Good work', 'Student sees score');
    request_page($teacher, $report, ['csrf' => field($html, 'csrf'), 'action' => 'grade', 'user_id' => $bobId, 'version' => 0, 'status' => 'revision', 'feedback' => 'Add a sketch']);
    ensure(db_value('SELECT status FROM board_scores WHERE board_id = ? AND user_id = ?', [$board, $bobId]) === 'revision', 'Revision');
    ensure(count(array_filter(board_pending_for($bobId), fn($p) => $p['revision'])) === 1, 'Revision in pending bell');
    ensure(api($bob, 'post_save', ['post_id' => $late['post_id'], 'body' => 'Late answer + sketch'])['ok'], 'Resubmit');
    ensure(db_value('SELECT status FROM board_scores WHERE board_id = ? AND user_id = ?', [$board, $bobId]) === 'graded' && db_value('SELECT score FROM board_scores WHERE board_id = ? AND user_id = ?', [$board, $bobId]) === null, 'Resubmit clears revision');
    request_page($otherTeacher, $report);
    ensure(curl_getinfo($otherTeacher, CURLINFO_HTTP_CODE) === 403, 'Other teacher report');

    // Scores reach the course gradebook.
    $data = gradebook_data($course);
    $item = array_values(array_filter($data['items'], fn($i) => $i['kind'] === 'board'))[0];
    $cells = array_column(array_map(fn($r) => ['id' => (int)$r['student']['id'], 'cell' => $r['cells'][$item['id']]], $data['rows']), 'cell', 'id');
    ensure($cells[$aliceId]['score'] === '8.00' && $cells[$bobId]['state'] === 'pending', 'Gradebook cells');
    ensure(str_contains(request_page($teacher, 'admin/gradebook.php?course_id=' . $course), 'ใบงานกระดาน'), 'Gradebook page');

    // Exports.
    $zip = request_page($teacher, $report . '&export=zip');
    ensure(str_starts_with($zip, "PK\x03\x04") && str_contains($zip, 'logo.png') && str_contains($zip, 'report.docx') && substr($zip, -22, 4) === "PK\x05\x06", 'ZIP export');
    ensure(unpack('v', substr($zip, -12, 2))[1] === 4, 'ZIP entry count');
    ensure(str_contains(request_page($teacher, $report . '&export=csv'), 'Good work'), 'CSV export');
    ensure(str_contains(request_page($teacher, $report . '&tab=activity'), 'ให้คะแนน') && str_contains(request_page($teacher, $report . '&tab=participation'), 'ดาว ⭐'), 'Report tabs');

    // Teacher awards stars; students cannot; dashboard shows what is left to do.
    $beforeStars = xp_total($course, $aliceId);
    ensure(api($teacher, 'award_star', ['post_id' => $postId])['ok'] && xp_total($course, $aliceId) === $beforeStars + XP_RULES['star'], 'Award star');
    ensure(api($bob, 'award_star', ['post_id' => $postId])['http'] === 403, 'Student awarded star');
    $all = api($teacher, 'award_star', ['all' => 1]);
    ensure($all['ok'] && $all['awarded'] === 2, 'Give stars to all');
    // Several students at once, several stars each; amount is capped at 5.
    $bobPost = (int)db_value('SELECT id FROM board_posts WHERE board_id = ? AND user_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$board, $bobId]);
    [$aliceBefore, $bobBefore] = [xp_total($course, $aliceId), xp_total($course, $bobId)];
    $many = api($teacher, 'award_star', ['post_ids' => [$postId, $bobPost], 'amount' => 3]);
    ensure($many['ok'] && $many['awarded'] === 2 && xp_total($course, $aliceId) === $aliceBefore + 3 && xp_total($course, $bobId) === $bobBefore + 3, 'Award several stars to several students');
    api($teacher, 'award_star', ['post_ids' => [$postId], 'amount' => 99]);
    ensure(xp_total($course, $aliceId) === $aliceBefore + 8, 'Star amount cap');
    ensure(!api($teacher, 'award_star', ['post_ids' => []])['ok'], 'Award with nothing selected');
    $boardStars = (int)db_value("SELECT SUM(points) FROM xp_events WHERE board_id = ? AND user_id = ? AND source = 'star'", [$board, $aliceId]);
    $mineInFeed = array_values(array_filter(feed($teacher)['posts'], fn($p) => $p['id'] === $postId))[0];
    ensure($mineInFeed['board_stars'] === $boardStars && $boardStars === 1 + 1 + 3 + 5, 'Stars in this activity');
    $alicePost = array_values(array_filter(feed($teacher)['posts'], fn($p) => $p['user_id'] === $aliceId))[0];
    $teacherPost = array_values(array_filter(feed($teacher)['posts'], fn($p) => $p['user_id'] === $teacherId))[0];
    ensure($alicePost['level'] >= 1 && $alicePost['stars'] === xp_total($course, $aliceId) && $teacherPost['level'] === null && feed($alice)['me']['stars']['stars'] === xp_total($course, $aliceId), 'Stars in feed');
    $todoBoard = db_exec("INSERT INTO boards (course_id,title,layout,is_assignment,due_at) VALUES (?,'Exit ticket todo','stream',1,NOW() + INTERVAL 3 DAY)", [$course]);
    $dash = request_page($alice, 'my-activities.php?course_id=' . $course);
    ensure(str_contains($dash, 'Exit ticket todo') && str_contains($dash, 'ไปทำเลย') && str_contains($dash, 'Worksheet 1') && str_contains($dash, 'โพสต์แรก') && preg_match('/เหลือ [23] วัน/u', $dash), 'Student dashboard');
    ensure(str_contains(request_page($alice, 'board.php?id=' . $todoBoard), 'Exit ticket todo'), 'Second board');
    db_exec('DELETE FROM boards WHERE id = ?', [$todoBoard]);

    // Approval queue.
    db_exec('UPDATE boards SET require_approval = 1, one_answer = 0 WHERE id = ?', [$board]);
    $pending = api($bob, 'post_save', ['body' => 'Needs approval']);
    ensure($pending['status'] === 'pending' && !in_array($pending['post_id'], array_column(feed($alice)['posts'], 'id'), true), 'Pending hidden');
    ensure(api($bob, 'approve', ['post_id' => $pending['post_id']])['http'] === 403, 'Student approved');
    ensure(api($teacher, 'reject', ['post_id' => $pending['post_id'], 'note' => ''])['ok'] === false, 'Reject without note');
    ensure(api($teacher, 'approve', ['post_id' => $pending['post_id']])['ok'] && in_array($pending['post_id'], array_column(feed($alice)['posts'], 'id'), true), 'Approve');

    // Delete, CSRF, leaderboard, cleanup of stale uploads.
    ensure(!api($bob, 'post_delete', ['post_id' => $postId])['ok'], 'Deleted classmate post');
    $before = xp_total($course, $aliceId);
    ensure(api($alice, 'post_delete', ['post_id' => $postId])['ok'] && xp_total($course, $aliceId) < $before, 'Delete revokes XP');
    $csrf[spl_object_id($alice)] = 'bad';
    ensure(api($alice, 'post_save', ['body' => 'x'])['http'] === 400, 'CSRF');
    ensure(str_contains(request_page($alice, 'leaderboard.php?course_id=' . $course), 'ยังไม่เปิดตารางอันดับ'), 'Leaderboard closed');
    db_exec('UPDATE courses SET leaderboard_enabled = 1 WHERE id = ?', [$course]);
    ensure(str_contains(request_page($alice, 'leaderboard.php?course_id=' . $course), 'Board 2'), 'Leaderboard open');
    $stale = db_exec("INSERT INTO board_uploads (board_id,user_id,kind,path,original_name,size,created_at) VALUES (?,?,'image','boards/" . str_repeat('a', 48) . ".png','old.png',1,NOW() - INTERVAL 2 DAY)", [$board, $bobId]);
    board_cleanup_uploads();
    ensure(!db_value('SELECT 1 FROM board_uploads WHERE id = ?', [$stale]), 'Stale upload cleanup');
    echo "Board submit HTTP tests passed: template, pre-upload kinds, private files, one-answer edits/history, reveal, comments, likes, XP, late/closed, grading, revision, gradebook, ZIP/CSV, approval, delete, CSRF, leaderboard\n";
} finally {
    if ($course) {
        foreach (board_files_where('b.course_id = ?', [$course]) as $path) delete_upload($path);
        db_exec('DELETE FROM courses WHERE id = ?', [$course]);
    }
    foreach ($users as $id) db_exec('DELETE FROM users WHERE id = ?', [$id]);
    foreach ($clients as $client) curl_close($client);
    foreach ($tmpFiles as $f) @unlink($f);
    echo "Test fixtures and files removed\n";
}
