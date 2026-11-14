<?php
// หลังบ้าน: ตรวจคำตอบ/ให้คะแนนใบงานบนกระดาน, สถานะการส่ง, รายงานการมีส่วนร่วม, ประวัติกิจกรรม, ดาวน์โหลดผลงาน (ZIP/CSV)
require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_role('admin', 'teacher');
$board = board_get(get_int('id'));
if (!$board || !can_manage_course((int)$board['course_id'])) { http_response_code(403); exit('ไม่มีสิทธิ์จัดการกระดานนี้'); }
$boardId = (int)$board['id'];
$courseId = (int)$board['course_id'];
$self = 'admin/board_report.php?id=' . $boardId;
$groupId = get_int('group_id');
$roster = grade_roster($courseId, $groupId);
$rosterIds = array_map('intval', array_column($roster, 'id'));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'grade') {
    verify_csrf();
    $uid = (int)post('user_id');
    $back = $self . (get_int('review') ? '&review=' . $uid : '') . '#student-' . $uid;
    try {
        if (!in_array($uid, array_map('intval', array_column(grade_roster($courseId), 'id')), true)) throw new RuntimeException('นักเรียนไม่ได้อยู่ในรายวิชานี้');
        $status = post('status') === 'revision' ? 'revision' : 'graded';
        $feedback = trim((string)post('feedback'));
        if (mb_strlen($feedback) > 2000) throw new RuntimeException('ข้อเสนอแนะยาวได้ไม่เกิน 2,000 ตัวอักษร');
        if ($status === 'revision' && $feedback === '') throw new RuntimeException('กรุณาบอกสิ่งที่ต้องแก้ไข');
        $score = $status === 'graded' ? assignment_score(post('score'), (string)$board['max_points']) : null;
        $targets = [$uid];
        if (post('apply_team') && ($team = board_user_team($boardId, $uid))) {
            $targets = array_map('intval', array_column(db_all('SELECT user_id FROM board_team_members WHERE team_id = ?', [$team]), 'user_id'));
        }
        $conn->begin_transaction();
        $existing = db_one('SELECT version FROM board_scores WHERE board_id = ? AND user_id = ? FOR UPDATE', [$boardId, $uid]);
        if ((int)post('version') !== (int)($existing['version'] ?? 0)) throw new RuntimeException('คะแนนของนักเรียนคนนี้ถูกแก้ไขแล้ว กรุณาตรวจสอบข้อมูลล่าสุดก่อนบันทึก');
        foreach ($targets as $target) {
            db_exec('INSERT INTO board_scores (board_id,user_id,score,feedback,status,graded_by) VALUES (?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE score = VALUES(score), feedback = VALUES(feedback), status = VALUES(status), graded_by = VALUES(graded_by), graded_at = NOW(), version = version + 1',
                [$boardId, $target, $score, $feedback ?: null, $status, (int)$me['id']]);
            board_grade_xp($board, $target, $score);
            board_log($boardId, (int)$me['id'], null, 'grade', ($score ?? 'ให้แก้ไข') . ' → ผู้เรียน #' . $target);
        }
        grade_sync($courseId);
        board_bump($boardId);
        $conn->commit();
        flash('success', $status === 'revision' ? 'ส่งกลับให้แก้ไขแล้ว' : 'บันทึกคะแนน' . (count($targets) > 1 ? 'ทั้งทีม ' . count($targets) . ' คน' : '') . 'แล้ว (เข้าสมุดคะแนนรายวิชา)');
        // โหมดตรวจทีละคน: ไปคนถัดไปที่ส่งแล้วแต่ยังไม่ตรวจ
        if (post('next')) {
            $subs = board_submissions($board, grade_roster($courseId, $groupId));
            foreach ($subs as $sid => $s) if (in_array($s['state'], ['submitted', 'late'], true) && $sid !== $uid) redirect($self . '&review=' . $sid . ($groupId ? '&group_id=' . $groupId : ''));
            flash('info', 'ตรวจครบทุกคนที่ส่งแล้ว');
            redirect($self);
        }
        redirect($back);
    } catch (RuntimeException $ex) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        flash('danger', $ex->getMessage());
        redirect($back);
    }
}

$subs = board_submissions($board, $roster);
$export = $_GET['export'] ?? '';
$safeName = fn(string $s) => trim(preg_replace('~[\\\\/:*?"<>|\x00-\x1f]+~u', '_', $s)) ?: 'file';

