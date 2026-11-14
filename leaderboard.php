<?php
// ตารางอันดับดาว ⭐ / Level รายวิชา: นักเรียนเห็นเมื่อครูเปิด (ครูเห็นเสมอ)
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_login();
$courseId = get_int('course_id');
$course = db_one('SELECT * FROM courses WHERE id = ?', [$courseId]);
if (!$course || !boards_ready() || !can_view_course($courseId)) {
    http_response_code(404);
    exit('ไม่พบรายวิชา');
}
$canManage = can_manage_course($courseId);
if (!$canManage && empty($course['leaderboard_enabled'])) {
    flash('info', 'ครูยังไม่เปิดตารางอันดับของรายวิชานี้ คุณดูดาวและ Level ของตนเองได้ที่หน้า “กิจกรรมของฉัน”');
    redirect('my-activities.php?course_id=' . $courseId);
}
$rows = xp_leaderboard($courseId);
$stats = star_stats($courseId);
$badges = star_badges();
$podium = array_slice($rows, 0, 3);
$pageTitle = 'ตารางอันดับ — ' . $course['title'];
require __DIR__ . '/includes/header.php';
?>
<link href="<?= url('assets/css/board.css') ?>?v=<?= (int)@filemtime(ROOT_PATH . '/assets/css/board.css') ?>" rel="stylesheet">
<div class="container py-4">
    <a href="<?= url('course.php?id=' . $courseId) ?>"><i class="bi bi-arrow-left"></i> <?= e($course['code'] . ' ' . $course['title']) ?></a>
    <h1 class="h3 my-3"><i class="bi bi-trophy-fill text-warning"></i> ตารางอันดับดาว</h1>
    <?php if ($canManage): ?><div class="alert alert-light border small">ตารางอันดับ<?= $course['leaderboard_enabled'] ? 'เปิดให้นักเรียนเห็นอยู่' : 'ยังไม่เปิดให้นักเรียนเห็น (ครูเห็นคนเดียว)' ?> · ตั้งค่าได้ที่หน้าแก้ไขรายวิชา</div><?php endif; ?>
    <?php if ($podium): ?>
        <div class="podium mb-4">
            <?php foreach ([1, 0, 2] as $place): if (!isset($podium[$place])) continue; $r = $podium[$place]; $lv = star_level((int)$r['xp']); ?>
                <div class="podium-col place-<?= $place + 1 ?>">
                    <div class="avatar-wrap"><img src="<?= e(avatar_url($r)) ?>" class="podium-avatar" alt=""><span class="avatar-level avatar-level-lg"><?= $lv['level'] ?></span></div>
                    <div class="fw-semibold text-truncate mt-2" style="max-width:9rem"><?= e($r['fullname']) ?></div>
                    <div class="small">⭐ <?= number_format((int)$r['xp']) ?></div>
                    <div class="podium-step"><?= ['🥇', '🥈', '🥉'][$place] ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="card border-0 shadow-sm rounded-4"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th style="width:4rem">อันดับ</th><th>ผู้เรียน</th><th>Level</th><th>เหรียญรางวัล</th><th class="text-end">ดาว</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $r): $lv = star_level((int)$r['xp']); $earned = star_earned(($stats[(int)$r['id']] ?? []) + ['total' => (int)$r['xp']]); ?>
            <tr class="<?= (int)$r['id'] === (int)$me['id'] ? 'table-warning' : '' ?>">
                <td class="fw-semibold"><?= $i < 3 ? ['🥇', '🥈', '🥉'][$i] : $i + 1 ?></td>
                <td><span class="avatar-wrap me-2"><img src="<?= e(avatar_url($r)) ?>" class="avatar-sm" alt=""><span class="avatar-level"><?= $lv['level'] ?></span></span> <?= e($r['fullname']) ?><?= $canManage && $r['student_code'] ? ' <small class="text-muted">' . e($r['student_code']) . '</small>' : '' ?></td>
                <td style="min-width:8rem"><span class="small">Lv.<?= $lv['level'] ?></span><div class="progress" style="height:6px"><div class="progress-bar bg-warning" style="width:<?= $lv['percent'] ?>%"></div></div></td>
                <td><?php foreach ($earned as [$name, $icon, $color]): ?><i class="bi <?= $icon ?> me-1" style="color:<?= $color ?>" title="<?= e($name) ?>" aria-label="<?= e($name) ?>"></i><?php endforeach; ?></td>
                <td class="text-end fw-semibold text-nowrap">⭐ <?= number_format((int)$r['xp']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีใครได้ดาว</td></tr><?php endif; ?>
        </tbody>
    </table></div></div>
    <p class="small text-muted mt-2">เหรียญ: <?php foreach ($badges as [$name, $icon, $color, $rule]): ?><span class="me-2 text-nowrap"><i class="bi <?= $icon ?>" style="color:<?= $color ?>"></i> <?= e($name) ?> (<?= e($rule) ?>)</span><?php endforeach; ?></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
