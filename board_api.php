<?php
// API กระดานกิจกรรม (JSON): feed แบบ polling, อัปโหลดไฟล์ล่วงหน้า, ส่ง/แก้คำตอบ, ความคิดเห็น, reaction, การดูแลกระดาน
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function board_json(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function board_fail(string $message, int $code = 400): never
{
    board_json(['ok' => false, 'error' => $message], $code);
}

$me = current_user();
if (!$me) board_fail('หมดเวลาการเข้าสู่ระบบ กรุณาเข้าสู่ระบบใหม่', 401);
if (!boards_ready()) board_fail('กรุณารัน database/migrations/1.17.0.sql ก่อนใช้กระดานกิจกรรม', 503);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost && !$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    board_fail('ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับ (สูงสุด ' . round(board_max_upload_bytes() / 1048576) . ' MB)', 413);
}
if ($isPost && !hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) board_fail('หน้านี้เปิดไว้นานเกินไป กรุณารีเฟรชหน้าแล้วลองใหม่ (คำตอบที่พิมพ์ไว้ยังอยู่)', 400);

$board = board_get($isPost ? (int)post('board_id') : get_int('board'));
if (!$board) board_fail('ไม่พบกระดาน', 404);
$role = board_role($board, $me);
if (!$role) board_fail('คุณไม่มีสิทธิ์เข้ากระดานนี้', 403);
$uid = (int)$me['id'];
$boardId = (int)$board['id'];
$canModerate = board_can($role, 'moderate');

