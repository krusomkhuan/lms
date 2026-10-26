<?php
require_once __DIR__ . '/includes/bootstrap.php';

$id = get_int('id');
$course = db_one(course_list_sql() . ' WHERE c.id = ?', [$id]);
$me = current_user();
$canManage = $course && can_manage_course($id);
if (!$course || ($course['status'] !== 'published' && !$canManage)) {
    http_response_code(404);
    exit('ไม่พบรายวิชา');
}

$enrolled = $me && is_enrolled($id, (int)$me['id']);
$selfEnrolled = $me && db_value('SELECT 1 FROM enrollments WHERE course_id = ? AND user_id = ?', [$id, (int)$me['id']]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $me = require_login();
    if (post('action') === 'enroll' && $course['enroll_type'] === 'open' && !$enrolled) {
        db_exec('INSERT IGNORE INTO enrollments (course_id, user_id) VALUES (?, ?)', [$id, (int)$me['id']]);
        flash('success', 'ลงทะเบียนเรียนเรียบร้อย เริ่มเรียนได้เลย!');
    } elseif (post('action') === 'unenroll' && $selfEnrolled) {
        db_exec('DELETE FROM enrollments WHERE course_id = ? AND user_id = ?', [$id, (int)$me['id']]);
        flash('info', 'ยกเลิกการลงทะเบียนแล้ว');
    }
    redirect('course.php?id=' . $id);
}

$lessons = db_all('SELECT id, title, media_type, is_published FROM lessons WHERE course_id = ?' . ($canManage ? '' : ' AND is_published = 1') . ' ORDER BY sort_order, id', [$id]);
$teachers = db_all('SELECT u.fullname, u.email, u.avatar FROM course_teachers ct JOIN users u ON u.id = ct.teacher_id WHERE ct.course_id = ?', [$id]);
$groups = db_all('SELECT g.name FROM course_groups cg JOIN student_groups g ON g.id = cg.group_id WHERE cg.course_id = ?', [$id]);
$done = $me ? array_column(db_all('SELECT lesson_id FROM lesson_progress WHERE user_id = ?', [(int)$me['id']]), 'lesson_id') : [];
$progress = $enrolled ? course_progress($id, (int)$me['id']) : 0;
$types = media_types();