if ($export === 'zip') {
    // ZIP แบบไม่บีบอัด (ไม่ต้องใช้ส่วนขยาย ZipArchive ของ PHP)
    $files = db_all("SELECT a.*, p.user_id, u.fullname, u.student_code FROM board_attachments a JOIN board_posts p ON p.id = a.post_id JOIN users u ON u.id = p.user_id
                     WHERE p.board_id = ? AND p.deleted_at IS NULL AND p.status <> 'rejected' ORDER BY u.student_code, u.fullname, a.id", [$boardId]);
    $csv = "\xEF\xBB\xBFรหัส,ชื่อ,สถานะ,ส่งครั้งแรก,จำนวนโพสต์,คะแนน,ข้อเสนอแนะ\r\n";
    foreach ($roster as $s) {
        $r = $subs[(int)$s['id']];
        $csv .= implode(',', array_map(fn($x) => '"' . str_replace('"', '""', (string)$x) . '"', [$s['student_code'], $s['fullname'], BOARD_SUBMIT_STATES[$r['state']][0], $r['first'], $r['posts'], $r['score'], $r['feedback']])) . "\r\n";
    }
    session_write_close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="board-' . $boardId . '.zip"; filename*=UTF-8\'\'' . rawurlencode($safeName($board['title']) . '.zip'));
    header('Cache-Control: private, no-store');
    $offset = 0; $central = ''; $count = 0; $seen = [];
    $dos = (((int)date('Y') - 1980) << 25) | ((int)date('n') << 21) | ((int)date('j') << 16) | ((int)date('G') << 11) | ((int)date('i') << 5) | ((int)date('s') >> 1);
    $add = function (string $name, ?string $path, ?string $content = null) use (&$offset, &$central, &$count, $dos) {
        $size = $path !== null ? filesize($path) : strlen($content);
        $crc = $path !== null ? hexdec(hash_file('crc32b', $path)) : crc32($content);
        $header = pack('VvvvVVVVvv', 0x04034b50, 20, 0x0800, 0, $dos, $crc, $size, $size, strlen($name), 0) . $name;
        echo $header;
        if ($path !== null) readfile($path); else echo $content;
        $central .= pack('VvvvvVVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $dos, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        $offset += strlen($header) + $size;
        $count++;
        flush();
    };
    $add('รายชื่อการส่งงาน.csv', null, $csv);
    foreach ($files as $f) {
        $full = UPLOAD_PATH . '/' . $f['path'];
        if (!preg_match('~^boards/[a-f0-9]{48}\.[a-z0-9]+$~D', $f['path']) || !is_file($full)) continue;
        $folder = $safeName(trim(($f['student_code'] ? $f['student_code'] . ' ' : '') . $f['fullname']));
        $name = $folder . '/' . $safeName($f['original_name']);
        for ($i = 2; isset($seen[$name]); $i++) $name = $folder . '/' . $i . '_' . $safeName($f['original_name']);
        $seen[$name] = true;
        $add($name, $full);
    }
    echo $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $offset, 0);
    exit;
}

// การมีส่วนร่วมรายคน
$part = [];
foreach ([
    'posts' => "SELECT user_id, COUNT(*) n FROM board_posts WHERE board_id = ? AND deleted_at IS NULL GROUP BY user_id",
    'comments' => "SELECT c.user_id, COUNT(*) n FROM board_comments c JOIN board_posts p ON p.id = c.post_id WHERE p.board_id = ? AND c.deleted_at IS NULL GROUP BY c.user_id",
    'given' => "SELECT r.user_id, COUNT(*) n FROM board_reactions r JOIN board_posts p ON p.id = r.post_id WHERE p.board_id = ? GROUP BY r.user_id",
    'received' => "SELECT p.user_id, COUNT(*) n FROM board_reactions r JOIN board_posts p ON p.id = r.post_id WHERE p.board_id = ? AND p.deleted_at IS NULL GROUP BY p.user_id",
    'edits' => "SELECT p.user_id, COUNT(*) n FROM board_post_revisions v JOIN board_posts p ON p.id = v.post_id WHERE p.board_id = ? GROUP BY p.user_id",
    'xp' => "SELECT user_id, SUM(points) n FROM xp_events WHERE board_id = ? GROUP BY user_id",
] as $key => $sql) foreach (db_all($sql, [$boardId]) as $r) $part[(int)$r['user_id']][$key] = (int)$r['n'];

