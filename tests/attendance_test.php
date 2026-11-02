<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/attendance.php';
date_default_timezone_set('Asia/Bangkok');
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$config = attendance_validate_config('2026-11-02','2027-03-31',['1','2','3','4','5'],"2026-12-10 | Constitution Day\n2026-12-31..2027-01-03 | New Year",true);
check(attendance_closed_reason($config,'2026-11-02') === '', 'Opening day included');
check(attendance_closed_reason($config,'2027-03-31') === '', 'Closing day included');
check(attendance_closed_reason($config,'2026-11-01') !== '', 'Before opening blocked');
check(attendance_closed_reason($config,'2027-04-01') !== '', 'After closing blocked');
check(attendance_closed_reason($config,'2026-11-07') !== '', 'Weekend blocked');
check(str_contains(attendance_closed_reason($config,'2026-12-10'), 'Constitution Day'), 'Single holiday');
foreach (['2026-12-31','2027-01-01','2027-01-03'] as $day) check(str_contains(attendance_closed_reason($config,$day), 'New Year'), 'Holiday range inclusive');
check(attendance_closed_reason($config,'2027-01-04') === '', 'Reopens after holidays');
check(attendance_closed_reason(null,'2026-11-03') !== '', 'No calendar blocked');
check(attendance_validate_config($config['term_start'],$config['term_end'],explode(',',$config['weekdays']),attendance_holiday_text($config),true) === $config, 'Calendar text round trip');
foreach (['2026-02-29','2026-13-01','2026-1-01','0000-01-01','2026-11-03x',[],null] as $bad) {
    try { attendance_date($bad); throw new LogicException('Invalid date accepted'); } catch (RuntimeException $expected) {}
}
check(attendance_date('2028-02-29') === '2028-02-29', 'Leap day');
foreach ([['2027-01-01','2026-01-01',['1'],''],['2026-01-01','2029-01-01',['1'],''],['2026-01-01','2026-12-31',[],''],['2026-01-01','2026-12-31',['8'],''],['2026-01-01','2026-12-31',['1'],'2027-01-01 | Outside'],['2026-01-01','2026-12-31',['1'],'2026-12-31..2026-12-01 | Reverse']] as $bad) {
    try { attendance_validate_config(...[...$bad,false]); throw new LogicException('Invalid config accepted'); } catch (RuntimeException $expected) {}
}
echo "Attendance calendar tests passed\n";
