<?php
// ---------- ทั่วไป ----------
function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** path ของเว็บจากตำแหน่งสคริปต์ที่กำลังรัน เช่น "/lms" หรือ "" ถ้าอยู่ที่ root ของโดเมน */
function detect_base_url(): string
{
    $root = str_replace('\\', '/', (string)realpath(ROOT_PATH));
    $script = str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $name = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($root !== '' && stripos($script, $root . '/') === 0) {
        $rel = substr($script, strlen($root));            // เช่น /admin/index.php
        if (strcasecmp(substr($name, -strlen($rel)), $rel) === 0) {
            return rtrim(substr($name, 0, -strlen($rel)), '/');
        }
    }
    return '';
}

/** URL เต็มที่ตรวจพบ (ใช้แสดงเป็นค่าแนะนำในหน้าตั้งค่า) */
function detected_full_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . detect_base_url();
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . (preg_match('#^(https?:)?/#', $path) ? $path : url($path)));
    exit;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function render_flash(): string
{
    $html = '';
    foreach ($_SESSION['flash'] ?? [] as $f) {
        $html .= '<div class="alert alert-' . e($f['type']) . ' alert-dismissible fade show">' . e($f['msg'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

function post(string $key, $default = '')
{
    return isset($_POST[$key]) ? (is_string($_POST[$key]) ? trim($_POST[$key]) : $_POST[$key]) : $default;
}

function get_int(string $key): int
{
    return isset($_GET[$key]) ? (int)$_GET[$key] : 0;
}

function thai_date(?string $datetime, bool $withTime = false): string
{
    if (!$datetime) return '-';
    $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $t = strtotime($datetime);
    $s = date('j', $t) . ' ' . $months[(int)date('n', $t)] . ' ' . (date('Y', $t) + 543);
    return $withTime ? $s . ' ' . date('H:i', $t) . ' น.' : $s;
}

// ---------- ฐานข้อมูล (mysqli prepared statements) ----------
function db_query(string $sql, array $params = [])
{
    global $conn;
    $stmt = $conn->prepare($sql);
    if ($params) {
        $types = '';
        foreach ($params as $p) {
            $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
        }
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

function db_all(string $sql, array $params = []): array
{
    $res = db_query($sql, $params)->get_result();
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function db_one(string $sql, array $params = []): ?array
{
    $res = db_query($sql, $params)->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    return $row ?: null;
}

function db_value(string $sql, array $params = [])
{
    $row = db_one($sql, $params);
    return $row ? reset($row) : null;
}

function db_exec(string $sql, array $params = []): int
{
    global $conn;
    $stmt = db_query($sql, $params);
    return $conn->insert_id ?: $stmt->affected_rows;
}

function setting(string $name, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db_all('SELECT name, value FROM settings') as $r) {
            $cache[$r['name']] = $r['value'];
        }
    }
    return $cache[$name] ?? $default;
}

// ---------- CSRF ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function verify_csrf(): void
{
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('คำขอไม่ถูกต้อง (CSRF) กรุณาย้อนกลับแล้วลองใหม่');
    }
}

// ---------- สมาชิก / สิทธิ์ ----------
function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = !empty($_SESSION['user_id'])
            ? db_one("SELECT * FROM users WHERE id = ? AND status = 'active'", [(int)$_SESSION['user_id']])
            : null;
    }
    return $user;
}

function is_role(string ...$roles): bool
{
    $u = current_user();
    return $u && in_array($u['role'], $roles, true);
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        flash('warning', 'กรุณาเข้าสู่ระบบก่อน');
        redirect('login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
    }
    return $u;
}

function require_role(string ...$roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    }
    return $u;
}

function role_label(string $role): string
{
    return ['admin' => 'ผู้ดูแลระบบ', 'teacher' => 'ครูผู้สอน', 'student' => 'ผู้เรียน'][$role] ?? $role;
}

function role_badge(string $role): string
{
    $c = ['admin' => 'danger', 'teacher' => 'primary', 'student' => 'success'][$role] ?? 'secondary';
    return '<span class="badge bg-' . $c . '">' . e(role_label($role)) . '</span>';
}

/** โหมด "มุมมองสำหรับผู้เรียน": admin/ครูดูหน้าบ้านแบบผู้เรียน (เฉพาะหน้าบ้าน ไม่มีผลกับหลังบ้าน) */
function student_preview(): bool
{
    $u = current_user();
    return $u && !empty($_SESSION['student_preview']) && in_array($u['role'], ['admin', 'teacher'], true)
        && basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) !== 'admin';
}

