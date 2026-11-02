<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/attendance_report.php';
$me = require_role('admin','teacher');
$pageTitle = 'Dashboard การเข้าเรียน';
$courses = staff_courses($me);
$courseId = get_int('course_id');
if (!$courseId && $courses) $courseId = (int)$courses[0]['id'];
$course = $courseId ? db_one('SELECT * FROM courses WHERE id=?',[$courseId]) : null;
if ($courseId && (!$course || !can_manage_course($courseId))) { http_response_code(403); exit('ไม่มีสิทธิ์ดูสรุปการเข้าเรียนรายวิชานี้'); }
if (!$course || !attendance_ready()) {
    require __DIR__ . '/_top.php';
    echo '<div class="alert alert-info">'.($course ? 'กรุณารัน database/migrations/1.16.0.sql ก่อนใช้งานระบบเช็คชื่อ' : 'ยังไม่มีรายวิชาที่คุณจัดการได้').'</div>';
    require __DIR__ . '/_bottom.php'; exit;
}
$groups = grade_groups($courseId); $groupId = get_int('group_id');
$groupNames = array_column($groups,'name','id');
if ($groupId && !isset($groupNames[$groupId])) { http_response_code(403); exit('กลุ่มนี้ไม่ได้รับสิทธิ์ในรายวิชา'); }
$today = date('Y-m-d');
try { [$from,$until] = attendance_report_range($_GET['from'] ?? date('Y-m-01'), $_GET['until'] ?? $today); }
catch (RuntimeException $ex) { http_response_code(400); exit(e($ex->getMessage())); }
$report = attendance_report($courseId,$groupId,$from,$until);
$statuses = attendance_statuses(true);
if (!$report['totals']['leave']) unset($statuses['leave']);
$groupName = $groupId ? $groupNames[$groupId] : 'ทุกกลุ่ม / ลงทะเบียนรายบุคคล';
$text = attendance_report_text($course,$groupName,$from,$until,$report);
$shareUrl = 'https://line.me/R/share?text='.rawurlencode($text);
$colors = ['present'=>'#198754','late'=>'#b77900','absent'=>'#c43c47','sick_leave'=>'#6f42c1','personal_leave'=>'#1976a3','leave'=>'#6c757d'];
$maxDaily = max([1,...array_map('array_sum',$report['daily'])]);
$periodUrl = fn($a,$b) => url('admin/attendance_dashboard.php?'.http_build_query(['course_id'=>$courseId,'group_id'=>$groupId,'from'=>$a,'until'=>$b]));
require __DIR__ . '/_top.php';
?>
<style>
.attendance-dashboard .metric { border: 1px solid #dee2e6; border-radius: 12px; background: #fff; padding: 1rem; height: 100%; }
.attendance-dashboard .metric-value { font-size: 1.8rem; font-weight: 600; line-height: 1.35; }
.attendance-dashboard .chart-track { height: 14px; background: #edf0f3; border-radius: 4px; overflow: hidden; }
.attendance-dashboard .daily-track { display: flex; height: 18px; background: #edf0f3; border-radius: 3px; overflow: hidden; }
.attendance-dashboard .legend-dot { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: .35rem; }
.attendance-dashboard .table th { white-space: nowrap; }
.attendance-dashboard .table td { vertical-align: middle; }
.attendance-dashboard .line-button { background: #06a947; border-color: #06a947; color: #fff; }
.attendance-dashboard .line-button:hover { background: #048738; color: #fff; }
@media print { .admin-sidebar,.admin-topbar,.attendance-filters,.attendance-share,.attendance-actions { display:none !important; } .admin-main { margin-left:0 !important; } }
</style>
<main class="attendance-dashboard">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <div><h4 class="mb-1">สรุปการเข้าเรียน</h4><div class="text-muted"><?= e($course['code'].' '.$course['title']) ?> · <?= e($groupName) ?></div><small class="text-muted"><?= e(thai_date($from)) ?> – <?= e(thai_date($until)) ?></small></div>
        <div class="attendance-actions"><a class="btn btn-outline-primary" href="<?= url('admin/attendance.php?course_id='.$courseId.'&group_id='.$groupId) ?>"><i class="bi bi-calendar-check"></i> ไปเช็คชื่อ</a><a class="btn line-button" href="#line-summary"><i class="bi bi-line"></i> แชร์สรุปไป LINE</a></div>
    </div>
    <form method="get" class="card border-0 mb-4 attendance-filters"><div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-4"><label class="form-label" for="dashboard-course">รายวิชา</label><select name="course_id" id="dashboard-course" class="form-select"><?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $courseId ? 'selected' : '' ?>><?= e($c['code'].' '.$c['title']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label" for="dashboard-group">กลุ่มผู้เรียน</label><select name="group_id" id="dashboard-group" class="form-select"><option value="0">ทุกกลุ่ม / ลงทะเบียนรายบุคคล</option><?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= (int)$g['id'] === $groupId ? 'selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label" for="dashboard-from">ตั้งแต่วันที่</label><input type="date" name="from" id="dashboard-from" class="form-control" value="<?= e($from) ?>" required></div>
            <div class="col-md-2"><label class="form-label" for="dashboard-until">ถึงวันที่</label><input type="date" name="until" id="dashboard-until" class="form-control" value="<?= e($until) ?>" required></div>
            <div class="col-md-1"><button class="btn btn-vt w-100 text-nowrap px-1">แสดง</button></div>
        </div>
        <div class="d-flex gap-2 mt-3"><a class="btn btn-sm btn-outline-secondary" href="<?= e($periodUrl($today,$today)) ?>">วันนี้</a><a class="btn btn-sm btn-outline-secondary" href="<?= e($periodUrl(date('Y-m-d',strtotime('-6 days')),$today)) ?>">7 วันล่าสุด</a><a class="btn btn-sm btn-outline-secondary" href="<?= e($periodUrl(date('Y-m-01'),$today)) ?>">เดือนนี้</a></div>
    </div></form>
    <div class="d-flex flex-wrap gap-3 align-items-center mb-3"><span><strong><?= $report['student_count'] ?></strong> ผู้เรียนปัจจุบัน</span><span class="text-muted">มีบันทึก <?= $report['marked_students'] ?> คน</span><span class="text-muted">ยังไม่มีบันทึกในช่วงนี้ <?= $report['student_count']-$report['marked_students'] ?> คน</span></div>
    <div class="row g-3 mb-3">
        <?php foreach ($statuses as $key=>$label): ?><div class="col-6 col-lg"><div class="metric"><div class="small text-muted"><span class="legend-dot" style="background:<?= $colors[$key] ?>"></span><?= e($label) ?></div><div class="metric-value" data-total-status="<?= e($key) ?>"><?= $report['totals'][$key] ?></div><div class="small text-muted">ครั้ง (คน-วัน)</div></div></div><?php endforeach; ?>
    </div>
    <p class="small text-muted mb-4">รวมการลา <?= $report['leave_total'] ?> ครั้ง (ลาป่วย + ลากิจ<?= $report['totals']['leave'] ? ' + ลาข้อมูลเดิม' : '' ?>) · บันทึกทั้งหมด <?= $report['recorded'] ?> ครั้ง · มาเรียนรวมมาสาย <?= $report['attendance_rate'] !== null ? $report['attendance_rate'].'%' : '—' ?> ของรายการที่บันทึก<br>นับตามผู้เรียนปัจจุบันในรายวิชา/กลุ่มที่เลือก เฉพาะวันที่บันทึกแล้ว วันที่ยังไม่เช็คชื่อไม่ถูกนับเป็นขาด</p>
    <?php if (!$report['recorded']): ?><div class="alert alert-info">ยังไม่มีข้อมูลเช็คชื่อในช่วงวันที่และกลุ่มที่เลือก</div><?php endif; ?>
    <div class="row g-4 mb-4">
        <section class="col-lg-4"><div class="card border-0 h-100"><div class="card-body"><h5 class="mb-3">สัดส่วนสถานะ</h5>
            <?php foreach ($statuses as $key=>$label): $percent = $report['recorded'] ? round(100*$report['totals'][$key]/$report['recorded'],1) : 0; ?>
                <div class="d-flex justify-content-between small mb-1"><span><?= e($label) ?></span><span><?= $report['totals'][$key] ?> ครั้ง · <?= $percent ?>%</span></div><div class="chart-track mb-3" role="img" aria-label="<?= e($label.' '.$percent.' เปอร์เซ็นต์') ?>"><div style="height:100%;width:<?= $percent ?>%;background:<?= $colors[$key] ?>"></div></div>
            <?php endforeach; ?>
        </div></div></section>
        <section class="col-lg-8"><div class="card border-0 h-100"><div class="card-body"><h5 class="mb-1">แนวโน้มรายวัน</h5><p class="small text-muted">แสดงเฉพาะวันที่มีข้อมูลเช็คชื่อ · แต่ละแถบแสดงจำนวนรายการของวันนั้น</p>
            <div style="max-height:330px;overflow-y:auto">
            <?php foreach ($report['daily'] as $day=>$counts): ?><div class="d-flex gap-3 align-items-center mb-3"><div class="small text-nowrap" style="width:110px"><?= e(thai_date($day)) ?></div><div class="daily-track flex-grow-1" role="img" aria-label="<?= e(thai_date($day).' '.implode(', ',array_map(fn($key) => $statuses[$key].' '.$counts[$key].' ครั้ง',array_keys($statuses)))) ?>"><?php foreach ($statuses as $key=>$label): ?><span style="width:<?= round(100*$counts[$key]/$maxDaily,3) ?>%;background:<?= $colors[$key] ?>" title="<?= e($label.' '.$counts[$key].' ครั้ง') ?>"></span><?php endforeach; ?></div><span class="small text-muted text-nowrap"><?= array_sum($counts) ?> ครั้ง</span></div><?php endforeach; ?>
            <?php if (!$report['daily']): ?><p class="text-muted py-4">ยังไม่มีข้อมูลรายวัน</p><?php endif; ?>
            </div>
            <div class="d-flex gap-3 flex-wrap small mt-2"><?php foreach ($statuses as $key=>$label): ?><span><span class="legend-dot" style="background:<?= $colors[$key] ?>"></span><?= e($label) ?></span><?php endforeach; ?></div>
        </div></div></section>
    </div>
    <section class="card border-0 mb-4"><div class="card-body"><h5 class="mb-1">สรุปรายบุคคล</h5><p class="small text-muted mb-3">เรียงจากจำนวนขาดเรียนมากที่สุด แล้วตามด้วยมาสาย · หน่วย: ครั้ง (คน-วัน)</p>
        <div class="table-responsive"><table class="table table-hover"><thead><tr><th>รหัส / ชื่อผู้เรียน</th><?php foreach ($statuses as $label): ?><th class="text-center"><?= e($label) ?></th><?php endforeach; ?><th class="text-center">รวมการลา</th><th class="text-center">บันทึกแล้ว</th></tr></thead><tbody>
        <?php foreach ($report['students'] as $student): ?><tr data-report-student="<?= (int)$student['id'] ?>"><td><small class="text-muted"><?= e($student['student_code'] ?: '-') ?></small><br><?= e($student['fullname']) ?></td><?php foreach ($statuses as $key=>$label): ?><td class="text-center <?= $key === 'absent' && $student['counts'][$key] ? 'text-danger fw-semibold' : '' ?>"><?= $student['counts'][$key] ?></td><?php endforeach; ?><td class="text-center"><?= $student['counts']['sick_leave']+$student['counts']['personal_leave']+$student['counts']['leave'] ?></td><td class="text-center"><?= $student['recorded'] ?: 'ยังไม่มีบันทึก' ?></td></tr><?php endforeach; ?>
        <?php if (!$report['students']): ?><tr><td colspan="<?= count($statuses)+3 ?>" class="text-center text-muted py-4">ยังไม่มีผู้เรียนในกลุ่มนี้</td></tr><?php endif; ?>
        </tbody></table></div>
    </div></section>
    <section class="card border-0 attendance-share" id="line-summary"><div class="card-body">
        <h5><i class="bi bi-line text-success"></i> แชร์สรุปไป LINE กลุ่ม</h5><p class="small text-muted">ตรวจข้อความก่อนแชร์ ระบบเตรียมเฉพาะยอดรวมตามตัวกรองด้านบน คุณเลือกกลุ่มและกดส่งใน LINE เอง</p>
        <label class="form-label" for="attendance-share-text">ข้อความสรุปที่จะแชร์</label><textarea id="attendance-share-text" class="form-control mb-3" rows="11" maxlength="2000"><?= e($text) ?></textarea>
        <div class="d-flex flex-wrap gap-2"><a class="btn line-button" id="attendance-line-share" href="<?= e($shareUrl) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-line"></i> เปิด LINE เพื่อเลือกกลุ่ม</a><button type="button" class="btn btn-outline-secondary" id="attendance-copy-summary"><i class="bi bi-copy"></i> คัดลอกข้อความ</button></div>
        <div class="small text-muted mt-2" id="attendance-share-feedback" role="status" aria-live="polite">หากอุปกรณ์เปิด LINE ไม่ได้ ให้คัดลอกข้อความแล้ววางในกลุ่ม</div>
    </div></section>
</main>
<script src="<?= url('assets/js/attendance-dashboard.js') ?>?v=<?= (int)filemtime(ROOT_PATH.'/assets/js/attendance-dashboard.js') ?>" defer></script>
<?php require __DIR__ . '/_bottom.php'; ?>
