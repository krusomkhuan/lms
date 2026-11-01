<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/../config/config.php';
require __DIR__.'/../includes/functions.php';
require __DIR__.'/../includes/schedule.php';
require __DIR__.'/../includes/gradebook.php';
function check($condition,$message) { if (!$condition) throw new RuntimeException($message); }
$scale=grade_default_scale();
foreach ([['80','4'],['79.99','3.5'],['75','3.5'],['70','3'],['65','2.5'],['60','2'],['55','1.5'],['50','1'],['49.99','0'],['0','0'],['100','4']] as [$n,$g]) check(grade_label((float)$n,$scale)===$g,'Grade boundary '.$n);
$items=[['id'=>1,'weight'=>40],['id'=>2,'weight'=>40],['id'=>3,'weight'=>20]];
$cells=[1=>['score'=>8,'max'=>10,'state'=>'scored','pending'=>false],2=>['score'=>15,'max'=>20,'state'=>'scored','pending'=>false],3=>['score'=>90,'max'=>100,'state'=>'scored','pending'=>false]];
$result=grade_calculate($items,$cells,$scale);
check($result['total']===80.0 && $result['grade']==='4' && $result['weight_valid'],'Weighted calculation');
$cells[2]=['score'=>null,'max'=>20,'state'=>'pending','pending'=>true];
check(grade_calculate($items,$cells,$scale)['pending']===1,'Pending work');
$cells[2]=['score'=>null,'max'=>20,'state'=>'missing','pending'=>false];
check(grade_calculate($items,$cells,$scale)['missing']===1,'Missing work');
$cells[2]['state']='unavailable';
check(grade_calculate($items,$cells,$scale)['unavailable']===1,'Unavailable source');
$items[0]['weight']=39.99;
check(!grade_calculate($items,$cells,$scale)['weight_valid'],'Invalid weight sum');
$attempts=[['id'=>2,'score'=>9,'max'=>20,'status'=>'graded'],['id'=>1,'score'=>8,'max'=>10,'status'=>'graded']];
check(grade_pick($attempts,'highest','quiz')['record_id']===1,'Highest must use ratio');
check(grade_pick($attempts,'latest','quiz')['record_id']===2,'Latest score');
$attempts[0]['status']='submitted'; $attempts[0]['score']=null;
check(grade_pick($attempts,'highest','assignment')['pending'],'Ungraded latest work blocks finalization');
check(grade_validate_scale(['80','0'],['A','F'])[1]['label']==='F','Custom scale');
foreach ([[['80','80','0'],['A','B','F']],[['0','80'],['F','A']],[['80','50'],['A','F']],[['80','0'],['A','A']]] as [$mins,$labels]) {
    try { grade_validate_scale($mins,$labels); throw new LogicException('Invalid scale accepted'); } catch (RuntimeException $expected) {}
}
check(schedule_datetime('2026-10-27T09:30')==='2026-10-27 09:30:00','Local datetime');
foreach (['2026-02-30T10:00','2026-10-27T25:00','nonsense',[]] as $bad) {
    try { schedule_datetime($bad); throw new LogicException('Invalid datetime accepted'); } catch (RuntimeException $expected) {}
}
try { schedule_range('2026-10-28T10:00','2026-10-27T10:00'); throw new LogicException('Invalid range accepted'); } catch (RuntimeException $expected) {}
$lesson=['available_from'=>'2026-10-27 09:00:00','available_until'=>'2026-10-27 10:00:00'];
check(!lesson_schedule_open($lesson,'2026-10-27 08:59:59'),'Before opening');
check(lesson_schedule_open($lesson,'2026-10-27 09:00:00'),'Opening boundary');
check(!lesson_schedule_open($lesson,'2026-10-27 10:00:00'),'Closing boundary');
$work=['submission_opens_at'=>'2026-10-27 09:00:00','submission_due_at'=>'2026-10-27 10:00:00','accept_late'=>0];
check(assignment_schedule_state($work,'2026-10-27 08:59:59')==='upcoming','Before submission open');
check(assignment_schedule_state($work,'2026-10-27 10:00:00')==='open','Due boundary');
check(assignment_schedule_state($work,'2026-10-27 10:00:01')==='closed','After due');
$work['accept_late']=1;
check(assignment_schedule_state($work,'2026-10-27 10:00:01')==='late','Accept late');
echo "Grade calculation, scales, attempt policies and schedule boundaries passed\n";