/** ข้อมูลกระดานทั้งหมดที่ผู้ใช้เห็นได้ */
function board_payload(array $board, string $role, array $me): array
{
    $uid = (int)$me['id'];
    $boardId = (int)$board['id'];
    $mod = board_can($role, 'moderate');
    [$where, $params] = board_post_filter($board, $role, $uid);
    $posts = db_all("SELECT p.*, u.fullname, u.avatar, u.student_code, u.role FROM board_posts p JOIN users u ON u.id = p.user_id WHERE $where ORDER BY p.is_pinned DESC, p.created_at DESC, p.id DESC LIMIT 1000", $params);
    $ids = array_map(fn($p) => (int)$p['id'], $posts);
    $attachments = $comments = $reactions = $mine = $revisions = [];
    if ($ids) {
        $in = implode(',', $ids);
        foreach (db_all("SELECT * FROM board_attachments WHERE post_id IN ($in) ORDER BY sort_order, id") as $a) {
            $attachments[(int)$a['post_id']][] = ['id' => (int)$a['id'], 'kind' => $a['kind'], 'name' => $a['original_name'], 'size' => (int)$a['size'],
                'ext' => strtolower(pathinfo($a['path'], PATHINFO_EXTENSION)), 'url' => url('board_file.php?id=' . $a['id'])];
        }
        if ($board['allow_comments'] || $mod) foreach (db_all("SELECT c.*, u.fullname, u.avatar FROM board_comments c JOIN users u ON u.id = c.user_id WHERE c.post_id IN ($in) AND c.deleted_at IS NULL ORDER BY c.id") as $c) {
            $comments[(int)$c['post_id']][] = ['id' => (int)$c['id'], 'author' => $c['fullname'], 'avatar' => avatar_url($c), 'body' => $c['body'], 'at' => $c['created_at'],
                'mine' => (int)$c['user_id'] === $uid, 'can_delete' => (int)$c['user_id'] === $uid || $mod];
        }
        foreach (db_all("SELECT post_id, COUNT(*) AS n, AVG(value) AS avg FROM board_reactions WHERE post_id IN ($in) GROUP BY post_id") as $r) $reactions[(int)$r['post_id']] = $r;
        foreach (db_all("SELECT post_id, value FROM board_reactions WHERE post_id IN ($in) AND user_id = ?", [$uid]) as $r) $mine[(int)$r['post_id']] = (int)$r['value'];
        foreach (db_all("SELECT post_id, COUNT(*) AS n FROM board_post_revisions WHERE post_id IN ($in) GROUP BY post_id") as $r) $revisions[(int)$r['post_id']] = (int)$r['n'];
    }
    $window = board_window($board);
    $authorIds = array_values(array_unique(array_map(fn($p) => (int)$p['user_id'], $posts)));
    $stats = $authorIds ? star_stats((int)$board['course_id'], $authorIds) : [];
    // ดาวที่ครูมอบในกิจกรรมนี้ (แยกตามกระดาน)
    $boardStars = $authorIds ? array_map('intval', array_column(db_all("SELECT user_id, SUM(points) AS n FROM xp_events WHERE board_id = ? AND source = 'star' GROUP BY user_id", [$boardId]), 'n', 'user_id')) : [];
    $out = [];
    foreach ($posts as $p) {
        $pid = (int)$p['id'];
        $own = (int)$p['user_id'] === $uid;
        $editable = $own ? in_array($window, ['open', 'late'], true) || board_can($role, 'manage') : $mod;
        $out[] = [
            'id' => $pid, 'user_id' => (int)$p['user_id'], 'author' => $p['fullname'], 'student_code' => $mod ? (string)$p['student_code'] : '', 'avatar' => avatar_url($p),
            'team_id' => $p['team_id'] ? (int)$p['team_id'] : null, 'section_id' => $p['section_id'] ? (int)$p['section_id'] : null,
            'title' => $p['title'], 'body' => (string)$p['body'], 'link' => $p['link_url'] ? link_preview($p['link_url']) : null,
            'status' => $p['status'], 'reject_note' => ($own || $mod) ? (string)$p['reject_note'] : '', 'pinned' => (bool)$p['is_pinned'], 'late' => (bool)$p['is_late'],
            'x' => $p['pos_x'] !== null ? (int)$p['pos_x'] : null, 'y' => $p['pos_y'] !== null ? (int)$p['pos_y'] : null,
            'lat' => $p['lat'] !== null ? (float)$p['lat'] : null, 'lng' => $p['lng'] !== null ? (float)$p['lng'] : null,
            'created_at' => $p['created_at'], 'updated_at' => $p['updated_at'], 'revisions' => $revisions[$pid] ?? 0,
            'attachments' => $attachments[$pid] ?? [], 'comments' => $comments[$pid] ?? [],
            'reactions' => ['count' => (int)($reactions[$pid]['n'] ?? 0), 'avg' => isset($reactions[$pid]) ? round((float)$reactions[$pid]['avg'], 1) : 0, 'mine' => $mine[$pid] ?? 0],
            'stars' => ($p['role'] ?? '') === 'student' ? (int)($stats[(int)$p['user_id']]['total'] ?? 0) : null,
            'level' => ($p['role'] ?? '') === 'student' ? star_level((int)($stats[(int)$p['user_id']]['total'] ?? 0))['level'] : null,
            'board_stars' => (int)($boardStars[(int)$p['user_id']] ?? 0),
            'mine' => $own, 'can_edit' => $editable, 'can_delete' => $editable, 'can_move' => $own || $mod,
        ];
    }
    $myScore = null;
    if ($board['is_assignment'] && $me['role'] === 'student') {
        $s = db_one('SELECT score, feedback, status, graded_at FROM board_scores WHERE board_id = ? AND user_id = ?', [$boardId, $uid]);
        if ($s) $myScore = ['score' => $s['score'], 'feedback' => (string)$s['feedback'], 'status' => $s['status'], 'at' => $s['graded_at']];
    }
    $pending = $mod ? (int)db_value("SELECT COUNT(*) FROM board_posts WHERE board_id = ? AND status = 'pending' AND deleted_at IS NULL", [$boardId]) : 0;
    return [
        'ok' => true, 'rev' => (int)$board['rev'],
        'board' => [
            'id' => $boardId, 'title' => $board['title'], 'layout' => $board['layout'], 'status' => $board['status'], 'window' => $window, 'window_label' => board_window_label($board),
            'hide_until_reveal' => (bool)$board['hide_until_reveal'], 'revealed' => board_revealed($board), 'require_approval' => (bool)$board['require_approval'],
            'reaction_mode' => $board['reaction_mode'], 'allow_comments' => (bool)$board['allow_comments'], 'group_mode' => $board['group_mode'], 'team_visibility' => $board['team_visibility'],
            'one_answer' => (bool)$board['one_answer'], 'is_assignment' => (bool)$board['is_assignment'], 'max_points' => $board['max_points'], 'pending' => $pending,
        ],
        'me' => [
            'id' => $uid, 'role' => $role, 'role_label' => BOARD_ROLES[$role], 'stars' => $me['role'] === 'student' ? star_level(xp_total((int)$board['course_id'], $uid)) : null, 'team_id' => board_user_team($boardId, $uid), 'score' => $myScore,
            'can' => ['post' => board_can($role, 'post') && (in_array($window, ['open', 'late'], true) || board_can($role, 'manage')), 'comment' => board_can($role, 'comment') && ($board['allow_comments'] || $mod) && $board['status'] === 'open',
                'react' => board_can($role, 'react') && $board['reaction_mode'] !== 'none' && $board['status'] === 'open', 'moderate' => $mod, 'manage' => board_can($role, 'manage')],
        ],
        'sections' => array_map(fn($s) => ['id' => (int)$s['id'], 'title' => $s['title']], db_all('SELECT * FROM board_sections WHERE board_id = ? ORDER BY sort_order, id', [$boardId])),
        'teams' => array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name']], db_all('SELECT * FROM board_teams WHERE board_id = ? ORDER BY sort_order, id', [$boardId])),
        'posts' => $out,
    ];
}