if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="board-' . $boardId . '-report.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['รหัส', 'ชื่อ', 'กลุ่ม', 'สถานะการส่ง', 'ส่งครั้งแรก', 'โพสต์', 'ความคิดเห็น', 'กดถูกใจ/โหวตให้เพื่อน', 'ได้รับถูกใจ/โหวต', 'แก้ไขคำตอบ', 'ดาวจากกระดาน', 'คะแนน', 'คะแนนเต็ม', 'ข้อเสนอแนะ']);
    foreach ($roster as $s) {
        $uid = (int)$s['id']; $r = $subs[$uid]; $p = $part[$uid] ?? [];
        fputcsv($out, [$s['student_code'], $s['fullname'], $s['group_names'], BOARD_SUBMIT_STATES[$r['state']][0], $r['first'], $p['posts'] ?? 0, $p['comments'] ?? 0, $p['given'] ?? 0, $p['received'] ?? 0, $p['edits'] ?? 0, $p['xp'] ?? 0, $r['score'], $board['max_points'], $r['feedback']]);
    }
    exit;
}

$tab = in_array($_GET['tab'] ?? '', ['participation', 'activity'], true) ? $_GET['tab'] : 'grading';
$review = get_int('review');
$counts = array_count_values(array_column($subs, 'state'));
$teamNames = array_column(db_all('SELECT m.user_id, t.name FROM board_team_members m JOIN board_teams t ON t.id = m.team_id WHERE m.board_id = ?', [$boardId]), 'name', 'user_id');
$groups = grade_groups($courseId);
$pageTitle = 'ตรวจคำตอบ — ' . $board['title'];
require __DIR__ . '/_top.php';

/** แสดงคำตอบของนักเรียนแบบอ่านง่ายสำหรับครู */
function board_report_posts(int $boardId, int $userId): void
{
    $posts = db_all('SELECT * FROM board_posts WHERE board_id = ? AND user_id = ? AND deleted_at IS NULL ORDER BY id', [$boardId, $userId]);
    if (!$posts) { echo '<p class="text-muted">ยังไม่ได้ส่ง</p>'; return; }
    foreach ($posts as $p) {
        $files = db_all('SELECT * FROM board_attachments WHERE post_id = ? ORDER BY sort_order, id', [(int)$p['id']]);
        $revs = db_all('SELECT * FROM board_post_revisions WHERE post_id = ? ORDER BY id DESC', [(int)$p['id']]); ?>
        <article class="border rounded-3 p-3 mb-3 <?= $p['status'] === 'rejected' ? 'border-danger' : '' ?>">
            <div class="small text-muted mb-1">ส่ง <?= e(thai_date($p['created_at'], true)) ?><?= $p['updated_at'] ? ' · แก้ไขล่าสุด ' . e(thai_date($p['updated_at'], true)) : '' ?>
                <?= $p['is_late'] ? '<span class="badge bg-warning text-dark">ส่งเกินเวลา</span>' : '' ?> <?= $p['status'] !== 'approved' ? '<span class="badge bg-secondary">' . ($p['status'] === 'pending' ? 'รออนุมัติ' : 'ไม่อนุมัติ') . '</span>' : '' ?></div>
            <?php if ($p['title']): ?><h3 class="h6"><?= e($p['title']) ?></h3><?php endif; ?>
            <?php if ($p['body']): ?><div style="white-space:pre-wrap" class="text-break"><?= e($p['body']) ?></div><?php endif; ?>
            <?php if ($p['link_url']): ?><div class="mt-2"><?php if ($yt = youtube_id($p['link_url'])): ?><div class="ratio ratio-16x9" style="max-width:560px"><iframe src="https://www.youtube-nocookie.com/embed/<?= e($yt) ?>" allowfullscreen title="YouTube"></iframe></div><?php else: ?><a href="<?= e($p['link_url']) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-link-45deg"></i> <?= e($p['link_url']) ?></a><?php endif; ?></div><?php endif; ?>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <?php foreach ($files as $f): $u = url('board_file.php?id=' . $f['id']); ?>
                    <?php if ($f['kind'] === 'image'): ?><a href="<?= e($u) ?>" target="_blank"><img src="<?= e($u) ?>" alt="<?= e($f['original_name']) ?>" style="max-height:220px;max-width:100%" class="rounded border"></a>
                    <?php elseif ($f['kind'] === 'video'): ?><video src="<?= e($u) ?>" controls preload="metadata" style="max-width:420px;width:100%" class="rounded"></video>
                    <?php elseif ($f['kind'] === 'audio'): ?><div><div class="small"><?= e($f['original_name']) ?></div><audio src="<?= e($u) ?>" controls preload="none"></audio></div>
                    <?php else: ?><a class="btn btn-sm btn-outline-primary" href="<?= e($u) ?>&download=1"><i class="bi bi-download"></i> <?= e($f['original_name']) ?></a><?php if (str_ends_with(strtolower($f['path']), '.pdf')): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e($u) ?>" target="_blank">ดู PDF</a><?php endif; ?><?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php if ($revs): ?><details class="mt-2 small"><summary>ประวัติการแก้ไข <?= count($revs) ?> ครั้ง</summary>
                <?php foreach ($revs as $r): ?><div class="border-start ps-2 my-2"><div class="text-muted"><?= e(thai_date($r['created_at'], true)) ?></div><?= $r['title'] ? '<b>' . e($r['title']) . '</b><br>' : '' ?><span style="white-space:pre-wrap"><?= e($r['body']) ?></span></div><?php endforeach; ?></details><?php endif; ?>
        </article>
    <?php }
}

