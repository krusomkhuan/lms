<?php
require_once __DIR__ . '/includes/bootstrap.php';

$me = require_login();
$uid = (int)$me['id'];
$preview = student_preview();
if ($me['role'] !== 'student' && !$preview) redirect('my-courses.php');
$courses = $preview
    ? db_all("SELECT c.id, c.code, c.title FROM courses c WHERE c.status = 'published'" . ($me['role'] === 'admin' ? '' : ' AND c.id IN (SELECT course_id FROM course_teachers WHERE teacher_id = ?)') . ' ORDER BY c.code', $me['role'] === 'admin' ? [] : [$uid])
    : db_all("SELECT c.id, c.code, c.title FROM courses c WHERE c.status = 'published' AND c.id IN " . student_course_ids_sql() . ' ORDER BY c.code', [$uid, $uid]);
$courseIds = array_column($courses, 'id');
$courseId = in_array(get_int('course_id'), array_map('intval', $courseIds), true) ? get_int('course_id') : (int)($courseIds[0] ?? 0);
$course = null;
foreach ($courses as $c) if ((int)$c['id'] === $courseId) $course = $c;

if ($course) {
    $lessonProgress = course_progress($courseId, $uid);
    $lessonTotal = (int)db_value('SELECT COUNT(*) FROM lessons WHERE course_id = ? AND is_published = 1', [$courseId]);
    $lessonDone = (int)round($lessonProgress * $lessonTotal / 100);
    $items = progress_items($courseId);
    $results = progress_results($courseId, $uid)[$uid] ?? [];
    $summary = progress_summary($items, $results);
    $pairs = quiz_ready() ? prepost_pairs($courseId) : [];
    $grade = gradebook_ready() ? db_one('SELECT r.total, r.grade_label, g.created_at FROM grade_results r JOIN grade_runs g ON g.id = r.run_id WHERE r.user_id = ? AND g.course_id = ? ORDER BY r.run_id DESC LIMIT 1', [$uid, $courseId]) : null;
}
$statusLabels = ['graded' => ['ตรวจแล้ว', 'success'], 'submitted' => ['รอตรวจ', 'warning'], 'revision' => ['ให้แก้ไข', 'danger']];

