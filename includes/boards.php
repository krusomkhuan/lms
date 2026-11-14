<?php
// กระดานกิจกรรม (Collaborative Board): สิทธิ์ การมองเห็นโพสต์ สถานะการส่งงาน ไฟล์แนบ และ XP/Level รายวิชา

const BOARD_LAYOUTS = [
    'wall' => ['Wall', 'bi-bricks', 'การ์ดเรียงต่อกันแบบผนัง'],
    'grid' => ['Grid', 'bi-grid-3x3-gap', 'การ์ดขนาดเท่ากันเป็นตาราง'],
    'columns' => ['Columns', 'bi-layout-three-columns', 'แยกเป็นคอลัมน์ตามหัวข้อ'],
    'stream' => ['Stream', 'bi-list-ul', 'เรียงลงมาทีละโพสต์'],
    'timeline' => ['Timeline', 'bi-clock-history', 'เรียงตามเวลาที่โพสต์'],
    'table' => ['Table', 'bi-table', 'ตารางสรุปคำตอบ'],
    'freeform' => ['Freeform', 'bi-arrows-move', 'ลากวางการ์ดได้อิสระ'],
    'map' => ['Map', 'bi-geo-alt', 'ปักหมุดโพสต์บนแผนที่'],
    'wordcloud' => ['Word Cloud', 'bi-cloud', 'คำตอบที่ซ้ำกันมากตัวใหญ่ขึ้น'],
];
const BOARD_ROLES = ['manager' => 'ครูผู้สอน', 'moderator' => 'ผู้ดูแลกระดาน', 'contributor' => 'ผู้เรียน (โพสต์ได้)', 'commenter' => 'ผู้แสดงความคิดเห็น', 'viewer' => 'ผู้ชม'];
const BOARD_FILE_KINDS = [
    'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    'video' => ['mp4', 'webm', 'mov', 'ogv', 'm4v'],
    'audio' => ['mp3', 'wav', 'm4a', 'ogg', 'oga', 'weba', 'aac'],
    'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'csv', 'zip', 'rar'],
];
const BOARD_MIME = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'ogv' => 'video/ogg',
    'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'weba' => 'audio/webm',
    'pdf' => 'application/pdf',
];
const BOARD_MAX_FILES = 5;

/** เทมเพลตกิจกรรม Active Learning: ตั้งรูปแบบ คอลัมน์ และค่าตั้งต้นให้ (ครูแก้ต่อได้) */
function board_templates(): array
{
    return [
        '' => ['label' => 'กระดานเปล่า', 'icon' => 'bi-square', 'hint' => 'เริ่มจากกระดานว่าง ตั้งค่าเอง', 'set' => []],
        'wordcloud' => ['label' => 'ถาม–ตอบ Word Cloud', 'icon' => 'bi-cloud', 'hint' => 'ครูถาม นักเรียนตอบสั้น ๆ คำตอบที่ตรงกันมากตัวยิ่งใหญ่', 'set' => ['layout' => 'wordcloud', 'reaction_mode' => 'none', 'allow_comments' => 0]],
        'brainstorm' => ['label' => 'ระดมสมอง', 'icon' => 'bi-lightbulb', 'hint' => 'ทุกคนเสนอไอเดีย ให้ Vote ไอเดียที่ชอบ', 'set' => ['layout' => 'wall', 'reaction_mode' => 'vote']],
        'kwl' => ['label' => 'KWL', 'icon' => 'bi-layout-three-columns', 'hint' => 'รู้อะไรแล้ว / อยากรู้อะไร / ได้เรียนรู้อะไร', 'set' => ['layout' => 'columns'], 'sections' => ['K – สิ่งที่รู้แล้ว', 'W – สิ่งที่อยากรู้', 'L – สิ่งที่ได้เรียนรู้']],
        'think_pair_share' => ['label' => 'Think-Pair-Share', 'icon' => 'bi-people', 'hint' => 'คิดเดี่ยว → จับคู่แลกเปลี่ยน → แบ่งปันทั้งห้อง', 'set' => ['layout' => 'columns', 'group_mode' => 'teams'], 'sections' => ['Think – คิดคนเดียว', 'Pair – คุยกับคู่', 'Share – ข้อสรุปของคู่']],
        'gallery_walk' => ['label' => 'Gallery Walk', 'icon' => 'bi-images', 'hint' => 'ส่งผลงานก่อน ครูเปิดให้ชมแล้วให้ดาว', 'set' => ['layout' => 'grid', 'hide_until_reveal' => 1, 'reaction_mode' => 'star', 'is_assignment' => 1, 'one_answer' => 1]],
        'exit_ticket' => ['label' => 'Exit Ticket', 'icon' => 'bi-ticket-perforated', 'hint' => 'สรุปสั้น ๆ ท้ายคาบ ไม่เห็นคำตอบเพื่อน', 'set' => ['layout' => 'stream', 'hide_until_reveal' => 1, 'one_answer' => 1, 'reaction_mode' => 'none']],
        'assignment' => ['label' => 'ส่งผลงาน / ใบงาน', 'icon' => 'bi-journal-arrow-up', 'hint' => 'ส่งไฟล์ผลงาน 1 คน 1 ชิ้น ครูตรวจให้คะแนน', 'set' => ['layout' => 'grid', 'is_assignment' => 1, 'one_answer' => 1, 'hide_until_reveal' => 1, 'reaction_mode' => 'none']],
        'pros_cons' => ['label' => 'ข้อดี / ข้อเสีย', 'icon' => 'bi-plus-slash-minus', 'hint' => 'แยกความเห็นสองฝั่งเพื่ออภิปราย', 'set' => ['layout' => 'columns', 'reaction_mode' => 'like'], 'sections' => ['ข้อดี', 'ข้อเสีย', 'ข้อเสนอแนะ']],
        'jigsaw' => ['label' => 'Jigsaw', 'icon' => 'bi-puzzle', 'hint' => 'แต่ละทีมศึกษาคนละหัวข้อแล้วนำเสนอ', 'set' => ['layout' => 'columns', 'group_mode' => 'teams', 'team_visibility' => 'all']],
        'community_map' => ['label' => 'แผนที่ชุมชน', 'icon' => 'bi-geo-alt', 'hint' => 'ปักหมุดสถานที่จริง เช่น สถานประกอบการ', 'set' => ['layout' => 'map']],
    ];
}