/** ฟอร์มให้คะแนน */
function board_grade_form(array $board, array $student, array $sub, bool $review, bool $hasTeam): void
{ ?>
    <form method="post" class="row g-2 align-items-end"><?= csrf_field() ?>
        <input type="hidden" name="action" value="grade"><input type="hidden" name="user_id" value="<?= (int)$student['id'] ?>"><input type="hidden" name="version" value="<?= (int)$sub['version'] ?>">
        <div class="col-auto"><label class="form-label small mb-0">คะแนน / <?= e($board['max_points']) ?><input type="number" name="score" min="0" max="<?= e($board['max_points']) ?>" step="0.01" class="form-control form-control-sm" style="width:6.5rem" value="<?= e($sub['score']) ?>"></label></div>
        <div class="col"><label class="form-label small mb-0 w-100">ข้อเสนอแนะ<input name="feedback" maxlength="2000" class="form-control form-control-sm" value="<?= e($sub['feedback']) ?>"></label></div>
        <div class="col-auto d-flex flex-wrap gap-1">
            <?php if ($hasTeam): ?><label class="form-check small align-self-center me-1"><input type="checkbox" class="form-check-input" name="apply_team" value="1"> ทั้งทีม</label><?php endif; ?>
            <button class="btn btn-sm btn-vt" name="status" value="graded"><i class="bi bi-check2"></i> บันทึก</button>
            <?php if ($review): ?><button class="btn btn-sm btn-success" name="next" value="1"><i class="bi bi-skip-forward"></i> บันทึกแล้วคนถัดไป</button><input type="hidden" name="status" value="graded"><?php endif; ?>
            <button class="btn btn-sm btn-outline-danger" name="status" value="revision" title="ส่งกลับให้แก้ไข (ต้องเขียนข้อเสนอแนะ)">ให้แก้ไข</button>
        </div>
    </form>
<?php }
?>
<a href="<?= url('admin/boards.php?course_id=' . $courseId) ?>"><i class="bi bi-arrow-left"></i> กระดานของวิชา</a> · <a href="<?= url('board.php?id=' . $boardId) ?>" target="_blank">เปิดกระดาน</a> · <a href="<?= url('admin/board_form.php?id=' . $boardId) ?>">ตั้งค่า</a>
<div class="d-flex flex-wrap gap-2 align-items-center my-3">
    <?php foreach (BOARD_SUBMIT_STATES as $k => [$label, $color]): ?><span class="badge bg-<?= $color ?> fs-6 fw-normal"><?= $label ?> <?= (int)($counts[$k] ?? 0) ?></span><?php endforeach; ?>
    <span class="text-muted small">จากผู้เรียน <?= count($roster) ?> คน<?= $board['due_at'] ? ' · กำหนดส่ง ' . e(thai_date($board['due_at'], true)) : '' ?></span>
    <div class="ms-auto d-flex flex-wrap gap-2">
        <?php if ($groups): ?><form class="d-flex gap-1"><input type="hidden" name="id" value="<?= $boardId ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>">
            <select name="group_id" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="กรองกลุ่ม"><option value="">ทุกกลุ่ม</option><?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= $groupId === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?></select></form><?php endif; ?>
        <a class="btn btn-sm btn-outline-success" href="<?= url($self . '&export=csv' . ($groupId ? '&group_id=' . $groupId : '')) ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
        <a class="btn btn-sm btn-outline-primary" href="<?= url($self . '&export=zip' . ($groupId ? '&group_id=' . $groupId : '')) ?>"><i class="bi bi-file-earmark-zip"></i> ดาวน์โหลดผลงานทั้งหมด (ZIP)</a>
    </div>
