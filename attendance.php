<?php
require_once __DIR__ . '/includes/bootstrap.php';
$me = require_role('student');
$courseId = get_int('course_id');
$course = db_one("SELECT * FROM courses WHERE id=? AND status='published'", [$courseId]);
if (!$course || !is_enrolled($courseId, (int)$me['id'])) { http_response_code(403); exit('ไม่มีสิทธิ์ดูข้อมูลเช็คชื่อรายวิชานี้'); }
$pageTitle = 'เช็คชื่อเข้าเรียน — ' . $course['title'];
if (!attendance_ready()) {
    require __DIR__ . '/includes/header.php';
    echo '<div class="container py-4"><div class="alert alert-info">ระบบเช็คชื่อยังไม่พร้อมใช้งาน กรุณาติดต่อครูผู้สอน</div></div>';
    require __DIR__ . '/includes/footer.php'; exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        attendance_self_checkin($courseId, (int)$me['id']);
        flash('success', 'บันทึกเช็คชื่อแล้ว หากต้องการแก้ไขสถานะ กรุณาติดต่อครูผู้สอน');
        redirect('attendance.php?course_id=' . $courseId);
    } catch (RuntimeException $ex) {
        if ($ex instanceof mysqli_sql_exception) throw $ex;
        $error = $ex->getMessage();
    }
}
$config = db_one('SELECT * FROM attendance_config WHERE course_id=?', [$courseId]);
$today = date('Y-m-d'); $reason = attendance_closed_reason($config, $today);
$records = db_all('SELECT * FROM attendance_records WHERE course_id=? AND user_id=? ORDER BY attendance_date DESC', [$courseId,(int)$me['id']]);
$statuses = attendance_statuses(true);
$todayRecord = null; $counts = array_fill_keys(array_keys($statuses), 0);
foreach ($records as $r) { $counts[$r['status']]++; if ($r['attendance_date'] === $today) $todayRecord = $r; }
if (!$counts['leave']) unset($statuses['leave']);
require __DIR__ . '/includes/header.php';
?>
<main class="container py-4">
    <a href="<?= url('course.php?id=' . $courseId) ?>">กลับไปรายวิชา</a>
    <h3 class="mt-3"><?= e($pageTitle) ?></h3>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <div class="card border-0 shadow-sm my-4"><div class="card-body">
        <h5>วันนี้ <?= e(thai_date($today)) ?></h5>
        <?php if ($config): ?><p class="text-muted">ภาคเรียน <?= e(thai_date($config['term_start'])) ?> – <?= e(thai_date($config['term_end'])) ?></p><?php endif; ?>
        <?php if ($todayRecord): ?><div class="alert alert-success mb-0">สถานะ: <?= e($statuses[$todayRecord['status']]) ?> · <?= e(thai_date($todayRecord['checked_at'], true)) ?></div>
        <?php elseif ($reason !== ''): ?><div class="alert alert-info mb-0"><?= e($reason) ?></div>
        <?php elseif (empty($config['self_checkin'])): ?><p class="mb-0">ครูเป็นผู้บันทึกเช็คชื่อให้รายวิชานี้</p>
        <?php else: ?><form method="post"><?= csrf_field() ?><button class="btn btn-vt btn-lg">เช็คชื่อเข้าเรียนวันนี้</button><div class="form-text">บันทึกได้วันละ 1 ครั้ง ตามเวลาไทย</div></form><?php endif; ?>
    </div></div>
    <div class="d-flex gap-3 flex-wrap mb-4"><?php foreach ($statuses as $key=>$label): ?><div class="border rounded p-3 bg-white"><?= e($label) ?> <strong><?= $counts[$key] ?></strong> วัน</div><?php endforeach; ?></div>
    <h5>ประวัติการเช็คชื่อของฉัน</h5>
    <div class="table-responsive bg-white rounded shadow-sm"><table class="table mb-0"><thead><tr><th>วันที่</th><th>สถานะ</th><th>หมายเหตุ</th><th>บันทึกโดย</th></tr></thead><tbody>
    <?php foreach ($records as $r): ?><tr><td><?= e(thai_date($r['attendance_date'])) ?></td><td><?= e($statuses[$r['status']]) ?></td><td><?= e($r['note']) ?></td><td><?= $r['source'] === 'student' ? 'เช็คชื่อด้วยตนเอง' : 'ครูผู้สอน' ?></td></tr><?php endforeach; ?>
    <?php if (!$records): ?><tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีประวัติการเช็คชื่อ</td></tr><?php endif; ?>
    </tbody></table></div>
    <p class="small text-muted mt-3">นับเฉพาะวันที่บันทึกสถานะแล้ว วันหยุดและวันที่ยังไม่เช็คชื่อไม่ถูกนับเป็นขาด</p>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