/** สิทธิ์จัดการรายวิชาจริงของ admin/ครู (ไม่สนโหมดมุมมองผู้เรียน) */
function staff_manages_course(int $courseId): bool
{
    $u = current_user();
    if (!$u) return false;
    if ($u['role'] === 'admin') return true;
    if ($u['role'] !== 'teacher') return false;
    return (bool)db_value('SELECT 1 FROM course_teachers WHERE course_id = ? AND teacher_id = ?', [$courseId, (int)$u['id']]);
}

function enroll_key_ready(): bool
{
    static $ready;
    return $ready ??= (bool)db_value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'courses' AND column_name = 'enroll_key'");
}

/** รหัสลงทะเบียนเรียนของรายวิชา: ครูตั้งเอง หรือค่าเริ่มต้นเป็นรหัสวิชา */
function course_enroll_key(array $course): string
{
    $key = trim((string)($course['enroll_key'] ?? ''));
    return $key !== '' ? $key : trim((string)$course['code']);
}

/** เทียบรหัสลงทะเบียน: ไม่สนตัวพิมพ์เล็ก/ใหญ่และช่องว่าง */
function enroll_key_matches(array $course, $input): bool
{
    $norm = fn($s) => mb_strtolower(preg_replace('/\s+/u', '', (string)$s));
    return is_string($input) && $norm($input) !== '' && hash_equals($norm(course_enroll_key($course)), $norm($input));
}

/** ผู้ใช้จัดการรายวิชานี้ได้หรือไม่ (admin ทุกวิชา, ครูเฉพาะวิชาที่สอน; ปิดในโหมดมุมมองผู้เรียน) */
function can_manage_course(int $courseId): bool
{
    return !student_preview() && staff_manages_course($courseId);
}

/** ผู้เรียนมีสิทธิ์เรียนวิชานี้ (ลงทะเบียนเอง หรืออยู่ในกลุ่มที่ได้รับสิทธิ์; โหมดมุมมองผู้เรียนถือว่าเรียนวิชาที่ตนจัดการ) */
function is_enrolled(int $courseId, int $userId): bool
{
    $u = current_user();
    if ($u && (int)$u['id'] === $userId && student_preview() && staff_manages_course($courseId)) return true;
    return (bool)db_value(
        'SELECT 1 FROM enrollments WHERE course_id = ? AND user_id = ?
         UNION SELECT 1 FROM course_groups cg JOIN group_members gm ON gm.group_id = cg.group_id
         WHERE cg.course_id = ? AND gm.user_id = ? LIMIT 1',
        [$courseId, $userId, $courseId, $userId]
    );
}

/** แก้ไขกลุ่มได้: admin ทุกกลุ่ม, ครูเฉพาะกลุ่มที่ตนเป็นครูผู้สอน (group_teachers) */
function can_manage_group(array $group): bool
{
    $u = current_user();
    if (!$u) return false;
    if ($u['role'] === 'admin') return true;
    return $u['role'] === 'teacher'
        && (bool)db_value('SELECT 1 FROM group_teachers WHERE group_id = ? AND teacher_id = ?', [(int)$group['id'], (int)$u['id']]);
}

function can_view_course(int $courseId): bool
{
    $u = current_user();
    return $u && (can_manage_course($courseId) || is_enrolled($courseId, (int)$u['id']));
}

/** id ของวิชาที่ผู้ใช้มีสิทธิ์เรียน (SQL fragment สำหรับ IN) */
function student_course_ids_sql(): string
{
    return '(SELECT course_id FROM enrollments WHERE user_id = ?
             UNION SELECT cg.course_id FROM course_groups cg JOIN group_members gm ON gm.group_id = cg.group_id WHERE gm.user_id = ?)';
}

/** กรอง HTML เนื้อหาบทเรียน: เก็บแท็กจัดรูปแบบ ตัด script / event handler / javascript: */
function clean_html(?string $html): string
{
    $allowed = '<p><br><b><strong><i><em><u><s><del><ins><small><ul><ol><li><dl><dt><dd><h1><h2><h3><h4><h5><h6><a><img>'
        . '<table><caption><colgroup><col><thead><tbody><tfoot><tr><th><td><figure><figcaption><blockquote><pre><code><hr><span><div><sub><sup><mark><abbr>';
    $html = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1\s*>#is', '', (string)$html);
    $html = strip_tags($html, $allowed);
    $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    // style จากตัวแก้ไข (สี/จัดตำแหน่ง) เก็บไว้ แต่ตัดที่มี expression() หรือ url()
    $html = preg_replace('/\s+style\s*=\s*("[^"]*(expression|url\s*\()[^"]*"|\'[^\']*(expression|url\s*\()[^\']*\')/i', '', $html);
    return preg_replace('/(href|src)\s*=\s*(["\']?)\s*(javascript|vbscript|data):[^"\'\s>]*/i', '$1=$2#', $html);
}