function boards_ready(): bool
{
    static $ready;
    return $ready ??= (bool)db_value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'xp_events'");
}

function board_get(int $id): ?array
{
    return boards_ready() ? db_one('SELECT b.*, c.title AS course_title, c.code AS course_code, c.status AS course_status FROM boards b JOIN courses c ON c.id = b.course_id WHERE b.id = ?', [$id]) : null;
}

/** บทบาทของผู้ใช้ในกระดาน (null = ไม่มีสิทธิ์เข้า) */
function board_role(array $board, array $me): ?string
{
    $courseId = (int)$board['course_id'];
    if (can_manage_course($courseId)) return 'manager';
    // มุมมองผู้เรียนของครู: ดูได้อย่างเดียว
    if (student_preview() && staff_manages_course($courseId)) return 'viewer';
    if ($me['role'] !== 'student' || $board['course_status'] !== 'published' || !is_enrolled($courseId, (int)$me['id'])) return null;
    $role = db_value('SELECT role FROM board_members WHERE board_id = ? AND user_id = ?', [(int)$board['id'], (int)$me['id']]);
    return $role ?: $board['student_role'];
}

function board_can(string $role, string $ability): bool
{
    $rank = ['viewer' => 0, 'commenter' => 1, 'contributor' => 2, 'moderator' => 3, 'manager' => 4][$role] ?? -1;
    return match ($ability) {
        'react', 'comment' => $rank >= 1,
        'post' => $rank >= 2,
        'moderate' => $rank >= 3,
        'manage', 'grade' => $rank >= 4,
        default => $rank >= 0,
    };
}

/** ช่วงเวลารับโพสต์: upcoming | open | late | closed */
function board_window(array $board, ?string $now = null): string
{
    $now ??= date('Y-m-d H:i:s');
    if ($board['status'] !== 'open') return 'closed';
    if (!empty($board['opens_at']) && $now < $board['opens_at']) return 'upcoming';
    if (!empty($board['closes_at']) && $now >= $board['closes_at']) return 'closed';
    if (!empty($board['due_at']) && $now > $board['due_at']) return $board['accept_late'] ? 'late' : 'closed';
    return 'open';
}

function board_window_label(array $board): string
{
    $parts = [];
    if (!empty($board['opens_at'])) $parts[] = 'เปิดรับ ' . thai_date($board['opens_at'], true);
    if (!empty($board['due_at'])) $parts[] = 'กำหนดส่ง ' . thai_date($board['due_at'], true);
    if (!empty($board['closes_at'])) $parts[] = 'ปิดรับ ' . thai_date($board['closes_at'], true);
    return implode(' · ', $parts);
}

