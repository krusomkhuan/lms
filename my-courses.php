<?php
require_once __DIR__ . '/includes/bootstrap.php';

$me = require_login();
$uid = (int)$me['id'];

$preview = student_preview();
$learning = $preview
    // มุมมองสำหรับผู้เรียน: แสดงวิชาที่ admin/ครูจัดการ เสมือนเป็นวิชาที่เรียนอยู่
    ? db_all(course_list_sql() . " WHERE c.status = 'published'" . ($me['role'] === 'admin' ? '' : ' AND c.id IN (SELECT course_id FROM course_teachers WHERE teacher_id = ?)') . ' ORDER BY c.code', $me['role'] === 'admin' ? [] : [$uid])
    : db_all(course_list_sql() . " WHERE c.status = 'published' AND c.id IN " . student_course_ids_sql() . ' ORDER BY c.code', [$uid, $uid]);
foreach ($learning as &$c) {
    $c['progress'] = course_progress((int)$c['id'], $uid);
}
unset($c);

$teaching = !$preview && in_array($me['role'], ['teacher', 'admin'], true)
    ? db_all(course_list_sql() . ' WHERE c.id IN (SELECT course_id FROM course_teachers WHERE teacher_id = ?) ORDER BY c.code', [$uid])
    : [];

$pageTitle = 'วิชาของฉัน';
require __DIR__ . '/includes/header.php';
?>
<div class="container py-4">
    <?php if ($teaching): ?>
        <h4 class="mb-3"><i class="bi bi-person-video3 text-vt"></i> รายวิชาที่ฉันสอน</h4>
        <div class="row g-4 mb-5">
            <?php foreach ($teaching as $c) include __DIR__ . '/includes/course_card.php'; ?>
        </div>
    <?php endif; ?>

    <?php $pendingWork = $me['role'] === 'student' ? student_pending_work($uid) : []; ?>
    <?php if ($pendingWork): $pendingLate = count(array_filter($pendingWork, fn($w) => $w['late'])); ?>
        <div class="alert <?= $pendingLate ? 'alert-danger' : 'alert-warning' ?> mb-4">
            <div class="fw-semibold mb-2"><i class="bi bi-exclamation-triangle-fill"></i> คุณมีงานค้างส่ง <?= count($pendingWork) ?> งาน<?= $pendingLate ? ' (เลยกำหนดแล้ว ' . $pendingLate . ' งาน)' : '' ?></div>
            <ul class="mb-0 ps-3">
                <?php foreach ($pendingWork as $w): ?>
                    <li class="mb-1"><a class="alert-link" href="<?= e(pending_work_url($w)) ?>"><?= e($w['title']) ?></a>
                        <span class="small">· <?= e($w['course']) ?> · <span class="<?= $w['late'] ? 'fw-semibold' : '' ?>"><?= e(pending_work_due($w)) ?></span><?= $w['revision'] ? ' · ครูให้แก้ไขแล้วส่งใหม่' : '' ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <h4 class="mb-3"><i class="bi bi-journal-bookmark text-vt"></i> รายวิชาที่กำลังเรียน</h4>
    <div class="row g-4">
        <?php foreach ($learning as $c) include __DIR__ . '/includes/course_card.php'; ?>
        <?php if (!$learning): ?>
            <div class="col-12 text-center text-muted py-5">
                <i class="bi bi-journal-x fs-1 d-block mb-2"></i>ยังไม่มีรายวิชา
                <div class="mt-2"><a href="<?= url('courses.php') ?>" class="btn btn-vt">เลือกดูรายวิชา</a></div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
