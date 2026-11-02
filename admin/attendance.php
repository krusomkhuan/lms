<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_role('admin', 'teacher');
$courseId = get_int('course_id');
$pageTitle = 'เช็คชื่อเข้าเรียน';
if (!$courseId) {
    $courses = $me['role'] === 'admin' ? db_all('SELECT id,code,title FROM courses ORDER BY code')
        : db_all('SELECT c.id,c.code,c.title FROM courses c JOIN course_teachers ct ON ct.course_id=c.id WHERE ct.teacher_id=? ORDER BY c.code', [(int)$me['id']]);
    require __DIR__ . '/_top.php';
    echo '<div class="list-group">';
    foreach ($courses as $c) echo '<a class="list-group-item list-group-item-action" href="' . e(url('admin/attendance.php?course_id=' . $c['id'])) . '"><i class="bi bi-calendar-check"></i> ' . e($c['code'] . ' ' . $c['title']) . '</a>';
    if (!$courses) echo '<div class="alert alert-info">ยังไม่มีรายวิชาที่จัดการได้</div>';
    echo '</div>';
    require __DIR__ . '/_bottom.php'; exit;
}
$course = db_one('SELECT * FROM courses WHERE id=?', [$courseId]);
if (!$course || !can_manage_course($courseId)) { http_response_code(403); exit('ไม่มีสิทธิ์เช็คชื่อรายวิชานี้'); }
$pageTitle .= ' — ' . $course['code'] . ' ' . $course['title'];
if (!attendance_ready()) {
    require __DIR__ . '/_top.php';
    echo '<div class="alert alert-warning">กรุณารัน database/migrations/1.16.0.sql ก่อนใช้งานระบบเช็คชื่อ</div>';
    require __DIR__ . '/_bottom.php'; exit;
}
if (!str_contains((string)db_value("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='attendance_records' AND column_name='status'"), "'sick_leave'")) {
    require __DIR__ . '/_top.php';
    echo '<div class="alert alert-warning">กรุณารัน database/migrations/1.16.1.sql เพื่อเพิ่มสถานะลาป่วยและลากิจ ข้อมูลเช็คชื่อเดิมยังคงอยู่</div>';
    require __DIR__ . '/_bottom.php'; exit;
}
try { $date = attendance_date($_GET['date'] ?? date('Y-m-d')); }
catch (RuntimeException $ex) { http_response_code(400); exit(e($ex->getMessage())); }
$groupId = get_int('group_id');
$groups = grade_groups($courseId);
if ($groupId && !in_array($groupId, array_map('intval', array_column($groups, 'id')), true)) { http_response_code(403); exit('กลุ่มนี้ไม่ได้รับสิทธิ์ในรายวิชา'); }
$roster = grade_roster($courseId, $groupId);
$config = db_one('SELECT * FROM attendance_config WHERE course_id=?', [$courseId]);
$statuses = attendance_statuses(true); $choices = attendance_statuses(); $error = '';
$returnPath = 'admin/attendance.php?course_id=' . $courseId . '&date=' . $date . '&group_id=' . $groupId;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $conn->begin_transaction();
    try {
        $locked = attendance_lock($courseId);
        if ((string)post('version') !== (string)($locked['version'] ?? 0)) throw new RuntimeException('ข้อมูลเปลี่ยนแปลงแล้ว กรุณาโหลดหน้าใหม่ก่อนบันทึก');
        if (post('action') === 'settings') {
            $new = attendance_validate_config(post('term_start'), post('term_end'), post('weekdays', []), post('holidays'), post('self_checkin') === '1');
            // Preserve old records; a calendar change must not silently exclude recorded attendance.
            foreach (db_all('SELECT DISTINCT attendance_date FROM attendance_records WHERE course_id=?', [$courseId]) as $r) {
                if (attendance_closed_reason($new, $r['attendance_date']) !== '') throw new RuntimeException('เปลี่ยนปฏิทินไม่ได้ เพราะวันที่ ' . thai_date($r['attendance_date']) . ' มีข้อมูลเช็คชื่อแล้ว');
            }
            db_exec('INSERT INTO attendance_config (course_id,term_start,term_end,weekdays,holidays_json,self_checkin) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE term_start=VALUES(term_start),term_end=VALUES(term_end),weekdays=VALUES(weekdays),holidays_json=VALUES(holidays_json),self_checkin=VALUES(self_checkin),version=version+1',
                [$courseId,$new['term_start'],$new['term_end'],$new['weekdays'],$new['holidays_json'],$new['self_checkin']]);
        } elseif (post('action') === 'mark') {
            $reason = attendance_closed_reason($locked, $date);
            if ($reason !== '') throw new RuntimeException($reason);
            if ($date > date('Y-m-d')) throw new RuntimeException('ยังไม่สามารถเช็คชื่อล่วงหน้าได้');
            $marks = post('marks', []); $notes = post('notes', []);
            if (!is_array($marks) || !is_array($notes)) throw new RuntimeException('ข้อมูลเช็คชื่อไม่ถูกต้อง');
            $allowed = array_column(grade_roster($courseId, $groupId), null, 'id');
            foreach ($marks as $uid => $status) {
                if (!isset($allowed[$uid])) throw new RuntimeException('มีผู้เรียนที่ไม่ได้อยู่ในรายวิชาหรือกลุ่มที่เลือก');
                $note = $notes[$uid] ?? '';
                if (!is_string($note) || mb_strlen($note) > 255 || !is_string($status) || ($status !== '' && !isset($statuses[$status]))) throw new RuntimeException('สถานะหรือหมายเหตุไม่ถูกต้อง');
                if ($status === '') continue;
                db_exec('INSERT INTO attendance_records (course_id,user_id,attendance_date,status,note,source,marked_by) VALUES (?,?,?,?,?,\'teacher\',?) ON DUPLICATE KEY UPDATE status=VALUES(status),note=VALUES(note),source=VALUES(source),marked_by=VALUES(marked_by),updated_at=NOW()', [$courseId,(int)$uid,$date,$status,$note,(int)$me['id']]);
            }
            db_exec('UPDATE attendance_config SET version=version+1 WHERE course_id=?', [$courseId]);
        } else { throw new RuntimeException('คำสั่งไม่ถูกต้อง'); }
        $conn->commit(); flash('success', 'บันทึกข้อมูลเช็คชื่อแล้ว'); redirect($returnPath);
    } catch (Throwable $ex) {
        $conn->rollback();
        if (!$ex instanceof RuntimeException || $ex instanceof mysqli_sql_exception) throw $ex;
        $error = $ex->getMessage();
    }
}
$records = array_column(db_all('SELECT * FROM attendance_records WHERE course_id=? AND attendance_date=?', [$courseId,$date]), null, 'user_id');
$summary = [];
foreach (db_all('SELECT user_id,status,COUNT(*) AS total FROM attendance_records WHERE course_id=? GROUP BY user_id,status', [$courseId]) as $r) $summary[$r['user_id']][$r['status']] = (int)$r['total'];
if (!array_filter($summary, fn($counts) => !empty($counts['leave']))) unset($statuses['leave']);
$closed = attendance_closed_reason($config, $date);
$canMark = $closed === '' && $date <= date('Y-m-d');
require __DIR__ . '/_top.php';
?>
<style>
.attendance-grid { min-width: 840px; border-top: 3px solid #c65b5b; }
.attendance-grid thead th { background: #808080; color: #fff; text-align: center; vertical-align: middle; border-color: #bdbdbd; }
.attendance-grid .attendance-person { min-width: 200px; }
.attendance-grid .attendance-status { width: 95px; min-width: 88px; text-align: center; white-space: nowrap; }
.attendance-grid .attendance-note { min-width: 180px; }
.attendance-grid input[type="checkbox"] { width: 16px; height: 16px; accent-color: #0d6efd; cursor: pointer; vertical-align: middle; }
.attendance-grid tbody td { padding: .45rem; }
.attendance-grid tbody td:has(input[type="checkbox"]:checked) { background: #edf5ff; }
</style>
<a href="<?= url('admin/course_students.php?course_id=' . $courseId) ?>" class="d-inline-block mb-3">กลับไปผู้เรียนรายวิชา</a>
<a href="<?= url('admin/attendance_dashboard.php?course_id='.$courseId.'&group_id='.$groupId) ?>" class="btn btn-outline-primary ms-3 mb-3"><i class="bi bi-bar-chart-steps"></i> Dashboard สรุป / แชร์ LINE</a>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="row g-4">
<div class="col-12">
<details <?= !$config ? 'open' : '' ?>>
<summary class="text-primary fw-semibold">ตั้งค่าปฏิทินภาคเรียน / วันเรียน / วันหยุด</summary>
<form method="post" class="card shadow-sm border-0 mt-3">
    <div class="card-body" style="max-width:720px">
        <?= csrf_field() ?><input type="hidden" name="action" value="settings"><input type="hidden" name="version" value="<?= (int)($config['version'] ?? 0) ?>">
        <label class="form-label" for="term-start">วันเปิดภาคเรียน</label>
        <input type="date" id="term-start" name="term_start" class="form-control mb-3" required value="<?= e($config['term_start'] ?? '') ?>">
        <label class="form-label" for="term-end">วันปิดภาคเรียน (รวมวันนี้)</label>
        <input type="date" id="term-end" name="term_end" class="form-control mb-3" required value="<?= e($config['term_end'] ?? '') ?>">
        <fieldset class="mb-3"><legend class="fs-6">วันเรียนประจำสัปดาห์</legend>
        <?php foreach ([1=>'จันทร์',2=>'อังคาร',3=>'พุธ',4=>'พฤหัสบดี',5=>'ศุกร์',6=>'เสาร์',7=>'อาทิตย์'] as $n=>$label): ?>
            <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="weekdays[]" id="day-<?= $n ?>" value="<?= $n ?>" <?= in_array((string)$n, explode(',', $config['weekdays'] ?? '1,2,3,4,5'), true) ? 'checked' : '' ?>><label class="form-check-label" for="day-<?= $n ?>"><?= e($label) ?></label></div>
        <?php endforeach; ?></fieldset>
        <label class="form-label" for="holidays">วันหยุด / ช่วงวันหยุด</label>
        <textarea id="holidays" name="holidays" class="form-control" rows="6" aria-describedby="holiday-help" placeholder="2026-12-10 | วันรัฐธรรมนูญ&#10;2026-12-31..2027-01-03 | หยุดปีใหม่"><?= e(attendance_holiday_text($config)) ?></textarea>
        <div class="form-text mb-3" id="holiday-help">บรรทัดละ 1 รายการ: ปี ค.ศ.-เดือน-วัน | ชื่อวันหยุด หรือ วันเริ่ม..วันสิ้นสุด | ชื่อวันหยุด วันหยุดต้องอยู่ในภาคเรียน</div>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="self-checkin" name="self_checkin" value="1" <?= !empty($config['self_checkin']) ? 'checked' : '' ?>><label for="self-checkin" class="form-check-label">ให้นักเรียนเช็คชื่อด้วยตนเองในวันปัจจุบัน</label></div>
        <p class="small text-muted">เช็คชื่อได้ตลอดวันตามเวลาไทย เฉพาะวันเรียนที่กำหนด นักเรียนแก้สถานะที่ครูบันทึกไม่ได้</p>
        <button class="btn btn-vt w-100">บันทึกปฏิทิน</button>
    </div>
</form>
</details>
</div>
<div class="col-12">
    <form method="get" class="d-flex gap-2 flex-wrap align-items-end mb-3">
        <input type="hidden" name="course_id" value="<?= $courseId ?>">
        <div><label for="attendance-date" class="form-label">วันที่เช็คชื่อ</label><input id="attendance-date" type="date" name="date" value="<?= e($date) ?>" class="form-control" required></div>
        <div><label for="attendance-group" class="form-label">กลุ่มผู้เรียน</label><select id="attendance-group" name="group_id" class="form-select"><option value="0">ทุกกลุ่ม / ลงทะเบียนรายบุคคล</option><?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= $groupId === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-outline-primary">แสดงรายชื่อ</button>
    </form>
    <?php if (!$canMark): ?><div class="alert alert-info"><?= e($closed ?: 'ยังไม่สามารถเช็คชื่อล่วงหน้าได้') ?></div><?php endif; ?>
    <form method="post" class="card shadow-sm border-0" id="attendance-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="mark"><input type="hidden" name="version" value="<?= (int)($config['version'] ?? 0) ?>">
        <div class="card-header bg-white fw-semibold">เช็คชื่อ <?= e(thai_date($date)) ?> · <?= count($roster) ?> คน</div>
        <div class="table-responsive"><table class="table table-bordered table-hover align-middle mb-0 attendance-grid"><thead>
        <tr><th rowspan="2" scope="col" class="attendance-person">รหัส / ชื่อผู้เรียน</th><th colspan="6" scope="colgroup">สถานะ</th></tr>
        <tr><?php foreach ($choices as $key=>$label): ?><th scope="col" class="attendance-status"><label class="d-flex justify-content-center align-items-center gap-1"><input type="checkbox" data-attendance-all="<?= e($key) ?>" aria-label="เลือก<?= e($label) ?>ทุกคนในรายชื่อที่แสดง" <?= !$canMark || !$roster ? 'disabled' : '' ?>><span><?= e($label) ?></span></label></th><?php endforeach; ?><th scope="col" class="attendance-note">หมายเหตุ</th></tr>
        </thead><tbody>
        <?php foreach ($roster as $s): $r = $records[$s['id']] ?? null; $selected = $r['status'] ?? 'present'; ?>
        <tr data-attendance-row><td><small class="text-muted"><?= e($s['student_code'] ?: '-') ?></small><br><?= e($s['fullname']) ?><?php if ($r): ?><div class="small text-muted"><?= $r['source'] === 'student' ? 'เช็คชื่อเอง' : 'ครูบันทึก' ?> · <?= e(thai_date($r['checked_at'], true)) ?></div><?php endif; ?>
            <?php if ($selected === 'leave'): ?><div class="small text-warning-emphasis">ลา (ข้อมูลเดิม) · เลือกลาป่วยหรือลากิจเพื่อระบุประเภท</div><?php endif; ?>
            <input type="hidden" data-attendance-value name="marks[<?= (int)$s['id'] ?>]" value="<?= e($selected) ?>" <?= !$canMark ? 'disabled' : '' ?>></td>
            <?php foreach ($choices as $key=>$label): ?><td class="attendance-status"><input type="checkbox" data-attendance-status="<?= e($key) ?>" aria-label="<?= e($label . ' — ' . $s['fullname']) ?>" <?= $selected === $key ? 'checked' : '' ?> <?= !$canMark ? 'disabled' : '' ?>></td><?php endforeach; ?>
            <td><input class="form-control" name="notes[<?= (int)$s['id'] ?>]" aria-label="หมายเหตุ <?= e($s['fullname']) ?>" maxlength="255" value="<?= e($r['note'] ?? '') ?>" <?= !$canMark ? 'disabled' : '' ?>></td></tr>
        <?php endforeach; ?>
        <?php if (!$roster): ?><tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีผู้เรียนในกลุ่มที่เลือก</td></tr><?php endif; ?>
        </tbody></table></div>
        <div class="card-body"><button class="btn btn-vt" <?= !$canMark || !$roster ? 'disabled' : '' ?>>บันทึกเช็คชื่อ</button><p class="small text-muted mt-2 mb-0">เลือกได้คนละ 1 สถานะ · ช่องหัวตารางใช้เลือกสถานะให้ทุกคนในรายชื่อที่แสดง · ผู้ที่ยังไม่มีข้อมูลจะเลือกมาปกติไว้ให้ และจะบันทึกเมื่อกดปุ่มบันทึกเท่านั้น</p></div>
    </form>
    <div class="card shadow-sm border-0 mt-4"><div class="card-header bg-white fw-semibold">สรุปการเข้าเรียน · ทั้งภาคเรียน</div>
        <div class="table-responsive"><table class="table mb-0"><thead><tr><th>ผู้เรียน</th><?php foreach ($statuses as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($roster as $s): ?><tr><td><?= e($s['fullname']) ?></td><?php foreach ($statuses as $key=>$label): ?><td><?= (int)($summary[$s['id']][$key] ?? 0) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        </tbody></table></div><div class="card-body small text-muted">นับเฉพาะวันที่บันทึกสถานะแล้ว วันหยุดและวันที่ยังไม่เช็คชื่อไม่ถูกนับเป็นขาด</div>
    </div>
</div>
</div>
<script src="<?= url('assets/js/attendance.js') ?>?v=<?= (int)filemtime(ROOT_PATH . '/assets/js/attendance.js') ?>" defer></script>
<?php require __DIR__ . '/_bottom.php'; ?>