$pageTitle = 'ความก้าวหน้าของฉัน';
require __DIR__ . '/includes/header.php';
?>
<div class="container py-4">
    <h1 class="h4 mb-3"><i class="bi bi-graph-up-arrow text-vt"></i> ความก้าวหน้าของฉัน</h1>
    <?php if (!$courses): ?>
        <div class="text-center text-muted py-5"><i class="bi bi-journal-x fs-1 d-block mb-2"></i>ยังไม่มีรายวิชาที่กำลังเรียน</div>
    <?php else: ?>
    <ul class="nav nav-pills flex-nowrap overflow-auto mb-4 pb-1">
        <?php foreach ($courses as $c): ?><li class="nav-item"><a class="nav-link text-nowrap <?= (int)$c['id'] === $courseId ? 'active' : '' ?>" href="?course_id=<?= (int)$c['id'] ?>"><?= e($c['code']) ?> <?= e($c['title']) ?></a></li><?php endforeach; ?>
    </ul>

    <div class="row g-3 mb-4">
        <?php
        $tiles = [
            ['bi-journal-check', 'บทเรียนที่เรียนจบ', $lessonDone . ' / ' . $lessonTotal, $lessonProgress, 'primary'],
            ['bi-ui-checks', 'แบบทดสอบที่ทำแล้ว', $summary['quiz_done'] . ' / ' . $summary['quiz_total'], $summary['quiz_total'] ? round($summary['quiz_done'] * 100 / $summary['quiz_total']) : null, 'info'],
            ['bi-send-check', 'งานที่ส่งแล้ว', $summary['assignment_done'] . ' / ' . $summary['assignment_total'] . ($summary['assignment_pending'] ? ' (รอตรวจ ' . $summary['assignment_pending'] . ')' : ''), $summary['assignment_total'] ? round($summary['assignment_done'] * 100 / $summary['assignment_total']) : null, 'warning'],
            ['bi-trophy', 'คะแนนเฉลี่ย (ดีที่สุด)', $summary['average'] === null ? '—' : $summary['average'] . '%', $summary['average'], progress_color($summary['average'])],
        ];
        foreach ($tiles as [$icon, $label, $value, $bar, $color]): ?>
            <div class="col-6 col-lg-3"><div class="card stat-card shadow-sm h-100"><div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-2"><div class="icon bg-<?= $color ?>-subtle text-<?= $color ?>"><i class="bi <?= $icon ?>"></i></div><div><div class="small text-muted"><?= $label ?></div><div class="fs-5 fw-semibold"><?= e((string)$value) ?></div></div></div>
                <?php if ($bar !== null): ?><div class="progress" style="height:6px" role="progressbar" aria-label="<?= $label ?>" aria-valuenow="<?= (float)$bar ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-<?= $color ?>" style="width:<?= (float)$bar ?>%"></div></div><?php endif; ?>
            </div></div></div>
        <?php endforeach; ?>
    </div>

    <?php if ($grade): ?>
        <div class="alert alert-success d-flex justify-content-between align-items-center"><span><i class="bi bi-award"></i> ผลการเรียนที่ครูประกาศ: คะแนนรวม <b><?= e($grade['total']) ?></b> / 100 · เกรด <b><?= e($grade['grade_label']) ?></b></span><small><?= e(thai_date($grade['created_at'], true)) ?></small></div>
    <?php endif; ?>

    <?php if ($pairs): ?>
    <div class="card border-0 shadow-sm rounded-4 mb-4"><div class="card-body">
        <h2 class="h6 mb-3"><i class="bi bi-arrow-up-right-circle text-success"></i> ผลก่อนเรียน–หลังเรียน</h2>
        <?php foreach ($pairs as $pair):
            $pre = $results['quiz:' . $pair['pre_key']]['first'] ?? null;
            $post = $results['quiz:' . $pair['post_key']]['best'] ?? null; ?>
            <div class="mb-3">
                <div class="small fw-semibold mb-1"><?= e($pair['pre']['title']) ?> → <?= e($pair['post']['title']) ?>
                    <?php if ($pre !== null && $post !== null): $d = round($post - $pre, 1); ?><span class="badge bg-<?= $d > 0 ? 'success' : ($d < 0 ? 'danger' : 'secondary') ?> ms-1"><?= ($d > 0 ? '+' : '') . $d ?>%</span><?php endif; ?></div>
                <?php foreach ([['ก่อนเรียน', $pre, 'secondary'], ['หลังเรียน', $post, 'success']] as [$label, $value, $color]): ?>
                    <div class="d-flex align-items-center gap-2 small mb-1"><span style="width:5.5rem"><?= $label ?></span>
                        <div class="progress flex-grow-1" style="height:14px" role="progressbar" aria-label="<?= $label ?>" aria-valuenow="<?= (float)$value ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-<?= $color ?>" style="width:<?= (float)$value ?>%"></div></div>
                        <span style="width:3.5rem" class="text-end"><?= $value === null ? 'ยังไม่ทำ' : $value . '%' ?></span></div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4"><div class="card-body">
        <h2 class="h6 mb-3"><i class="bi bi-list-check text-vt"></i> คะแนนแบบทดสอบและงาน</h2>
        <?php if (!$items): ?><p class="text-muted mb-0">รายวิชานี้ยังไม่มีแบบทดสอบหรืองาน</p><?php endif; ?>
        <div class="list-group list-group-flush">
        <?php foreach ($items as $key => $item): $r = $results[$key] ?? null; $value = $r['best'] ?? null; ?>
            <a class="list-group-item list-group-item-action px-0" href="<?= url('lesson.php?id=' . $item['lesson_id'] . ($item['media_id'] ? '&media_id=' . $item['media_id'] : '')) ?>">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div>
                        <i class="bi <?= $item['kind'] === 'quiz' ? 'bi-ui-checks text-info' : 'bi-journal-check text-primary' ?>"></i>
                        <span class="fw-semibold"><?= e($item['title']) ?></span>
                        <?php if ($item['quiz_kind']): ?><span class="badge bg-info-subtle text-info"><?= e(QUIZ_KINDS[$item['quiz_kind']]) ?></span><?php endif; ?>
                        <div class="small text-muted"><?= e($item['lesson_title']) ?></div>
                    </div>
                    <div class="text-end small">
                        <?php if (!$r): ?><span class="badge bg-light text-muted border"><?= $item['kind'] === 'quiz' ? 'ยังไม่ทำ' : 'ยังไม่ส่ง' ?></span>
                        <?php elseif ($item['kind'] === 'quiz'): ?><span class="fw-semibold text-<?= progress_color($value) ?>"><?= $value ?>%</span> <span class="text-muted">· ทำ <?= $r['attempts'] ?> ครั้ง · ล่าสุด <?= $r['latest'] ?>%</span>
                        <?php else: [$label, $color] = $statusLabels[$r['status']] ?? ['ส่งแล้ว', 'secondary']; ?><span class="badge bg-<?= $color ?>"><?= $label ?></span><?= $r['late'] ? ' <span class="badge bg-warning text-dark">ส่งล่าช้า</span>' : '' ?><?= $r['latest'] !== null ? ' <span class="fw-semibold text-' . progress_color($r['latest']) . '">' . $r['latest'] . '%</span>' : '' ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($value !== null): ?><div class="progress mt-2" style="height:4px" aria-hidden="true"><div class="progress-bar bg-<?= progress_color($value) ?>" style="width:<?= (float)$value ?>%"></div></div><?php endif; ?>
            </a>
        <?php endforeach; ?>
        </div>
    </div></div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
