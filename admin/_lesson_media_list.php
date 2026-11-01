<?php
// แสดงเฉพาะภายใต้หน้าแก้ไขบทเรียนที่ตรวจสิทธิ์แล้ว
if (!isset($lesson, $courseId, $me) || !can_manage_course((int)$courseId)) {
    http_response_code(403);
    exit;
}
$types = media_types();
$extraMedia = lesson_media_ready() ? db_all('SELECT * FROM lesson_media WHERE lesson_id = ? ORDER BY sort_order, id', [(int)$lesson['id']]) : [];
$lessonQuizzes = quiz_ready() ? lesson_quizzes($lesson) : [];
$quizBadge = function (?array $row) use ($lessonQuizzes, $lesson) {
    $q = $lessonQuizzes[(int)$lesson['id'] . ':' . (int)($row['id'] ?? 0)] ?? null;
    return $q && $q['kind'] ? ' <span class="badge ' . ($q['kind'] === 'pre' ? 'bg-warning text-dark' : 'bg-success') . '">' . e(QUIZ_KINDS[$q['kind']]) . '</span>' : '';
};
?>
<section class="card border-0 shadow-sm rounded-4 mt-4" id="media-list">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="h5 mb-0">สื่อในบทเรียน <span class="badge bg-secondary"><?= count($extraMedia) + 1 ?> รายการ</span></h2>
            <?php if (lesson_media_ready()): ?><a class="btn btn-vt" href="<?= url('admin/lesson_form.php?lesson_id=' . $lesson['id']) ?>"><i class="bi bi-plus-lg"></i> เพิ่มสื่อในบทเรียนนี้</a><?php endif; ?>
        </div>
        <p class="small text-muted">เพิ่มได้หลายรายการและซ้ำชนิดได้ เช่น วิดีโอ 2 รายการ และ PDF 3 ไฟล์ สื่อทั้งหมดแสดงแยกในเมนูซ้ายของบทเรียน ผู้เรียนเลือกเปิดดูทีละรายการ</p>
        <?php if (!lesson_media_ready()): ?><div class="alert alert-warning">กรุณารัน database/migrations/1.10.0.sql เพื่อเปิดใช้สื่อหลายรายการ</div><?php endif; ?>
        <?php if (quiz_ready()): ?>
        <div class="row g-2 mb-3">
            <?php foreach (['pre' => ['bi-1-circle', 'warning'], 'post' => ['bi-2-circle', 'success']] as $kind => [$icon, $color]):
                $found = array_filter($lessonQuizzes, fn($q) => $q['kind'] === $kind); ?>
                <div class="col-md-6"><div class="border border-<?= $color ?> rounded-3 p-3 h-100">
                    <div class="fw-semibold mb-1"><i class="bi <?= $icon ?> text-<?= $color ?>"></i> <?= e(QUIZ_KINDS[$kind]) ?></div>
                    <?php foreach ($found as $key => $q): ?>
                        <div class="small mb-2">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <a href="<?= url($q['media_id'] ? 'admin/lesson_form.php?media_id=' . $q['media_id'] : 'admin/lesson_form.php?id=' . $lesson['id']) ?>"><?= e($q['title']) ?></a>
                                <?php if ($q['media_id']): ?>
                                    <form method="post" action="<?= url('admin/lesson_media_action.php') ?>"><?= csrf_field() ?><input type="hidden" name="media_id" value="<?= (int)$q['media_id'] ?>">
                                        <button class="btn btn-sm text-nowrap <?= $q['published'] ? 'btn-outline-success' : 'btn-outline-secondary' ?>" name="action" value="toggle" title="กดเพื่อ<?= $q['published'] ? 'ซ่อนจาก' : 'แสดงให้' ?>ผู้เรียน"><i class="bi <?= $q['published'] ? 'bi-eye' : 'bi-eye-slash' ?>"></i> <?= $q['published'] ? 'แสดง' : 'ซ่อน' ?></button></form>
                                <?php else: ?>
                                    <form method="post" action="<?= url('admin/lessons.php?course_id=' . (int)$courseId) ?>" data-confirm="แบบทดสอบนี้เป็นเนื้อหาหลักของบทเรียน การ<?= $q['published'] ? 'ซ่อน' : 'แสดง' ?>จะมีผลกับทั้งบทเรียน ยืนยัน?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$lesson['id'] ?>"><input type="hidden" name="back" value="form">
                                        <button class="btn btn-sm text-nowrap <?= $q['published'] ? 'btn-outline-success' : 'btn-outline-secondary' ?>" name="action" value="toggle" title="แสดง/ซ่อนทั้งบทเรียน"><i class="bi <?= $q['published'] ? 'bi-eye' : 'bi-eye-slash' ?>"></i> <?= $q['published'] ? 'แสดง' : 'ซ่อน' ?> (ทั้งบท)</button></form>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted"><?= e(quiz_summary($q['config'])) ?></div>
                            <?php if ($kind === 'post'): $pairTitle = $q['config']['pair'] ? (grade_sources((int)$courseId)['quiz:' . $q['config']['pair']]['title'] ?? null) : null; ?>
                                <div class="<?= $pairTitle ? 'text-muted' : 'text-danger' ?>"><i class="bi bi-link-45deg"></i> <?= $pairTitle ? 'เปรียบเทียบกับ: ' . e($pairTitle) : 'ยังไม่ได้เลือกแบบทดสอบก่อนเรียนที่ใช้เปรียบเทียบ' ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$found && lesson_media_ready()): ?>
                        <div class="small text-muted mb-2">ยังไม่มีในบทเรียนนี้</div>
                        <a class="btn btn-sm btn-outline-<?= $color ?>" href="<?= url('admin/lesson_form.php?lesson_id=' . $lesson['id'] . '&quiz=' . $kind) ?>"><i class="bi bi-plus-lg"></i> สร้าง<?= e(QUIZ_KINDS[$kind]) ?></a>
                    <?php endif; ?>
                </div></div>
            <?php endforeach; ?>
        </div>
        <a class="btn btn-outline-primary mb-3" href="<?= url('admin/prepost.php?course_id=' . (int)$courseId) ?>">ผลก่อน–หลังเรียน</a>
        <a class="btn btn-outline-primary mb-3" href="<?= url('admin/quiz_results.php?lesson_id=' . $lesson['id']) ?>">ดูคะแนนแบบทดสอบ</a><?php endif; ?>
        <?php if (assignments_ready()): ?><a class="btn btn-outline-success mb-3" href="<?= url('admin/assignments.php?lesson_id=' . $lesson['id']) ?>">ตรวจงานและดูคะแนนการส่งงาน</a><?php endif; ?>
        <div class="list-group">
            <div class="list-group-item d-flex flex-wrap align-items-center gap-2">
                <i class="bi <?= $types[$lesson['media_type']]['icon'] ?>"></i>
                <span class="flex-grow-1 text-break" style="flex:1 1 14rem;min-width:0"><?= e($lesson['title']) ?><?= $quizBadge(null) ?> <span class="small text-muted">(เนื้อหาหลัก · แก้ไขด้านบน)</span></span>
                <a class="btn btn-sm btn-outline-secondary" href="<?= url('lesson.php?id=' . $lesson['id']) ?>" target="_blank" rel="noopener">ดูบทเรียน</a>
            </div>
            <?php foreach ($extraMedia as $n => $item): ?>
                <div class="list-group-item d-flex flex-wrap align-items-center gap-2">
                    <i class="bi <?= $types[$item['media_type']]['icon'] ?>"></i>
                    <span class="flex-grow-1 text-break" style="flex:1 1 14rem;min-width:0"><?= e($item['title']) ?><?= $quizBadge($item) ?> <span class="small text-muted"><?= e($types[$item['media_type']]['label']) ?></span></span>
                    <form method="post" action="<?= url('admin/lesson_media_action.php') ?>" class="d-flex gap-1">
                        <?= csrf_field() ?><input type="hidden" name="media_id" value="<?= (int)$item['id'] ?>">
                        <button class="btn btn-sm btn-light" name="action" value="up" aria-label="เลื่อนสื่อขึ้น" <?= $n === 0 ? 'disabled' : '' ?>>↑</button>
                        <button class="btn btn-sm btn-light" name="action" value="down" aria-label="เลื่อนสื่อลง" <?= $n === count($extraMedia) - 1 ? 'disabled' : '' ?>>↓</button>
                        <button class="btn btn-sm <?= $item['is_published'] ? 'btn-outline-success' : 'btn-outline-secondary' ?>" name="action" value="toggle"><?= $item['is_published'] ? 'แสดง' : 'ซ่อน' ?></button>
                    </form>
                    <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/lesson_form.php?media_id=' . $item['id']) ?>">แก้ไข</a>
                    <form method="post" action="<?= url('admin/lesson_media_action.php') ?>" data-confirm="ลบสื่อรายการนี้?">
                        <?= csrf_field() ?><input type="hidden" name="media_id" value="<?= (int)$item['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger" name="action" value="delete">ลบ</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