</div>
<ul class="nav nav-tabs mb-3">
    <?php foreach (['grading' => 'ตรวจคำตอบ / ให้คะแนน', 'participation' => 'รายงานการมีส่วนร่วม', 'activity' => 'ประวัติกิจกรรม'] as $k => $label): ?>
        <li class="nav-item"><a class="nav-link <?= $tab === $k && !$review ? 'active' : '' ?>" href="<?= url($self . '&tab=' . $k . ($groupId ? '&group_id=' . $groupId : '')) ?>"><?= $label ?></a></li>
    <?php endforeach; ?>
</ul>
<?php if (!$board['is_assignment']): ?><div class="alert alert-info small">กระดานนี้ยังไม่ได้ตั้งเป็นใบงาน คะแนนที่ให้จะยังไม่เข้าสมุดคะแนน · <a href="<?= url('admin/board_form.php?id=' . $boardId) ?>">เปิด “เป็นใบงาน” ในการตั้งค่า</a></div><?php endif; ?>

<?php if ($review && isset($subs[$review])): $student = null; foreach ($roster as $s) if ((int)$s['id'] === $review) $student = $s; $pos = array_search($review, $rosterIds, true); ?>
    <div class="card border-0 shadow-sm rounded-4" id="student-<?= $review ?>"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
            <div><h2 class="h5 mb-0"><?= e($student['fullname']) ?> <small class="text-muted"><?= e($student['student_code']) ?></small></h2>
                <span class="badge bg-<?= BOARD_SUBMIT_STATES[$subs[$review]['state']][1] ?>"><?= BOARD_SUBMIT_STATES[$subs[$review]['state']][0] ?></span> <?= isset($teamNames[$review]) ? '<span class="badge bg-info-subtle text-info-emphasis">' . e($teamNames[$review]) . '</span>' : '' ?></div>
            <div class="d-flex gap-1">
                <a class="btn btn-sm btn-outline-secondary <?= $pos > 0 ? '' : 'disabled' ?>" href="<?= $pos > 0 ? url($self . '&review=' . $rosterIds[$pos - 1] . ($groupId ? '&group_id=' . $groupId : '')) : '#' ?>"><i class="bi bi-chevron-left"></i> คนก่อนหน้า</a>
                <a class="btn btn-sm btn-outline-secondary <?= $pos < count($rosterIds) - 1 ? '' : 'disabled' ?>" href="<?= $pos < count($rosterIds) - 1 ? url($self . '&review=' . $rosterIds[$pos + 1] . ($groupId ? '&group_id=' . $groupId : '')) : '#' ?>">คนถัดไป <i class="bi bi-chevron-right"></i></a>
                <a class="btn btn-sm btn-light" href="<?= url($self . ($groupId ? '&group_id=' . $groupId : '')) ?>">กลับรายชื่อ</a>
            </div>
        </div>
        <?php board_report_posts($boardId, $review); ?>
        <?php board_grade_form($board, $student, $subs[$review], true, isset($teamNames[$review])); ?>
    </div></div>