function board_user_team(int $boardId, int $userId): ?int
{
    $team = db_value('SELECT team_id FROM board_team_members WHERE board_id = ? AND user_id = ?', [$boardId, $userId]);
    return $team ? (int)$team : null;
}

function board_revealed(array $board): bool
{
    return !$board['hide_until_reveal'] || !empty($board['revealed_at']);
}

/** เงื่อนไขโพสต์ที่ผู้ใช้เห็นได้: [sql, params] (ใช้ alias p) */
function board_post_filter(array $board, string $role, int $userId): array
{
    $sql = 'p.board_id = ? AND p.deleted_at IS NULL';
    $params = [(int)$board['id']];
    if (board_can($role, 'moderate')) return [$sql, $params];
    $others = "p.status = 'approved'";
    if (!board_revealed($board)) $others = '0';
    if ($board['group_mode'] === 'teams' && $board['team_visibility'] === 'own') {
        $team = board_user_team((int)$board['id'], $userId);
        $others .= $team ? ' AND p.team_id = ' . $team : ' AND 0';
    }
    return [$sql . " AND (p.user_id = ? OR ($others))", array_merge($params, [$userId])];
}

function board_can_view_post(array $board, string $role, int $userId, int $postId): bool
{
    [$where, $params] = board_post_filter($board, $role, $userId);
    return (bool)db_value("SELECT 1 FROM board_posts p WHERE p.id = ? AND $where", array_merge([$postId], $params));
}

function board_bump(int $boardId): void
{
    db_exec('UPDATE boards SET rev = rev + 1 WHERE id = ?', [$boardId]);
}

function board_log(int $boardId, ?int $userId, ?int $postId, string $action, string $detail = ''): void
{
    db_exec('INSERT INTO board_activity (board_id,user_id,post_id,action,detail) VALUES (?,?,?,?,?)', [$boardId, $userId, $postId, $action, mb_substr($detail, 0, 255)]);
}

const BOARD_ACTIONS = ['post' => 'โพสต์', 'edit' => 'แก้ไขโพสต์', 'delete' => 'ลบโพสต์', 'approve' => 'อนุมัติโพสต์', 'reject' => 'ไม่อนุมัติ / ให้แก้ไข', 'comment' => 'แสดงความคิดเห็น',
    'comment_delete' => 'ลบความคิดเห็น', 'react' => 'กดถูกใจ/โหวต/ให้ดาว', 'grade' => 'ให้คะแนน', 'reveal' => 'เปิดเฉลย/ให้เห็นทุกโพสต์', 'hide' => 'ซ่อนโพสต์ของเพื่อน', 'status' => 'เปลี่ยนสถานะกระดาน', 'pin' => 'ปักหมุดโพสต์', 'move' => 'ย้ายโพสต์', 'star' => 'มอบดาว'];

// ---------- ไฟล์แนบ ----------
function board_file_kind(string $ext): ?string
{
    foreach (BOARD_FILE_KINDS as $kind => $exts) if (in_array($ext, $exts, true)) return $kind;
    return null;
}

/** ขนาดไฟล์สูงสุดที่รับได้จริง (ค่าน้อยสุดของระบบและ PHP) เป็นไบต์ */
function board_max_upload_bytes(): int
{
    $toBytes = function ($v): int {
        $v = trim((string)$v);
        if ($v === '') return PHP_INT_MAX;
        $n = (float)$v;
        return (int)match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    };
    return (int)min(MAX_UPLOAD_MB * 1048576, $toBytes(ini_get('upload_max_filesize')), $toBytes(ini_get('post_max_size')));
}

