<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$me=require_role('admin','teacher');
$courseId=get_int('course_id');
$course=db_one('SELECT * FROM courses WHERE id=?',[$courseId]);
if (!$course || !can_manage_course($courseId)) { http_response_code(403); exit('ไม่มีสิทธิ์ดูผลเกรดรายวิชานี้'); }
$runId=get_int('run_id'); $page=max(1,get_int('page'));
$runs=gradebook_ready() ? db_all('SELECT r.*,u.fullname AS teacher FROM grade_runs r LEFT JOIN users u ON u.id=r.created_by WHERE r.course_id=? ORDER BY r.id DESC LIMIT 26 OFFSET '.(($page-1)*25),[$courseId]) : [];
$hasNext=count($runs)>25; $runs=array_slice($runs,0,25);
$run=$runId && gradebook_ready() ? db_one('SELECT * FROM grade_runs WHERE id=? AND course_id=?',[$runId,$courseId]) : null;
if ($runId && !$run) { http_response_code(404); exit('ไม่พบผลเกรด'); }
$results=$run ? db_all('SELECT * FROM grade_results WHERE run_id=? ORDER BY user_id',[$runId]) : [];
$pageTitle='ประวัติผลเกรด — '.$course['code'];
require __DIR__ . '/_top.php';
?>
<a href="<?= url('admin/gradebook.php?course_id='.$courseId) ?>">กลับสมุดคะแนน</a>
<h1 class="h4 my-3"><?= e($pageTitle) ?></h1>
<ul class="list-group mb-4"><?php foreach ($runs as $entry): ?><li class="list-group-item"><a href="?course_id=<?= $courseId ?>&run_id=<?= (int)$entry['id'] ?>">รอบที่ <?= (int)$entry['id'] ?> · <?= e(thai_date($entry['created_at'],true)) ?> · <?= e($entry['group_name'] ?: 'ผู้เรียนทั้งหมด') ?></a> · <?= e($entry['teacher'] ?? 'ครูผู้สอน') ?></li><?php endforeach; ?><?php if (!$runs): ?><li class="list-group-item">ยังไม่มีผลเกรดที่บันทึก</li><?php endif; ?></ul>
<?php if ($page>1): ?><a class="btn btn-light" href="?course_id=<?= $courseId ?>&page=<?= $page-1 ?>">ก่อนหน้า</a><?php endif; ?>
<?php if ($hasNext): ?><a class="btn btn-light" href="?course_id=<?= $courseId ?>&page=<?= $page+1 ?>">ถัดไป</a><?php endif; ?>
<?php if ($run): $plan=json_decode($run['plan_json'],true); ?>
<h2 class="h5 mt-4">ผลเกรดรอบที่ <?= (int)$runId ?> · <?= e(thai_date($run['created_at'],true)) ?></h2>
<p>ข้อมูลฉบับที่บันทึกไว้ คะแนนและเกณฑ์นี้คงเดิมแม้ภายหลังแก้ไขคะแนนต้นทาง</p>
<details class="mb-3"><summary>สัดส่วนและเกณฑ์ที่ใช้</summary><ul><?php foreach ($plan['items'] as $item): if ((float)$item['weight']<=0) continue; ?><li><?= e($item['title']) ?> <?= e($item['weight']) ?>%</li><?php endforeach; ?></ul><p><?php foreach ($plan['scale'] as $band): ?>ตั้งแต่ <?= e($band['min']) ?> = <?= e($band['label']) ?> · <?php endforeach; ?></p></details>
<div class="table-responsive"><table class="table table-bordered"><thead><tr><th>รหัสนักเรียน</th><th>ชื่อ-นามสกุล</th><th>กลุ่มเรียน</th><th>คะแนนรวม</th><th>เกรด</th></tr></thead><tbody><?php foreach ($results as $result): $snapshot=json_decode($result['snapshot_json'],true); $s=$snapshot['student']; ?><tr><td><?= e($s['student_code']) ?></td><td><?= e($s['fullname']) ?></td><td><?= e($s['group_names']) ?></td><td><?= e($result['total']) ?></td><td><?= e($result['grade_label']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