/** SELECT รายวิชาพร้อมครูผู้สอน และจำนวนบทเรียน (ต่อท้ายด้วย WHERE/ORDER ได้) */
function course_list_sql(): string
{
    return "SELECT c.*,
              (SELECT GROUP_CONCAT(u.fullname SEPARATOR ', ') FROM course_teachers ct JOIN users u ON u.id = ct.teacher_id WHERE ct.course_id = c.id) AS teachers,
              (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id AND l.is_published = 1) AS lesson_count
            FROM courses c";
}

function course_progress(int $courseId, int $userId): int
{
    $total = (int)db_value('SELECT COUNT(*) FROM lessons WHERE course_id = ? AND is_published = 1', [$courseId]);
    if (!$total) return 0;
    $done = (int)db_value('SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
                           WHERE lp.user_id = ? AND l.course_id = ? AND l.is_published = 1', [$userId, $courseId]);
    return (int)round($done * 100 / $total);
}

// ---------- สื่อการสอน ----------
function media_types(): array
{
    return [
        'text'    => ['label' => 'เนื้อหาข้อความ', 'icon' => 'bi-file-text', 'color' => 'secondary'],
        'youtube' => ['label' => 'วิดีโอ YouTube', 'icon' => 'bi-youtube', 'color' => 'danger'],
        'video'   => ['label' => 'ไฟล์วิดีโอ', 'icon' => 'bi-camera-video', 'color' => 'danger'],
        'pdf'     => ['label' => 'เอกสาร PDF', 'icon' => 'bi-file-earmark-pdf', 'color' => 'warning'],
        'slides'  => ['label' => 'สื่อนำเสนอ PowerPoint', 'icon' => 'bi-file-earmark-slides', 'color' => 'danger'],
        'assignment' => ['label' => 'งานมอบหมาย / ส่งงาน', 'icon' => 'bi-journal-check', 'color' => 'primary'],
        'quiz'    => ['label' => 'แบบทดสอบปรนัย', 'icon' => 'bi-ui-checks', 'color' => 'info'],
        'file'    => ['label' => 'ไฟล์ดาวน์โหลด', 'icon' => 'bi-download', 'color' => 'info'],
        'image'   => ['label' => 'รูปภาพ / อินโฟกราฟิก', 'icon' => 'bi-image', 'color' => 'success'],
        'mindmap' => ['label' => 'แผนผังความคิด', 'icon' => 'bi-diagram-3', 'color' => 'success'],
        'audio'   => ['label' => 'ไฟล์เสียง / Podcast', 'icon' => 'bi-music-note-beamed', 'color' => 'primary'],
        'link'    => ['label' => 'ลิงก์ภายนอก', 'icon' => 'bi-link-45deg', 'color' => 'dark'],
    ];
}

/** นามสกุลไฟล์ที่อนุญาตตามชนิดสื่อ */
/** ฐานข้อมูลรองรับสื่อชนิดนี้แล้วหรือยัง (slides: migrations/1.15.2.sql, mindmap: 1.15.3.sql) */
function media_type_ready(string $type): bool
{
    static $ready = [];
    return $ready[$type] ??= (bool)db_value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'lessons' AND column_name = 'media_type' AND column_type LIKE ?", ["%'" . $type . "'%"]);
}

function slides_ready(): bool
{
    return media_type_ready('slides');
}

function allowed_ext(string $mediaType): array
{
    return [
        'video' => ['mp4', 'webm', 'ogg', 'mov'],
        'pdf'   => ['pdf'],
        'slides' => ['pptx', 'ppt', 'ppsx', 'pps', 'odp'],
        'mindmap' => ['png', 'jpg', 'jpeg', 'webp', 'gif', 'pdf'],
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'audio' => ['mp3', 'wav', 'ogg', 'm4a'],
        'file'  => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'txt', 'jpg', 'png'],
        'cover' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    ][$mediaType] ?? [];
}

function youtube_id(string $url): ?string
{
    if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/))([A-Za-z0-9_-]{11})~', $url, $m)) {
        return $m[1];
    }
    return preg_match('~^[A-Za-z0-9_-]{11}$~', $url) ? $url : null;
}

/**
 * อัปโหลดไฟล์ คืนค่า path แบบ relative (เช่น lessons/abc.pdf) หรือ null ถ้าไม่มีไฟล์
 * โยน RuntimeException เมื่อไฟล์ไม่ถูกต้อง
 */
function upload_file(string $field, string $subdir, array $allowed, ?array $upload = null): ?string
{
    $f = $upload ?? ($_FILES[$field] ?? null);
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ (รหัส ' . $f['error'] . ') ไฟล์อาจใหญ่เกินที่เซิร์ฟเวอร์กำหนด');
    }
    if ($f['size'] > MAX_UPLOAD_MB * 1024 * 1024) {
        throw new RuntimeException('ไฟล์มีขนาดเกิน ' . MAX_UPLOAD_MB . ' MB');
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('ไม่อนุญาตไฟล์ชนิด .' . $ext . ' (อนุญาต: ' . implode(', ', $allowed) . ')');
    }
    $dir = UPLOAD_PATH . '/' . $subdir;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('ไม่สามารถบันทึกไฟล์ได้');
    }
    return $subdir . '/' . $name;
}

