<?php
// การ์ดรายวิชา ($c = แถวจาก course_list_sql())
?>
<div class="col-sm-6 col-lg-4 col-xl-3">
    <a href="<?= url('course.php?id=' . $c['id']) ?>" class="card course-card shadow-sm text-reset">
        <img src="<?= e(course_cover($c)) ?>" class="cover" alt="">
        <div class="card-body">
            <div class="small mb-1"><span class="badge bg-light text-vt border"><?= e($c['code']) ?></span></div>
            <h6 class="card-title fw-semibold mb-2"><?= e($c['title']) ?></h6>
            <div class="small text-muted text-truncate"><i class="bi bi-person-video3"></i> <?= e($c['teachers'] ?: 'ยังไม่ระบุผู้สอน') ?></div>
        </div>
        <div class="card-footer bg-white border-0 small text-muted d-flex justify-content-between">
            <span><i class="bi bi-collection-play"></i> <?= (int)$c['lesson_count'] ?> บทเรียน</span>
            <?php if (isset($c['progress'])): ?>
                <span class="text-success fw-semibold"><?= (int)$c['progress'] ?>%</span>
            <?php elseif ($c['enroll_type'] === 'group'): ?>
                <span><i class="bi bi-people"></i> เฉพาะกลุ่ม</span>
            <?php else: ?>
                <span class="text-success"><i class="bi bi-unlock"></i> เปิดลงทะเบียน</span>
            <?php endif; ?>
        </div>
    </a>
</div>