function board_upload(array $file): array
{
    if (!is_string($file['name'] ?? null) || !is_string($file['tmp_name'] ?? null)) throw new RuntimeException('ไม่พบไฟล์ที่อัปโหลด');
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับ (สูงสุด ' . round(board_max_upload_bytes() / 1048576) . ' MB)');
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่ (รหัส ' . (int)$file['error'] . ')');
    if ($file['size'] > board_max_upload_bytes()) throw new RuntimeException('ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับ (สูงสุด ' . round(board_max_upload_bytes() / 1048576) . ' MB)');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $kind = board_file_kind($ext);
    if (!$kind) throw new RuntimeException('ไม่รองรับไฟล์ .' . $ext . ' (รองรับรูปภาพ วิดีโอ เสียง PDF Word Excel PowerPoint และ zip)');
    $dir = UPLOAD_PATH . '/boards';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('สร้างโฟลเดอร์ uploads/boards ไม่ได้ กรุณาแจ้งผู้ดูแลระบบ');
    // ไม่เปิดไฟล์ให้เข้าถึงตรง: ต้องมี .htaccess ปิดการเข้าถึงก่อนรับไฟล์
    if (!is_file($dir . '/.htaccess') || !str_contains((string)file_get_contents($dir . '/.htaccess'), 'Require all denied')) throw new RuntimeException('ที่เก็บไฟล์กระดานยังไม่พร้อม (ขาด uploads/boards/.htaccess) กรุณาแจ้งผู้ดูแลระบบ');
    $name = bin2hex(random_bytes(24)) . '.' . $ext;
    $moved = @move_uploaded_file($file['tmp_name'], $dir . '/' . $name);
    if (!$moved && upload_tmp_file_ok((string)$file['tmp_name'])) $moved = @copy($file['tmp_name'], $dir . '/' . $name);
    if (!$moved) throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ พื้นที่อาจเต็มหรือโฟลเดอร์ไม่มีสิทธิ์เขียน');
    return ['path' => 'boards/' . $name, 'kind' => $kind, 'size' => (int)$file['size'], 'name' => mb_substr(basename(str_replace('\\', '/', $file['name'])), 0, 255)];
}

/** ลบไฟล์ที่อัปขึ้นมาแต่ไม่ได้โพสต์เกิน 24 ชั่วโมง */
function board_cleanup_uploads(): void
{
    foreach (db_all('SELECT id, path FROM board_uploads WHERE created_at < NOW() - INTERVAL 1 DAY LIMIT 50') as $u) {
        delete_upload($u['path']);
        db_exec('DELETE FROM board_uploads WHERE id = ?', [(int)$u['id']]);
    }
}

/** path ไฟล์ทั้งหมดของกระดานตามเงื่อนไข (ใช้ลบไฟล์เมื่อลบกระดาน/รายวิชา/ผู้ใช้) */
function board_files_where(string $where, array $params): array
{
    if (!boards_ready()) return [];
    $a = array_column(db_all("SELECT a.path FROM board_attachments a JOIN board_posts p ON p.id = a.post_id JOIN boards b ON b.id = p.board_id WHERE $where", $params), 'path');
    $u = array_column(db_all("SELECT u.path FROM board_uploads u JOIN boards b ON b.id = u.board_id WHERE " . str_replace('p.user_id', 'u.user_id', $where), $params), 'path');
    return array_merge($a, $u);
}

function link_preview(string $url): array
{
    $yt = youtube_id($url);
    return ['url' => $url, 'youtube' => $yt, 'host' => (string)parse_url($url, PHP_URL_HOST)];
}