function delete_upload(?string $path): void
{
    if ($path && strpos($path, '..') === false && is_file(UPLOAD_PATH . '/' . $path)) {
        unlink(UPLOAD_PATH . '/' . $path);
    }
}

function upload_url(?string $path): string
{
    return $path ? UPLOAD_URL . '/' . $path : '';
}

function course_cover(array $c): string
{
    if (empty($c['cover'])) return url('assets/img/course-default.svg');
    if (!is_file(UPLOAD_PATH . '/' . $c['cover'])) {
        // ไฟล์ภาพปกหาย (เช่น กู้คืนฐานข้อมูลแต่ไม่ได้กู้ไฟล์) → ภาพอัตโนมัติสร้างใหม่ทันที, ภาพอัปโหลดใช้ภาพเริ่มต้น
        require_once ROOT_PATH . '/includes/cover.php';
        if (!is_auto_cover($c['cover']) || empty($c['id']) || !isset($c['code'], $c['title'])) {
            return url('assets/img/course-default.svg');
        }
        foreach (glob(UPLOAD_PATH . '/covers/auto_' . (int)$c['id'] . '_*.svg') ?: [] as $old) unlink($old);
        $c['cover'] = cover_generate((int)$c['id'], $c['code'], $c['title'], (string)($c['description'] ?? ''));
        db_exec('UPDATE courses SET cover = ? WHERE id = ?', [$c['cover'], (int)$c['id']]);
    }
    return upload_url($c['cover']);
}

function avatar_url(?array $u): string
{
    return !empty($u['avatar']) ? upload_url($u['avatar']) : url('assets/img/avatar.svg');
}

/**
 * แสดงสไลด์ PowerPoint ในหน้าเว็บ: ไฟล์ที่อัปโหลดใช้ Office Online viewer (ต้องเป็นเว็บที่ Microsoft เข้าถึงได้),
 * ลิงก์ Google Slides แปลงเป็นลิงก์ฝัง, ลิงก์อื่น (OneDrive/Canva) ฝังตามที่ได้รับ
 * คืน ['embed' => URL สำหรับ iframe|null, 'open' => URL เปิดแท็บใหม่|null, 'local' => true เมื่อเป็น localhost จึงพรีวิวไม่ได้]
 */
function slides_embed(array $media, string $fileUrl): array
{
    if ($fileUrl !== '') {
        // BASE_URL อาจเป็นแค่ path (เช่น /lms) → ต่อโดเมนจากคำขอปัจจุบัน ให้ Office viewer ดึงไฟล์ได้
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $absolute = preg_match('~^https?://~i', $fileUrl) ? $fileUrl : ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $fileUrl;
        $host = strtolower((string)parse_url($absolute, PHP_URL_HOST));
        $local = $host === 'localhost' || $host === '::1' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test') || preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host);
        return ['embed' => $local ? null : 'https://view.officeapps.live.com/op/embed.aspx?src=' . rawurlencode($absolute), 'open' => $local ? null : 'https://view.officeapps.live.com/op/view.aspx?src=' . rawurlencode($absolute), 'local' => (bool)$local];
    }
    $url = (string)$media['media_url'];
    if (preg_match('~^https://docs\.google\.com/presentation/d/([A-Za-z0-9_-]+)~', $url, $m)) {
        return ['embed' => 'https://docs.google.com/presentation/d/' . $m[1] . '/embed?start=false&loop=false', 'open' => $url, 'local' => false];
    }
    return ['embed' => preg_match('~^https://~i', $url) ? $url : null, 'open' => $url ?: null, 'local' => false];
}

/** ลิงก์แผนผังความคิดสำหรับฝังใน iframe: Canva (/view → ?embed), Miro (live-embed), อื่น ๆ ใช้ลิงก์ https ตามเดิม */
function mindmap_embed(string $url): ?string
{
    if (!preg_match('~^https://~i', $url)) return null;
    if (preg_match('~^https://www\.canva\.com/design/([A-Za-z0-9_-]+)/([A-Za-z0-9_-]+)/view~', $url, $m)) {
        return 'https://www.canva.com/design/' . $m[1] . '/' . $m[2] . '/view?embed';
    }
    if (preg_match('~^https://miro\.com/app/board/([A-Za-z0-9_=-]+)~', $url, $m)) {
        return 'https://miro.com/app/live-embed/' . $m[1] . '/';
    }
    return $url;
}
