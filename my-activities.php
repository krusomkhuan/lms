<?php
// แดชบอร์ด "กิจกรรมของฉัน": ความคืบหน้าบนกระดานกิจกรรม กิจกรรมที่ยังไม่ทำ ดาว Level และเหรียญรางวัล (รายวิชา)
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();
$uid = (int)$me['id'];
$courses = boards_ready() ? db_all("SELECT c.id, c.code, c.title, c.leaderboard_enabled FROM courses c WHERE c.status = 'published' AND c.id IN " . student_course_ids_sql() . "
                                    AND EXISTS (SELECT 1 FROM boards b WHERE b.course_id = c.id AND b.status <> 'archived') ORDER BY c.code", [$uid, $uid]) : [];
$courseId = get_int('course_id') ?: (int)($courses[0]['id'] ?? 0);
$course = null;
foreach ($courses as $c) if ((int)$c['id'] === $courseId) $course = $c;

$activities = ['todo' => [], 'done' => [], 'upcoming' => [], 'missed' => [], 'view' => []];
$stat = [];
if ($course) {
    $boards = course_boards($courseId, false);
    $ids = array_map(fn($b) => (int)$b['id'], $boards);
    $in = $ids ? implode(',', $ids) : '0';
    $mine = [];
    foreach (db_all("SELECT board_id, COUNT(*) AS n, MIN(created_at) AS first_at, MAX(is_late) AS late, SUM(status = 'rejected') AS rejected, SUM(status = 'pending') AS pending
                     FROM board_posts WHERE user_id = ? AND deleted_at IS NULL AND board_id IN ($in) GROUP BY board_id", [$uid]) as $r) $mine[(int)$r['board_id']] = $r;
    $scores = array_column(db_all("SELECT * FROM board_scores WHERE user_id = ? AND board_id IN ($in)", [$uid]), null, 'board_id');
    $roles = array_column(db_all("SELECT board_id, role FROM board_members WHERE user_id = ? AND board_id IN ($in)", [$uid]), 'role', 'board_id');
    $comments = array_column(db_all("SELECT p.board_id, COUNT(*) AS n FROM board_comments c JOIN board_posts p ON p.id = c.post_id WHERE c.user_id = ? AND c.deleted_at IS NULL AND p.board_id IN ($in) GROUP BY p.board_id", [$uid]), 'n', 'board_id');
    $lessonTitles = array_column(db_all('SELECT id, title FROM lessons WHERE course_id = ?', [$courseId]), 'title', 'id');
    foreach ($boards as $b) {
        $bid = (int)$b['id'];
        $m = $mine[$bid] ?? null;
        $s = $scores[$bid] ?? null;
        $sent = $m && (int)$m['n'] > (int)$m['rejected'];
        $revision = ($s && $s['status'] === 'revision') || ($m && !$sent);
        $window = board_window($b);
        $role = $roles[$bid] ?? $b['student_role'];
        $item = ['board' => $b, 'window' => $window, 'posts' => (int)($m['n'] ?? 0), 'late' => (bool)($m['late'] ?? false), 'pending' => (int)($m['pending'] ?? 0), 'first' => $m['first_at'] ?? null,
            'score' => $s['score'] ?? null, 'feedback' => $s['feedback'] ?? '', 'revision' => $revision, 'comments' => (int)($comments[$bid] ?? 0), 'lesson' => $lessonTitles[$b['lesson_id'] ?? 0] ?? null];
        if (!in_array($role, ['contributor', 'moderator'], true)) $activities['view'][] = $item;
        elseif ($sent && !$revision) $activities['done'][] = $item;
        elseif ($window === 'upcoming') $activities['upcoming'][] = $item;
        elseif (in_array($window, ['open', 'late'], true)) $activities['todo'][] = $item;
        else $activities['missed'][] = $item;
    }
    // ยังไม่ทำ: เลยกำหนดก่อน แล้วเรียงตามกำหนดส่ง (ไม่มีกำหนดไว้ท้าย)
    usort($activities['todo'], fn($a, $b) => [$a['window'] !== 'late', $a['board']['due_at'] === null, $a['board']['due_at']] <=> [$b['window'] !== 'late', $b['board']['due_at'] === null, $b['board']['due_at']]);
    $stat = star_stats($courseId, [$uid])[$uid] ?? [];
}
$level = star_level((int)($stat['total'] ?? 0));
$badges = star_badges();
$earned = star_earned($stat + ['total' => $level['stars']]);
$required = count($activities['todo']) + count($activities['done']) + count($activities['missed']);
$donePct = $required ? (int)round(count($activities['done']) * 100 / $required) : 0;
$graded = array_filter($activities['done'], fn($a) => $a['score'] !== null && $a['board']['is_assignment']);
$avg = $graded ? round(array_sum(array_map(fn($a) => (float)$a['score'] * 100 / max(0.01, (float)$a['board']['max_points']), $graded)) / count($graded)) : null;
$history = $course ? db_all('SELECT x.*, b.title AS board_title FROM xp_events x LEFT JOIN boards b ON b.id = x.board_id WHERE x.course_id = ? AND x.user_id = ? ORDER BY x.id DESC LIMIT 8', [$courseId, $uid]) : [];
$top = $course && $course['leaderboard_enabled'] ? array_slice(xp_leaderboard($courseId), 0, 5) : [];

function due_chip(array $a): string
{
    $due = $a['board']['due_at'];
    if ($a['window'] === 'late') return '<span class="chip chip-danger"><i class="bi bi-exclamation-triangle"></i> เลยกำหนด ' . e(thai_date($due, true)) . '</span>';
    if (!$due) return '<span class="chip chip-muted"><i class="bi bi-infinity"></i> ไม่กำหนดวันส่ง</span>';
    $left = strtotime($due) - time();
    $text = $left < 3600 ? 'เหลือไม่ถึง 1 ชม.' : ($left < 86400 ? 'เหลือ ' . floor($left / 3600) . ' ชม.' : 'เหลือ ' . floor($left / 86400) . ' วัน');
    return '<span class="chip ' . ($left < 86400 ? 'chip-warn' : 'chip-ok') . '"><i class="bi bi-hourglass-split"></i> ' . $text . ' · ' . e(thai_date($due, true)) . '</span>';
}

$pageTitle = 'กิจกรรมของฉัน';
require __DIR__ . '/includes/header.php';
?>
<link href="<?= url('assets/css/board.css') ?>?v=<?= (int)@filemtime(ROOT_PATH . '/assets/css/board.css') ?>" rel="stylesheet">
<div class="container py-4 my-activities">
    <h1 class="h3 mb-3"><i class="bi bi-rocket-takeoff text-primary"></i> กิจกรรมของฉัน</h1>
    <?php if (!$courses): ?>
        <div class="text-center text-muted py-5 bg-white rounded-4 shadow-sm"><i class="bi bi-easel2 fs-1"></i><p class="mt-2 mb-0">ยังไม่มีกระดานกิจกรรมในรายวิชาที่เรียนอยู่</p></div>
    <?php else: ?>
    <ul class="nav nav-pills mb-3 flex-nowrap overflow-auto">
        <?php foreach ($courses as $c): ?><li class="nav-item"><a class="nav-link text-nowrap <?= (int)$c['id'] === $courseId ? 'active' : '' ?>" href="?course_id=<?= (int)$c['id'] ?>"><?= e($c['code'] . ' ' . $c['title']) ?></a></li><?php endforeach; ?>
    </ul>

    <!-- ส่วนหัว: ดาว Level เหรียญ -->
    <section class="act-hero mb-4">
        <div class="d-flex flex-wrap align-items-center gap-4">
            <div class="avatar-wrap"><img src="<?= e(avatar_url($me)) ?>" class="act-avatar" alt=""><span class="avatar-level avatar-level-lg"><?= $level['level'] ?></span></div>
            <div class="flex-grow-1" style="min-width:15rem">
                <div class="small opacity-75"><?= e($course['code'] . ' ' . $course['title']) ?></div>
                <div class="h4 mb-1"><?= e($me['fullname']) ?></div>
                <div class="d-flex align-items-center gap-2 fw-semibold"><span class="fs-3">⭐ <?= number_format($level['stars']) ?></span><span class="opacity-75">ดาว · Level <?= $level['level'] ?></span></div>
                <div class="act-progress mt-2" role="progressbar" aria-label="ความคืบหน้าไป Level ถัดไป" aria-valuenow="<?= $level['percent'] ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $level['percent'] ?>%"></span></div>
                <div class="small mt-1 opacity-75">อีก <?= $level['to'] - $level['stars'] ?> ดาว ถึง Level <?= $level['level'] + 1 ?></div>
            </div>
            <div class="act-badges" aria-label="เหรียญรางวัล">
                <?php foreach ($badges as $key => [$name, $icon, $color, $rule]): $has = isset($earned[$key]); ?>
                    <span class="act-badge <?= $has ? '' : 'locked' ?>" style="--c:<?= $color ?>" title="<?= e($name . ' — ' . $rule . ($has ? ' ✓' : ' (ยังไม่ได้)')) ?>"><i class="bi <?= $has ? $icon : 'bi-lock-fill' ?>"></i><small><?= e($name) ?></small></span>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ตัวเลขสรุป -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><div class="act-stat"><div class="act-ring" style="--p:<?= $donePct ?>"><span><?= $donePct ?>%</span></div><div><b><?= count($activities['done']) ?>/<?= $required ?></b><div class="small text-muted">กิจกรรมที่ทำแล้ว</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="act-stat"><span class="act-icon bg-danger-subtle text-danger"><i class="bi bi-list-task"></i></span><div><b><?= count($activities['todo']) ?></b><div class="small text-muted">ยังไม่ได้ทำ</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="act-stat"><span class="act-icon bg-primary-subtle text-primary"><i class="bi bi-award"></i></span><div><b><?= $avg === null ? '–' : $avg . '%' ?></b><div class="small text-muted">คะแนนใบงานเฉลี่ย</div></div></div></div>
        <div class="col-6 col-lg-3"><div class="act-stat"><span class="act-icon bg-success-subtle text-success"><i class="bi bi-patch-check"></i></span><div><b><?= count($earned) ?>/<?= count($badges) ?></b><div class="small text-muted">เหรียญรางวัล</div></div></div></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <h2 class="h5"><i class="bi bi-hourglass-split text-danger"></i> ยังไม่ได้ทำ <span class="badge bg-danger rounded-pill"><?= count($activities['todo']) ?></span></h2>
            <?php foreach ($activities['todo'] as $a): $b = $a['board']; ?>
                <div class="act-card act-todo <?= $a['window'] === 'late' ? 'is-late' : '' ?>">
                    <span class="act-icon"><i class="bi <?= BOARD_LAYOUTS[$b['layout']][1] ?? 'bi-easel2' ?>"></i></span>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold"><?= e($b['title']) ?><?= $a['revision'] ? ' <span class="badge bg-danger">ครูให้แก้ไข</span>' : '' ?></div>
                        <div class="small text-muted"><?= $a['lesson'] ? e($a['lesson']) . ' · ' : '' ?><?= $b['is_assignment'] ? 'ใบงาน ' . e($b['max_points']) . ' คะแนน' : 'กิจกรรมร่วมคิด' ?></div>
                        <?php if ($a['revision'] && $a['feedback']): ?><div class="small text-danger"><i class="bi bi-chat-square-text"></i> <?= e($a['feedback']) ?></div><?php endif; ?>
                        <div class="mt-1"><?= due_chip($a) ?></div>
                    </div>
                    <a class="btn btn-vt text-nowrap" href="<?= url('board.php?id=' . $b['id']) ?>"><?= $a['revision'] ? 'แก้ไขงาน' : 'ไปทำเลย' ?> <i class="bi bi-arrow-right"></i></a>
                </div>
            <?php endforeach; ?>
            <?php if (!$activities['todo']): ?><div class="act-empty"><i class="bi bi-emoji-sunglasses fs-1 text-success"></i><div class="fw-semibold mt-1">ทำครบทุกกิจกรรมที่เปิดอยู่แล้ว เก่งมาก!</div></div><?php endif; ?>

            <?php if ($activities['upcoming']): ?>
                <h2 class="h6 mt-4 text-muted"><i class="bi bi-calendar-event"></i> กำลังจะเปิด</h2>
                <?php foreach ($activities['upcoming'] as $a): ?><div class="act-card"><span class="act-icon"><i class="bi bi-lock"></i></span><div class="flex-grow-1"><div class="fw-semibold"><?= e($a['board']['title']) ?></div><div class="small text-muted">เปิดรับ <?= e(thai_date($a['board']['opens_at'], true)) ?></div></div></div><?php endforeach; ?>
            <?php endif; ?>

            <h2 class="h5 mt-4"><i class="bi bi-check2-circle text-success"></i> ทำแล้ว <span class="badge bg-success rounded-pill"><?= count($activities['done']) ?></span></h2>
            <?php foreach ($activities['done'] as $a): $b = $a['board']; ?>
                <a class="act-card act-done text-decoration-none text-body" href="<?= url('board.php?id=' . $b['id']) ?>">
                    <span class="act-icon"><i class="bi bi-check-lg"></i></span>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold"><?= e($b['title']) ?></div>
                        <div class="small text-muted">ส่งเมื่อ <?= e(thai_date($a['first'], true)) ?><?= $a['late'] ? ' · <span class="text-warning-emphasis">ส่งเกินเวลา</span>' : '' ?><?= $a['pending'] ? ' · รอครูอนุมัติ' : '' ?> · <?= $a['posts'] ?> โพสต์<?= $a['comments'] ? ' · ' . $a['comments'] . ' ความคิดเห็น' : '' ?></div>
                        <?php if ($a['feedback']): ?><div class="small"><i class="bi bi-chat-quote"></i> ครู: <?= e($a['feedback']) ?></div><?php endif; ?>
                    </div>
                    <?php if ($b['is_assignment']): ?><span class="act-score <?= $a['score'] === null ? 'wait' : '' ?>"><?= $a['score'] === null ? 'รอตรวจ' : e(rtrim(rtrim($a['score'], '0'), '.')) . '<small>/' . e(rtrim(rtrim($b['max_points'], '0'), '.')) . '</small>' ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
            <?php if (!$activities['done']): ?><p class="text-muted small">ยังไม่มี — เริ่มจากกิจกรรมด้านบนได้เลย</p><?php endif; ?>

            <?php if ($activities['missed']): ?>
                <h2 class="h6 mt-4 text-muted"><i class="bi bi-x-circle"></i> ปิดรับแล้วแต่ยังไม่ได้ส่ง</h2>
                <?php foreach ($activities['missed'] as $a): ?><a class="act-card act-missed text-decoration-none text-body" href="<?= url('board.php?id=' . $a['board']['id']) ?>"><span class="act-icon"><i class="bi bi-x-lg"></i></span><div class="flex-grow-1"><div class="fw-semibold"><?= e($a['board']['title']) ?></div><div class="small text-muted">ติดต่อครูผู้สอนหากต้องการส่งงานย้อนหลัง</div></div></a><?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 mb-4"><div class="card-body">
                <h2 class="h6"><i class="bi bi-stars text-warning"></i> ดาวล่าสุด</h2>
                <?php foreach ($history as $x): ?><div class="d-flex justify-content-between small py-1 border-bottom"><span><?= e(STAR_SOURCES[$x['source']] ?? $x['source']) ?><span class="d-block text-muted"><?= e($x['board_title'] ?? '') ?></span></span><b class="text-warning-emphasis text-nowrap">+<?= (int)$x['points'] ?> ⭐</b></div><?php endforeach; ?>
                <?php if (!$history): ?><p class="small text-muted mb-0">ยังไม่มีดาว — โพสต์ แสดงความคิดเห็น และส่งงานให้ทันเวลาเพื่อสะสมดาว</p><?php endif; ?>
                <details class="small mt-2"><summary>วิธีได้ดาว</summary>
                    <ul class="mb-0 mt-1"><li>โพสต์บนกระดาน +<?= XP_RULES['post'] ?> (สูงสุด <?= XP_RULES['post_cap'] ?> โพสต์ต่อกระดาน)</li><li>แสดงความคิดเห็นให้เพื่อน +<?= XP_RULES['comment'] ?> (วันละไม่เกิน <?= XP_RULES['comment_cap_daily'] ?>)</li>
                        <li>เพื่อนกดถูกใจ/โหวต/ให้ดาว +<?= XP_RULES['reaction'] ?></li><li>ส่งใบงานทันเวลา +<?= XP_RULES['ontime'] ?></li><li>คะแนนใบงาน สูงสุด +<?= XP_RULES['grade'] ?></li><li>ครูมอบดาว +<?= XP_RULES['star'] ?></li></ul></details>
            </div></div>
            <?php if ($top): ?>
                <div class="card border-0 shadow-sm rounded-4"><div class="card-body">
                    <h2 class="h6"><i class="bi bi-trophy text-warning"></i> อันดับในรายวิชา</h2>
                    <?php foreach ($top as $i => $r): ?><div class="d-flex align-items-center gap-2 py-1 <?= (int)$r['id'] === $uid ? 'fw-semibold' : '' ?>"><span style="width:1.6rem"><?= ['🥇', '🥈', '🥉'][$i] ?? $i + 1 ?></span><span class="avatar-wrap"><img src="<?= e(avatar_url($r)) ?>" class="avatar-xs" alt=""><span class="avatar-level"><?= star_level((int)$r['xp'])['level'] ?></span></span><span class="flex-grow-1 text-truncate ms-1"><?= e($r['fullname']) ?></span><span class="text-nowrap">⭐ <?= (int)$r['xp'] ?></span></div><?php endforeach; ?>
                    <a class="btn btn-sm btn-outline-warning w-100 mt-2" href="<?= url('leaderboard.php?course_id=' . $courseId) ?>">ดูตารางอันดับทั้งหมด</a>
                </div></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
