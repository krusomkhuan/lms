<?php
require_once __DIR__ . '/includes/bootstrap.php';
$me=require_role('student');
$results=gradebook_ready() ? db_all('SELECT r.*,c.code,c.title,g.created_at FROM grade_results r JOIN grade_runs g ON g.id=r.run_id JOIN courses c ON c.id=g.course_id WHERE r.user_id=? AND r.run_id=(SELECT MAX(r2.run_id) FROM grade_results r2 JOIN grade_runs g2 ON g2.id=r2.run_id WHERE r2.user_id=r.user_id AND g2.course_id=g.course_id) ORDER BY c.code',[(int)$me['id']]) : [];
$pageTitle='ผลการเรียนของฉัน';
require __DIR__ . '/includes/header.php';
?>
<main class="container py-4"><h1 class="h4 mb-3">ผลการเรียนของฉัน</h1><p>แสดงผลเกรดล่าสุดที่ครูบันทึกและประกาศแล้ว</p>
<div class="table-responsive"><table class="table table-bordered"><thead><tr><th>รหัสวิชา</th><th>รายวิชา</th><th>คะแนนรวม</th><th>เกรด</th><th>ประกาศเมื่อ</th></tr></thead><tbody><?php foreach ($results as $result): ?><tr><td><?= e($result['code']) ?></td><td><?= e($result['title']) ?></td><td><?= e($result['total']) ?> / 100</td><td><strong><?= e($result['grade_label']) ?></strong></td><td><?= e(thai_date($result['created_at'],true)) ?></td></tr><?php endforeach; ?><?php if (!$results): ?><tr><td colspan="5">ยังไม่มีผลเกรดที่ครูประกาศ</td></tr><?php endif; ?></tbody></table></div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