function board_find_post(int $boardId, int $postId): array
{
    $post = db_one('SELECT * FROM board_posts WHERE id = ? AND board_id = ? AND deleted_at IS NULL', [$postId, $boardId]);
    if (!$post) board_fail('ไม่พบโพสต์ อาจถูกลบไปแล้ว', 404);
    return $post;
}

function board_done(array $board, array $extra = []): never
{
    board_bump((int)$board['id']);
    board_json(['ok' => true] + $extra);
}

if (!$isPost) {
    // polling: ส่งข้อมูลเต็มเมื่อกระดานเปลี่ยน (rev) เท่านั้น
    session_write_close();
    if (get_int('since') === (int)$board['rev'] && get_int('since') > 0) board_json(['ok' => true, 'changed' => false, 'rev' => (int)$board['rev']]);
    board_json(board_payload($board, $role, $me) + ['changed' => true]);
}

$action = (string)post('action');
$window = board_window($board);
$open = in_array($window, ['open', 'late'], true) || board_can($role, 'manage');

try {
    switch ($action) {
        case 'upload':
            if (!board_can($role, 'post')) board_fail('คุณดูกระดานนี้ได้อย่างเดียว', 403);
            if (!$open) board_fail($window === 'upcoming' ? 'กระดานยังไม่เปิดรับคำตอบ' : 'กระดานปิดรับคำตอบแล้ว', 409);
            board_cleanup_uploads();
            if ((int)db_value('SELECT COUNT(*) FROM board_uploads WHERE board_id = ? AND user_id = ?', [$boardId, $uid]) >= 20) board_fail('มีไฟล์ที่ยังไม่ได้โพสต์มากเกินไป กรุณาโพสต์หรือลบไฟล์ก่อน', 429);
            $file = board_upload($_FILES['file'] ?? []);
            $id = db_exec('INSERT INTO board_uploads (board_id,user_id,kind,path,original_name,size) VALUES (?,?,?,?,?,?)', [$boardId, $uid, $file['kind'], $file['path'], $file['name'], $file['size']]);
            board_json(['ok' => true, 'upload' => ['id' => $id, 'kind' => $file['kind'], 'name' => $file['name'], 'size' => $file['size']]]);

        case 'upload_discard':
            $up = db_one('SELECT * FROM board_uploads WHERE id = ? AND board_id = ? AND user_id = ?', [(int)post('upload_id'), $boardId, $uid]);
            if ($up) { delete_upload($up['path']); db_exec('DELETE FROM board_uploads WHERE id = ?', [(int)$up['id']]); }
            board_json(['ok' => true]);

        case 'post_save':
            if (!board_can($role, 'post')) board_fail('คุณดูกระดานนี้ได้อย่างเดียว', 403);
            if (!$open) board_fail($window === 'upcoming' ? 'กระดานยังไม่เปิดรับคำตอบ' : 'กระดานปิดรับคำตอบแล้ว แก้ไขหรือส่งเพิ่มไม่ได้', 409);
            $title = trim((string)post('title'));
            $body = trim((string)post('body'));
            if (mb_strlen($title) > 200) board_fail('หัวข้อยาวได้ไม่เกิน 200 ตัวอักษร');
            if (mb_strlen($body) > 10000) board_fail('คำตอบยาวได้ไม่เกิน 10,000 ตัวอักษร');
            $link = assignment_link(post('link_url'));
            if (strlen($link) > 1000) board_fail('ลิงก์ยาวเกินไป');
            $uploadIds = array_values(array_unique(array_filter(array_map('intval', (array)post('uploads', [])))));
            $uploads = $uploadIds ? db_all('SELECT * FROM board_uploads WHERE board_id = ? AND user_id = ? AND id IN (' . implode(',', $uploadIds) . ')', [$boardId, $uid]) : [];
            if (count($uploads) !== count($uploadIds)) board_fail('ไฟล์บางไฟล์หมดอายุหรือไม่ใช่ของคุณ กรุณาอัปโหลดใหม่', 409);
            $sectionId = (int)post('section_id') ?: null;
            if ($sectionId && !db_value('SELECT 1 FROM board_sections WHERE id = ? AND board_id = ?', [$sectionId, $boardId])) $sectionId = null;
            $postId = (int)post('post_id');
            // 1 คน 1 คำตอบ: ส่งใหม่ = แก้คำตอบเดิม
            if (!$postId && $board['one_answer'] && !board_can($role, 'moderate')) {
                $postId = (int)db_value('SELECT id FROM board_posts WHERE board_id = ? AND user_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1', [$boardId, $uid]);
            }
            $old = $postId ? board_find_post($boardId, $postId) : null;
            if ($old && (int)$old['user_id'] !== $uid && !$canModerate) board_fail('แก้ไขได้เฉพาะโพสต์ของตนเอง', 403);
            $removeIds = array_map('intval', (array)post('remove_attachments', []));
            $keep = $old ? (int)db_value('SELECT COUNT(*) FROM board_attachments WHERE post_id = ?' . ($removeIds ? ' AND id NOT IN (' . implode(',', $removeIds) . ')' : ''), [$postId]) : 0;
            if ($keep + count($uploads) > BOARD_MAX_FILES) board_fail('แนบไฟล์ได้สูงสุด ' . BOARD_MAX_FILES . ' ไฟล์ต่อโพสต์');
            if ($title === '' && $body === '' && $link === '' && !$uploads && !$keep) board_fail('กรุณาพิมพ์คำตอบ ใส่ลิงก์ หรือแนบไฟล์อย่างน้อยหนึ่งอย่าง');
            $owner = $old ? (int)$old['user_id'] : $uid;
            $status = $board['require_approval'] && !board_can($role, 'moderate') ? 'pending' : 'approved';
            if ($old && $canModerate && (int)$old['user_id'] !== $uid) $status = $old['status'];
            $lat = post('lat') !== '' && is_numeric(post('lat')) ? max(-90, min(90, (float)post('lat'))) : null;
            $lng = post('lng') !== '' && is_numeric(post('lng')) ? max(-180, min(180, (float)post('lng'))) : null;
            $conn->begin_transaction();
            try {
                if ($old) {
                    db_exec('INSERT INTO board_post_revisions (post_id,title,body,link_url,edited_by) VALUES (?,?,?,?,?)', [$postId, $old['title'], $old['body'], $old['link_url'], $uid]);
                    $late = $old['status'] === 'rejected' ? ($window === 'late' ? 1 : 0) : (int)$old['is_late'];
                    db_exec('UPDATE board_posts SET title=?, body=?, link_url=?, section_id=COALESCE(?, section_id), status=?, reject_note=IF(?=\'rejected\', reject_note, NULL), is_late=?, lat=COALESCE(?, lat), lng=COALESCE(?, lng), updated_at=NOW() WHERE id=?',
                        [$title, $body ?: null, $link ?: null, $sectionId, $status, $status, $late, $lat, $lng, $postId]);
                    if ($removeIds) {
                        foreach (db_all('SELECT * FROM board_attachments WHERE post_id = ? AND id IN (' . implode(',', $removeIds) . ')', [$postId]) as $a) {
                            delete_upload($a['path']);
                            db_exec('DELETE FROM board_attachments WHERE id = ?', [(int)$a['id']]);
                        }
                    }
                } else {
                    $team = $board['group_mode'] === 'teams' ? board_user_team($boardId, $uid) : null;
                    $postId = db_exec('INSERT INTO board_posts (board_id,user_id,team_id,section_id,title,body,link_url,status,is_late,lat,lng,pos_x,pos_y) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        [$boardId, $uid, $team, $sectionId, $title, $body ?: null, $link ?: null, $status, $window === 'late' ? 1 : 0, $lat, $lng,
                            is_numeric(post('pos_x')) ? (int)post('pos_x') : null, is_numeric(post('pos_y')) ? (int)post('pos_y') : null]);
                }
                $order = (int)db_value('SELECT COALESCE(MAX(sort_order),0) FROM board_attachments WHERE post_id = ?', [$postId]);
                foreach ($uploads as $u) {
                    db_exec('INSERT INTO board_attachments (post_id,kind,path,original_name,size,sort_order) VALUES (?,?,?,?,?,?)', [$postId, $u['kind'], $u['path'], $u['original_name'], $u['size'], ++$order]);
                    db_exec('DELETE FROM board_uploads WHERE id = ?', [(int)$u['id']]);
                }
                // ส่งงานแก้ไขแล้ว: ล้างสถานะ "ครูให้แก้ไข" รอครูตรวจใหม่
                if ($owner === $uid) db_exec("UPDATE board_scores SET status = 'graded', score = NULL, version = version + 1 WHERE board_id = ? AND user_id = ? AND status = 'revision'", [$boardId, $uid]);
                board_log($boardId, $uid, $postId, $old ? 'edit' : 'post', $title ?: mb_substr($body, 0, 80));
                $conn->commit();
            } catch (Throwable $ex) {
                $conn->rollback();
                throw $ex;
            }
            $saved = db_one('SELECT * FROM board_posts WHERE id = ?', [$postId]);
            if ($status === 'approved') xp_for_post($board, $saved);
            board_done($board, ['post_id' => $postId, 'status' => $status, 'edited' => (bool)$old,
                'message' => $status === 'pending' ? 'ส่งแล้ว รอครูอนุมัติก่อนแสดงบนกระดาน' : ($old ? 'บันทึกการแก้ไขแล้ว' : ($window === 'late' ? 'ส่งแล้ว (ส่งเกินเวลา)' : 'ส่งคำตอบแล้ว'))]);

        case 'post_delete':
            $post = board_find_post($boardId, (int)post('post_id'));
            $own = (int)$post['user_id'] === $uid;
            if (!($own && $open) && !$canModerate) board_fail('ลบโพสต์นี้ไม่ได้', 403);
            db_exec('UPDATE board_posts SET deleted_at = NOW() WHERE id = ?', [(int)$post['id']]);
            xp_unpost($board, $post);
            board_log($boardId, $uid, (int)$post['id'], 'delete', $own ? '' : 'ลบโดยผู้ดูแล');
            board_done($board);

        case 'post_move':
            $post = board_find_post($boardId, (int)post('post_id'));
            if ((int)$post['user_id'] !== $uid && !$canModerate) board_fail('ย้ายได้เฉพาะโพสต์ของตนเอง', 403);
            $sectionId = post('section_id') !== '' ? (int)post('section_id') : null;
            if ($sectionId && !db_value('SELECT 1 FROM board_sections WHERE id = ? AND board_id = ?', [$sectionId, $boardId])) board_fail('ไม่พบคอลัมน์');
            db_exec('UPDATE board_posts SET pos_x = COALESCE(?, pos_x), pos_y = COALESCE(?, pos_y), lat = COALESCE(?, lat), lng = COALESCE(?, lng), section_id = IF(? = 1, ?, section_id) WHERE id = ?', [
                is_numeric(post('x')) ? max(0, min(20000, (int)post('x'))) : null, is_numeric(post('y')) ? max(0, min(20000, (int)post('y'))) : null,
                is_numeric(post('lat')) ? max(-90, min(90, (float)post('lat'))) : null, is_numeric(post('lng')) ? max(-180, min(180, (float)post('lng'))) : null,
                post('section_id') !== '' ? 1 : 0, $sectionId, (int)$post['id']]);
            board_done($board);

        case 'approve':
        case 'reject':
            if (!$canModerate) board_fail('เฉพาะครูหรือผู้ดูแลกระดาน', 403);
            $post = board_find_post($boardId, (int)post('post_id'));
            $note = mb_substr(trim((string)post('note')), 0, 500);
            if ($action === 'reject' && $note === '') board_fail('กรุณาระบุเหตุผลหรือสิ่งที่ต้องแก้ไข');
            db_exec('UPDATE board_posts SET status = ?, reject_note = ? WHERE id = ?', [$action === 'approve' ? 'approved' : 'rejected', $action === 'reject' ? $note : null, (int)$post['id']]);
            $post = db_one('SELECT * FROM board_posts WHERE id = ?', [(int)$post['id']]);
            $action === 'approve' ? xp_for_post($board, $post) : xp_unpost($board, $post);
            board_log($boardId, $uid, (int)$post['id'], $action, $note);
            board_done($board);

        case 'pin':
            if (!$canModerate) board_fail('เฉพาะครูหรือผู้ดูแลกระดาน', 403);
            $post = board_find_post($boardId, (int)post('post_id'));
            db_exec('UPDATE board_posts SET is_pinned = 1 - is_pinned WHERE id = ?', [(int)$post['id']]);
            board_log($boardId, $uid, (int)$post['id'], 'pin');
            board_done($board);

        case 'comment_add':
            if (!board_can($role, 'comment') || $board['status'] !== 'open' || (!$board['allow_comments'] && !$canModerate)) board_fail('กระดานนี้ไม่เปิดให้แสดงความคิดเห็น', 403);
            $post = board_find_post($boardId, (int)post('post_id'));
            if (!board_can_view_post($board, $role, $uid, (int)$post['id'])) board_fail('ไม่พบโพสต์', 404);
            $text = trim((string)post('body'));
            if ($text === '' || mb_strlen($text) > 2000) board_fail('ความคิดเห็นต้องมี 1–2,000 ตัวอักษร');
            $commentId = db_exec('INSERT INTO board_comments (post_id,user_id,body) VALUES (?,?,?)', [(int)$post['id'], $uid, $text]);
            if ((int)$post['user_id'] !== $uid && (int)db_value("SELECT COUNT(*) FROM xp_events WHERE board_id = ? AND user_id = ? AND source = 'comment' AND created_at >= CURDATE()", [$boardId, $uid]) < XP_RULES['comment_cap_daily']) {
                xp_award($board, $uid, 'comment', 'comment:' . $commentId, XP_RULES['comment']);
            }
            board_log($boardId, $uid, (int)$post['id'], 'comment', mb_substr($text, 0, 80));
            board_done($board, ['comment_id' => $commentId]);

        case 'comment_delete':
            $comment = db_one('SELECT c.* FROM board_comments c JOIN board_posts p ON p.id = c.post_id WHERE c.id = ? AND p.board_id = ? AND c.deleted_at IS NULL', [(int)post('comment_id'), $boardId]);
            if (!$comment) board_fail('ไม่พบความคิดเห็น', 404);
            if ((int)$comment['user_id'] !== $uid && !$canModerate) board_fail('ลบได้เฉพาะความคิดเห็นของตนเอง', 403);
            db_exec('UPDATE board_comments SET deleted_at = NOW() WHERE id = ?', [(int)$comment['id']]);
            xp_revoke($board, (int)$comment['user_id'], 'comment', 'comment:' . $comment['id']);
            board_log($boardId, $uid, (int)$comment['post_id'], 'comment_delete');
            board_done($board);

        case 'react':
            if (!board_can($role, 'react') || $board['reaction_mode'] === 'none' || $board['status'] !== 'open') board_fail('กระดานนี้ไม่เปิดให้กดถูกใจหรือให้คะแนน', 403);
            $post = board_find_post($boardId, (int)post('post_id'));
            if (!board_can_view_post($board, $role, $uid, (int)$post['id']) || $post['status'] !== 'approved') board_fail('ไม่พบโพสต์', 404);
            if ((int)$post['user_id'] === $uid) board_fail('กดให้โพสต์ของตนเองไม่ได้');
            $value = (int)post('value');
            $value = $board['reaction_mode'] === 'star' ? max(0, min(5, $value)) : ($value ? 1 : 0);
            $ref = 'react:' . $post['id'] . ':' . $uid;
            if ($value) {
                db_exec('INSERT INTO board_reactions (post_id,user_id,value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [(int)$post['id'], $uid, $value]);
                if ((int)db_value("SELECT COUNT(*) FROM xp_events WHERE course_id = ? AND source = 'reaction' AND ref LIKE ?", [(int)$board['course_id'], 'react:' . (int)$post['id'] . ':%']) < XP_RULES['reaction_cap']) {
                    xp_award($board, (int)$post['user_id'], 'reaction', $ref, XP_RULES['reaction']);
                }
            } else {
                db_exec('DELETE FROM board_reactions WHERE post_id = ? AND user_id = ?', [(int)$post['id'], $uid]);
                xp_revoke($board, (int)$post['user_id'], 'reaction', $ref);
            }
            board_log($boardId, $uid, (int)$post['id'], 'react', (string)$value);
            board_done($board);

        case 'award_star':
            if (!board_can($role, 'manage')) board_fail('เฉพาะครูผู้สอนมอบดาวได้', 403);
            $amount = max(1, min(5, (int)post('amount', 1)));
            $students = "SELECT DISTINCT p.user_id FROM board_posts p JOIN users u ON u.id = p.user_id WHERE p.board_id = ? AND p.deleted_at IS NULL AND p.status = 'approved' AND u.role = 'student'";
            if (post('all')) {
                // ทุกคนที่ตอบบนกระดานนี้
                $targets = array_map('intval', array_column(db_all($students, [$boardId]), 'user_id'));
            } else {
                // เลือกหลายคน: ส่ง post_ids[] (หรือ post_id เดียว) → ผู้เขียนแต่ละคนได้ดาวคนละครั้ง
                $postIds = array_values(array_unique(array_filter(array_map('intval', array_merge((array)post('post_ids', []), [(int)post('post_id')])))));
                if (!$postIds || count($postIds) > 500) board_fail('กรุณาเลือกงานที่จะมอบดาว');
                $targets = array_map('intval', array_column(db_all($students . ' AND p.id IN (' . implode(',', $postIds) . ')', [$boardId]), 'user_id'));
            }
            if (!$targets) board_fail('ยังไม่มีนักเรียนให้มอบดาว');
            $conn->begin_transaction();
            foreach ($targets as $target) for ($i = 0; $i < $amount; $i++) star_give($board, $target, $uid);
            board_log($boardId, $uid, null, 'star', count($targets) . ' คน × ' . $amount . ' ดาว');
            $conn->commit();
            board_done($board, ['message' => 'มอบ ' . $amount . ' ดาวให้ ' . count($targets) . ' คนแล้ว ⭐', 'awarded' => count($targets)]);

        case 'reveal':
        case 'hide':
            if (!$canModerate) board_fail('เฉพาะครูหรือผู้ดูแลกระดาน', 403);
            db_exec('UPDATE boards SET revealed_at = ' . ($action === 'reveal' ? 'NOW()' : 'NULL') . ' WHERE id = ?', [$boardId]);
            board_log($boardId, $uid, null, $action);
            board_done($board);

        case 'set_status':
            if (!board_can($role, 'manage')) board_fail('เฉพาะครูผู้สอน', 403);
            $status = (string)post('status');
            if (!in_array($status, ['open', 'closed', 'archived'], true)) board_fail('สถานะไม่ถูกต้อง');
            db_exec('UPDATE boards SET status = ? WHERE id = ?', [$status, $boardId]);
            board_log($boardId, $uid, null, 'status', $status);
            board_done($board);
    }
} catch (RuntimeException $ex) {
    board_fail($ex->getMessage());
}
board_fail('คำสั่งไม่ถูกต้อง');