// ---------- การส่งงานและคะแนน ----------
/** สถานะการส่งรายคน [user_id => ['state' => submitted|late|missing|revision|graded, 'first' => datetime, 'posts' => n, 'score' => ..., 'feedback' => ...]] */
function board_submissions(array $board, array $roster): array
{
    $posts = [];
    foreach (db_all("SELECT user_id, MIN(created_at) AS first_at, MAX(is_late) AS late, COUNT(*) AS n, MAX(COALESCE(updated_at, created_at)) AS last_at
                     FROM board_posts WHERE board_id = ? AND deleted_at IS NULL AND status <> 'rejected' GROUP BY user_id", [(int)$board['id']]) as $r) $posts[(int)$r['user_id']] = $r;
    $rejected = array_flip(array_map('intval', array_column(db_all("SELECT DISTINCT user_id FROM board_posts WHERE board_id = ? AND deleted_at IS NULL AND status = 'rejected'", [(int)$board['id']]), 'user_id')));
    $scores = array_column(db_all('SELECT * FROM board_scores WHERE board_id = ?', [(int)$board['id']]), null, 'user_id');
    $out = [];
    foreach ($roster as $s) {
        $uid = (int)$s['id'];
        $p = $posts[$uid] ?? null;
        $score = $scores[$uid] ?? null;
        $state = $p ? ($p['late'] ? 'late' : 'submitted') : (isset($rejected[$uid]) ? 'revision' : 'missing');
        if ($score && $score['status'] === 'revision') $state = 'revision';
        elseif ($score && $score['score'] !== null && $p) $state = 'graded';
        $out[$uid] = ['state' => $state, 'first' => $p['first_at'] ?? null, 'last' => $p['last_at'] ?? null, 'posts' => (int)($p['n'] ?? 0), 'late' => (bool)($p['late'] ?? false),
            'score' => $score['score'] ?? null, 'feedback' => $score['feedback'] ?? '', 'version' => (int)($score['version'] ?? 0), 'graded_at' => $score['graded_at'] ?? null];
    }
    return $out;
}

const BOARD_SUBMIT_STATES = ['submitted' => ['ส่งแล้ว', 'success'], 'late' => ['ส่งเกินเวลา', 'warning'], 'missing' => ['ยังไม่ส่ง', 'secondary'], 'revision' => ['ครูให้แก้ไข', 'danger'], 'graded' => ['ตรวจแล้ว', 'primary']];

// ---------- ดาว ⭐ / Level / เหรียญรางวัล รายวิชา (จากการมีส่วนร่วมบนกระดาน) ----------
// เก็บในตาราง xp_events (points = จำนวนดาว)
const XP_RULES = ['post' => 2, 'post_cap' => 3, 'comment' => 1, 'comment_cap_daily' => 5, 'reaction' => 1, 'reaction_cap' => 10, 'ontime' => 1, 'grade' => 5, 'star' => 1];
const STAR_SOURCES = ['post' => 'โพสต์บนกระดาน', 'comment' => 'แสดงความคิดเห็นให้เพื่อน', 'reaction' => 'เพื่อนกดถูกใจ/โหวต/ให้ดาว', 'ontime' => 'ส่งใบงานทันเวลา', 'grade' => 'คะแนนใบงานจากครู', 'star' => 'ครูมอบดาว'];

/** เหรียญรางวัล: [key => [ชื่อ, ไอคอน, สี, เงื่อนไข, ฟังก์ชันตรวจจากสถิติ]] */
function star_badges(): array
{
    return [
        'first_post' => ['โพสต์แรก', 'bi-rocket-takeoff', '#2563eb', 'โพสต์บนกระดานครั้งแรก', fn($s) => ($s['post'] ?? 0) >= 1],
        'helper' => ['เพื่อนช่วยเพื่อน', 'bi-chat-heart', '#db2777', 'แสดงความคิดเห็นให้เพื่อน 10 ครั้ง', fn($s) => ($s['comment'] ?? 0) >= 10],
        'liked' => ['ไอเดียโดนใจ', 'bi-heart-fill', '#e11d48', 'ได้รับถูกใจ/โหวตจากเพื่อน 10 ครั้ง', fn($s) => ($s['reaction'] ?? 0) >= 10],
        'punctual' => ['ตรงต่อเวลา', 'bi-alarm', '#059669', 'ส่งใบงานทันเวลา 3 ครั้ง', fn($s) => ($s['ontime'] ?? 0) >= 3],
        'teacher_star' => ['ขวัญใจครู', 'bi-star-fill', '#d97706', 'ได้รับดาวจากครู 5 ดวง', fn($s) => ($s['star_points'] ?? 0) >= 5],
        'excellent' => ['ผลงานยอดเยี่ยม', 'bi-award-fill', '#7c3aed', 'ได้คะแนนใบงานเต็มหรือเกือบเต็ม', fn($s) => ($s['grade_max'] ?? 0) >= XP_RULES['grade']],
        'rising' => ['ดาวรุ่ง', 'bi-stars', '#0891b2', 'ถึง Level 5', fn($s) => star_level((int)($s['total'] ?? 0))['level'] >= 5],
    ];
}

function xp_award(array $board, int $userId, string $source, string $ref, int $points): void
{
    if ($points <= 0 || db_value("SELECT role FROM users WHERE id = ?", [$userId]) !== 'student') return;
    db_exec('INSERT IGNORE INTO xp_events (course_id,user_id,board_id,lesson_id,source,ref,points) VALUES (?,?,?,?,?,?,?)',
        [(int)$board['course_id'], $userId, (int)$board['id'], $board['lesson_id'] ? (int)$board['lesson_id'] : null, $source, $ref, $points]);
}

function xp_revoke(array $board, int $userId, string $source, string $ref): void
{
    db_exec('DELETE FROM xp_events WHERE course_id = ? AND user_id = ? AND source = ? AND ref = ?', [(int)$board['course_id'], $userId, $source, $ref]);
}

/** ให้ดาวเมื่อโพสต์ถูกเผยแพร่ (นับสูงสุด 3 โพสต์ต่อกระดาน) และส่งทันกำหนด */
function xp_for_post(array $board, array $post): void
{
    if ($post['status'] !== 'approved' || $post['deleted_at']) return;
    $uid = (int)$post['user_id'];
    $count = (int)db_value("SELECT COUNT(*) FROM xp_events WHERE board_id = ? AND user_id = ? AND source = 'post'", [(int)$board['id'], $uid]);
    if ($count < XP_RULES['post_cap']) xp_award($board, $uid, 'post', 'post:' . $post['id'], XP_RULES['post']);
    if ($board['is_assignment'] && !$post['is_late']) xp_award($board, $uid, 'ontime', 'board:' . $board['id'], XP_RULES['ontime']);
}

function xp_unpost(array $board, array $post): void
{
    $uid = (int)$post['user_id'];
    xp_revoke($board, $uid, 'post', 'post:' . $post['id']);
    if (!db_value("SELECT 1 FROM board_posts WHERE board_id = ? AND user_id = ? AND deleted_at IS NULL AND status = 'approved'", [(int)$board['id'], $uid])) {
        xp_revoke($board, $uid, 'ontime', 'board:' . $board['id']);
    }
    db_exec("DELETE FROM xp_events WHERE course_id = ? AND source = 'reaction' AND ref LIKE ?", [(int)$board['course_id'], 'react:' . (int)$post['id'] . ':%']);
}

/** ครูมอบดาวให้นักเรียน (ทีละคนหรือทั้งห้อง) */
function star_give(array $board, int $userId, int $giverId, ?int $postId = null): void
{
    xp_award($board, $userId, 'star', 'award:' . $giverId . ':' . bin2hex(random_bytes(8)), XP_RULES['star']);
}

function xp_total(int $courseId, int $userId): int
{
    return boards_ready() ? (int)db_value('SELECT COALESCE(SUM(points),0) FROM xp_events WHERE course_id = ? AND user_id = ?', [$courseId, $userId]) : 0;
}

/** Level จากจำนวนดาว: Lv2 = 5 ดาว, Lv3 = 15, Lv4 = 30, Lv5 = 50 ... (ต้องเพิ่มทีละ 5, 10, 15, ...) */
function star_level(int $stars): array
{
    $stars = max(0, $stars);
    $level = (int)floor((1 + sqrt(1 + 8 * $stars / 5)) / 2);
    $from = (int)(5 * $level * ($level - 1) / 2);
    $to = (int)(5 * ($level + 1) * $level / 2);
    return ['xp' => $stars, 'stars' => $stars, 'level' => $level, 'from' => $from, 'to' => $to, 'percent' => (int)round(($stars - $from) * 100 / max(1, $to - $from))];
}

function xp_level(int $xp): array
{
    return star_level($xp);
}

/** สถิติดาวรายคนของรายวิชา [user_id => ['total', 'post' (ครั้ง), 'comment', 'reaction', 'ontime', 'star_points', 'grade_max']] */
function star_stats(int $courseId, array $userIds = []): array
{
    if (!boards_ready()) return [];
    $filter = $userIds ? ' AND user_id IN (' . implode(',', array_map('intval', $userIds)) . ')' : '';
    $stats = [];
    foreach (db_all("SELECT user_id, source, COUNT(*) AS n, SUM(points) AS pts, MAX(points) AS top FROM xp_events WHERE course_id = ?$filter GROUP BY user_id, source", [$courseId]) as $r) {
        $s = &$stats[(int)$r['user_id']];
        $s['total'] = ($s['total'] ?? 0) + (int)$r['pts'];
        $s[$r['source']] = (int)$r['n'];
        if ($r['source'] === 'star') $s['star_points'] = (int)$r['pts'];
        if ($r['source'] === 'grade') $s['grade_max'] = (int)$r['top'];
        unset($s);
    }
    return $stats;
}

/** เหรียญที่ได้แล้ว [key => badge] */
function star_earned(array $stat): array
{
    return array_filter(star_badges(), fn($b) => $b[4]($stat));
}

function xp_leaderboard(int $courseId): array
{
    return db_all("SELECT u.id, u.fullname, u.student_code, u.avatar, COALESCE(SUM(x.points),0) AS xp FROM users u
                   JOIN xp_events x ON x.user_id = u.id AND x.course_id = ? WHERE u.role = 'student' GROUP BY u.id ORDER BY xp DESC, u.fullname LIMIT 100", [$courseId]);
}

/** คะแนนใบงานจากครู → ดาวตามสัดส่วน (เต็ม = 5 ดาว) */
function board_grade_xp(array $board, int $userId, ?string $score): void
{
    xp_revoke($board, $userId, 'grade', 'board:' . $board['id']);
    if ($score !== null && (float)$board['max_points'] > 0) xp_award($board, $userId, 'grade', 'board:' . $board['id'], (int)round((float)$score / (float)$board['max_points'] * XP_RULES['grade']));
}

/** กระดานในรายวิชาที่ผู้ใช้เห็นได้ (ไม่รวมที่เก็บถาวร ยกเว้นครู) */
function course_boards(int $courseId, bool $canManage, ?int $lessonId = null): array
{
    if (!boards_ready()) return [];
    $sql = 'SELECT * FROM boards WHERE course_id = ?' . ($canManage ? '' : " AND status <> 'archived'") . ($lessonId !== null ? ' AND lesson_id = ?' : '') . ' ORDER BY (status = \'archived\'), created_at DESC';
    return db_all($sql, $lessonId !== null ? [$courseId, $lessonId] : [$courseId]);
}

/** ใบงานบนกระดานที่นักเรียนยังไม่ได้ส่ง หรือครูให้แก้ไข (ใช้ในกระดิ่งงานค้างส่ง) */
function board_pending_for(int $userId): array
{
    if (!boards_ready()) return [];
    $items = [];
    foreach (db_all("SELECT b.*, c.title AS course_title, c.status AS course_status,
                       (SELECT status FROM board_scores s WHERE s.board_id = b.id AND s.user_id = ?) AS score_status,
                       EXISTS (SELECT 1 FROM board_posts p WHERE p.board_id = b.id AND p.user_id = ? AND p.deleted_at IS NULL AND p.status <> 'rejected') AS sent
                     FROM boards b JOIN courses c ON c.id = b.course_id
                     WHERE b.is_assignment = 1 AND b.status = 'open' AND c.status = 'published' AND b.course_id IN " . student_course_ids_sql(), [$userId, $userId, $userId, $userId]) as $b) {
        $revision = $b['score_status'] === 'revision';
        if ($b['sent'] && !$revision) continue;
        $window = board_window($b);
        if (!in_array($window, ['open', 'late'], true)) continue;
        $items[] = ['board' => $b, 'late' => $window === 'late', 'revision' => $revision];
    }
    return $items;
}

/** ใบงานบนกระดานที่เป็นรายการคะแนนในสมุดคะแนน [source_key => board] */
function board_grade_sources(int $courseId): array
{
    if (!boards_ready()) return [];
    $out = [];
    foreach (db_all('SELECT * FROM boards WHERE course_id = ? AND is_assignment = 1', [$courseId]) as $b) $out['board:' . $b['id'] . ':0'] = $b;
    return $out;
}

/** คะแนนใบงานบนกระดานในรูปแบบเดียวกับงานมอบหมาย [source_key:user_id => [attempt]] ส่งแล้วแต่ยังไม่ตรวจ = รอตรวจ */
function board_grade_scores(int $courseId): array
{
    if (!boards_ready()) return [];
    $scores = [];
    foreach (db_all("SELECT b.id AS board_id, b.max_points, p.user_id, s.score, s.status FROM boards b
                     JOIN (SELECT DISTINCT board_id, user_id FROM board_posts WHERE deleted_at IS NULL AND status <> 'rejected'
                           UNION SELECT board_id, user_id FROM board_scores) p ON p.board_id = b.id
                     LEFT JOIN board_scores s ON s.board_id = b.id AND s.user_id = p.user_id
                     WHERE b.course_id = ? AND b.is_assignment = 1", [$courseId]) as $r) {
        $status = $r['status'] === 'revision' ? 'revision' : ($r['score'] !== null ? 'graded' : 'submitted');
        $scores['board:' . $r['board_id'] . ':0:' . $r['user_id']][] = ['id' => 'b' . $r['board_id'] . '-' . $r['user_id'], 'score' => $r['score'], 'max' => $r['max_points'], 'status' => $status];
    }
    return $scores;
}