<?php elseif ($tab === 'grading'): ?>
    <div class="card border-0 shadow-sm rounded-4"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>ผู้เรียน</th><th>สถานะ</th><th>ส่งเมื่อ</th><th style="min-width:30rem">คะแนน / ข้อเสนอแนะ</th></tr></thead>
        <tbody>
        <?php foreach ($roster as $s): $uid = (int)$s['id']; $r = $subs[$uid]; [$label, $color] = BOARD_SUBMIT_STATES[$r['state']]; ?>
            <tr id="student-<?= $uid ?>">
                <td><div class="fw-semibold"><?= e($s['fullname']) ?></div><small class="text-muted"><?= e($s['student_code']) ?><?= isset($teamNames[$uid]) ? ' · ' . e($teamNames[$uid]) : '' ?></small></td>
                <td><span class="badge bg-<?= $color ?>"><?= $label ?></span><?= $r['posts'] > 1 ? '<div class="small text-muted">' . $r['posts'] . ' โพสต์</div>' : '' ?></td>
                <td class="small"><?= $r['first'] ? e(thai_date($r['first'], true)) : '–' ?><?php if ($r['posts']): ?><div><a href="<?= url($self . '&review=' . $uid . ($groupId ? '&group_id=' . $groupId : '')) ?>"><i class="bi bi-eye"></i> ดูคำตอบ</a></div><?php endif; ?></td>
                <td><?php board_grade_form($board, $s, $r, false, isset($teamNames[$uid])); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$roster): ?><tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีผู้เรียน</td></tr><?php endif; ?>
        </tbody>
    </table></div></div>
    <?php $firstWaiting = array_key_first(array_filter($subs, fn($r) => in_array($r['state'], ['submitted', 'late'], true))); ?>
    <?php if ($firstWaiting): ?><a class="btn btn-vt mt-3" href="<?= url($self . '&review=' . $firstWaiting . ($groupId ? '&group_id=' . $groupId : '')) ?>"><i class="bi bi-person-check"></i> ตรวจทีละคน (เริ่มจากคนที่รอตรวจ)</a><?php endif; ?>
<?php elseif ($tab === 'participation'): ?>
    <div class="card border-0 shadow-sm rounded-4"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>ผู้เรียน</th><th class="text-center">โพสต์</th><th class="text-center">ความคิดเห็น</th><th class="text-center">ให้ถูกใจ/โหวต</th><th class="text-center">ได้รับ</th><th class="text-center">แก้ไขคำตอบ</th><th class="text-center">ดาว ⭐</th><th>การส่ง</th></tr></thead>
        <tbody>
        <?php foreach ($roster as $s): $uid = (int)$s['id']; $p = $part[$uid] ?? []; [$label, $color] = BOARD_SUBMIT_STATES[$subs[$uid]['state']]; $active = array_sum(array_intersect_key($p, array_flip(['posts', 'comments', 'given']))); ?>
            <tr class="<?= $active ? '' : 'table-warning' ?>">
                <td><?= e($s['fullname']) ?> <small class="text-muted"><?= e($s['student_code']) ?></small></td>
                <?php foreach (['posts', 'comments', 'given', 'received', 'edits', 'xp'] as $k): ?><td class="text-center"><?= (int)($p[$k] ?? 0) ?></td><?php endforeach; ?>
                <td><span class="badge bg-<?= $color ?>"><?= $label ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
    <p class="small text-muted mt-2">แถวสีเหลือง = ยังไม่มีส่วนร่วมบนกระดานนี้ · ดาว: โพสต์ +<?= XP_RULES['post'] ?> (สูงสุด <?= XP_RULES['post_cap'] ?> โพสต์) · ความคิดเห็นบนโพสต์เพื่อน +<?= XP_RULES['comment'] ?> (วันละไม่เกิน <?= XP_RULES['comment_cap_daily'] ?>) · ได้รับถูกใจ/โหวต/ดาว +<?= XP_RULES['reaction'] ?> · ส่งทันกำหนด +<?= XP_RULES['ontime'] ?> · คะแนนจากครูสูงสุด +<?= XP_RULES['grade'] ?> · ครูมอบดาว +<?= XP_RULES['star'] ?> ต่อครั้ง</p>
<?php else: ?>
    <?php $log = db_all('SELECT a.*, u.fullname FROM board_activity a LEFT JOIN users u ON u.id = a.user_id WHERE a.board_id = ? ORDER BY a.id DESC LIMIT 300', [$boardId]); ?>
    <div class="card border-0 shadow-sm rounded-4"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>เวลา</th><th>ผู้ใช้</th><th>การกระทำ</th><th>รายละเอียด</th></tr></thead>
        <tbody>
        <?php foreach ($log as $a): ?><tr><td class="small text-nowrap"><?= e(thai_date($a['created_at'], true)) ?></td><td><?= e($a['fullname'] ?? '-') ?></td><td><?= e(BOARD_ACTIONS[$a['action']] ?? $a['action']) ?></td><td class="small text-break"><?= e($a['detail']) ?></td></tr><?php endforeach; ?>
        <?php if (!$log): ?><tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีกิจกรรม</td></tr><?php endif; ?>
        </tbody>
    </table></div></div>
    <p class="small text-muted mt-2">แสดง 300 รายการล่าสุด</p>
<?php endif; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