$pageTitle = $course['title'];
require __DIR__ . '/includes/header.php';
?>
<div class="bg-white border-bottom">
    <div class="container py-4">
        <div class="row g-4 align-items-center">
            <div class="col-md-4"><img src="<?= e(course_cover($course)) ?>" class="img-fluid rounded-4 shadow-sm" alt=""></div>
            <div class="col-md-8">
                <nav class="small mb-2"><a href="<?= url('courses.php') ?>">รายวิชาทั้งหมด</a></nav>
                <span class="badge bg-primary-subtle text-primary mb-2"><?= e($course['code']) ?></span>
                <?php if ($course['status'] === 'draft'): ?><span class="badge bg-secondary mb-2">ฉบับร่าง</span><?php endif; ?>
                <h2 class="fw-semibold"><?= e($course['title']) ?></h2>
                <p class="text-muted"><?= nl2br(e($course['description'])) ?></p>
                <div class="small text-muted mb-3">
                    <?php if ($course['credits']): ?><span class="me-3"><i class="bi bi-award"></i> หน่วยกิต <?= e($course['credits']) ?></span><?php endif; ?>
                    <span class="me-3"><i class="bi bi-collection-play"></i> <?= count($lessons) ?> บทเรียน</span>
                </div>

                <?php if ($enrolled): ?>
                    <div class="d-flex align-items-center gap-3 mb-3" style="max-width:420px">
                        <div class="progress flex-grow-1" style="height:10px"><div class="progress-bar bg-success" style="width:<?= $progress ?>%"></div></div>
                        <span class="small fw-semibold"><?= $progress ?>%</span>
                    </div>
                <?php endif; ?>

                <div class="d-flex flex-wrap gap-2">
                    <?php if ($enrolled || $canManage): ?>
                        <?php if ($lessons): ?>
                            <a href="<?= url('lesson.php?id=' . $lessons[0]['id']) ?>" class="btn btn-vt px-4"><i class="bi bi-play-fill"></i> <?= $progress > 0 ? 'เรียนต่อ' : 'เริ่มเรียน' ?></a>
                        <?php endif; ?>
                        <?php if ($canManage): ?>
                            <a href="<?= url('admin/lessons.php?course_id=' . $id) ?>" class="btn btn-outline-primary"><i class="bi bi-pencil-square"></i> จัดการบทเรียน</a>
                        <?php endif; ?>
                        <?php if ($selfEnrolled): ?>
                            <form method="post" onsubmit="return confirm('ยืนยันยกเลิกการลงทะเบียน?')">
                                <?= csrf_field() ?><input type="hidden" name="action" value="unenroll">
                                <button class="btn btn-outline-danger">ยกเลิกลงทะเบียน</button>
                            </form>
                        <?php endif; ?>
                    <?php elseif ($course['enroll_type'] === 'open'): ?>
                        <?php if ($me): ?>
                            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="enroll">
                                <button class="btn btn-warning px-4"><i class="bi bi-box-arrow-in-right"></i> ลงทะเบียนเรียน</button></form>
                        <?php else: ?>
                            <a href="<?= url('login.php?next=' . urlencode($_SERVER['REQUEST_URI'])) ?>" class="btn btn-warning px-4">เข้าสู่ระบบเพื่อลงทะเบียน</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-info mb-0 py-2"><i class="bi bi-people"></i> รายวิชานี้เปิดให้เฉพาะกลุ่มผู้เรียนที่ได้รับสิทธิ์
                            <?= $groups ? '(' . e(implode(', ', array_column($groups, 'name'))) . ')' : '' ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container py-4">
    <div class="row g-4">
        <div class="col-lg-8">
            <h5 class="mb-3">เนื้อหารายวิชา</h5>
            <div class="list-group shadow-sm rounded-4">
                <?php foreach ($lessons as $i => $l): $t = $types[$l['media_type']]; ?>
                    <?php $open = $enrolled || $canManage; ?>
                    <a <?= $open ? 'href="' . url('lesson.php?id=' . $l['id']) . '"' : '' ?> class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3 <?= $open ? '' : 'disabled' ?>">
                        <span class="badge rounded-pill bg-<?= $t['color'] ?>-subtle text-<?= $t['color'] ?> p-2"><i class="bi <?= $t['icon'] ?>"></i></span>
                        <div class="flex-grow-1">
                            <div><?= e($l['title']) ?> <?= $l['is_published'] ? '' : '<span class="badge bg-secondary">ซ่อน</span>' ?></div>
                            <div class="small text-muted"><?= e($t['label']) ?></div>
                        </div>
                        <?php if (in_array($l['id'], $done)): ?><i class="bi bi-check-circle-fill text-success"></i>
                        <?php elseif (!$open): ?><i class="bi bi-lock text-muted"></i><?php endif; ?>
                    </a>
                <?php endforeach; ?>
                <?php if (!$lessons): ?><div class="list-group-item text-muted py-4 text-center">ยังไม่มีบทเรียน</div><?php endif; ?>
            </div>
        </div>
        <div class="col-lg-4">
            <h5 class="mb-3">ครูผู้สอน</h5>
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body">
                    <?php foreach ($teachers as $t): ?>
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <img src="<?= e(avatar_url($t)) ?>" class="rounded-circle" style="width:48px;height:48px;object-fit:cover" alt="">
                            <div><div class="fw-semibold"><?= e($t['fullname']) ?></div><div class="small text-muted"><?= e($t['email']) ?></div></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$teachers): ?><div class="text-muted small">ยังไม่ระบุครูผู้สอน</div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
