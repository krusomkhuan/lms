<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../config/config.php';
require __DIR__.'/../includes/functions.php';
require __DIR__.'/../includes/gradebook.php';
require __DIR__.'/../includes/attendance_report.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli(DB_HOST,DB_USER,DB_PASS,DB_NAME); $conn->set_charset('utf8mb4');
function ensure($ok,$message) { if (!$ok) throw new RuntimeException($message); }
function request_page($client,$path,$data=null) {
    curl_setopt_array($client,[CURLOPT_URL=>'http://localhost/lms/'.$path,CURLOPT_POST=>$data!==null,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>20]);
    if ($data!==null) curl_setopt($client,CURLOPT_POSTFIELDS,http_build_query($data));
    $html=curl_exec($client); ensure($html!==false,curl_error($client));
    ensure(!str_contains($html,'Fatal error') && !str_contains($html,'Warning:'),'PHP error: '.$path);
    return $html;
}
function field($html,$name) {
    preg_match('/name="'.preg_quote($name,'/').'" value="([^"]*)"/',$html,$m);
    ensure(isset($m[1]),'Missing '.$name); return html_entity_decode($m[1],ENT_QUOTES,'UTF-8');
}
function total($html,$status) {
    preg_match('/data-total-status="'.preg_quote($status,'/').'">(\d+)</',$html,$m);
    ensure(isset($m[1]),'Missing total '.$status); return (int)$m[1];
}
$users=[]; $clients=[]; $course=0; $groups=[]; $otherCourse=0;
try {
    ensure(attendance_ready(),'Attendance migration required');
    $password=bin2hex(random_bytes(20));
    foreach (['teacher','student','student','student','teacher','admin'] as $role) {
        $username='att_dash_'.bin2hex(random_bytes(6));
        $users[]=db_exec('INSERT INTO users (username,password,fullname,role,consent_version,consent_at) VALUES (?,?,?,?,?,NOW())',[$username,password_hash($password,PASSWORD_DEFAULT),'Dashboard Person '.count($users),$role,setting('privacy_version','1')]);
        $client=curl_init(); curl_setopt($client,CURLOPT_COOKIEFILE,''); $clients[]=$client;
        $html=request_page($client,'login.php'); request_page($client,'login.php',['csrf'=>field($html,'csrf'),'username'=>$username,'password'=>$password]);
    }
    [$teacher,$student,$student2,$noRecords,$outsider,$admin]=$clients;
    $course=db_exec("INSERT INTO courses (code,title,status) VALUES ('ATT-DASH','Dashboard fixture','published')");
    $otherCourse=db_exec("INSERT INTO courses (code,title,status) VALUES ('OTHER-DASH','Other dashboard fixture','published')");
    db_exec('INSERT INTO course_teachers VALUES (?,?)',[$course,$users[0]]);
    foreach (['Group A','Group B','Empty group'] as $name) {
        $groups[]=db_exec('INSERT INTO student_groups (name) VALUES (?)',[$name]);
        db_exec('INSERT INTO course_groups VALUES (?,?)',[$course,end($groups)]);
    }
    // Overlapping group and individual membership must count this student only once.
    db_exec('INSERT INTO group_members VALUES (?,?),(?,?),(?,?)',[$groups[0],$users[1],$groups[1],$users[1],$groups[1],$users[2]]);
    db_exec('INSERT INTO enrollments (course_id,user_id) VALUES (?,?),(?,?)',[$course,$users[1],$course,$users[3]]);
    $rows=[[$users[1],'2026-11-01','present'],[$users[1],'2026-11-02','absent'],[$users[1],'2026-11-03','late'],
        [$users[2],'2026-11-01','sick_leave'],[$users[2],'2026-11-02','personal_leave'],[$users[2],'2026-11-03','leave'],
        [$users[1],'2026-10-31','absent'],[$users[1],'2026-11-04','absent']];
    foreach ($rows as [$uid,$date,$status]) db_exec("INSERT INTO attendance_records (course_id,user_id,attendance_date,status,note,source,marked_by) VALUES (?,?,?,?,?,'teacher',?)",[$course,$uid,$date,$status,'PRIVATE-NOTE-DO-NOT-SHARE',$users[0]]);
    // Records belonging to a different course must not enter the aggregate.
    db_exec("INSERT INTO attendance_records (course_id,user_id,attendance_date,status,source) VALUES (?,?,'2026-11-02','absent','teacher')",[$otherCourse,$users[1]]);
    $report=attendance_report($course,0,'2026-11-01','2026-11-03');
    ensure($report['student_count']===3 && $report['marked_students']===2,'Roster count and unmarked students');
    ensure($report['recorded']===6 && $report['leave_total']===3 && $report['attendance_rate']===33.3,'Summary counts, leave total and rate');
    foreach (attendance_statuses(true) as $key=>$label) ensure($report['totals'][$key]===1,'One each: '.$key);
    ensure(count($report['daily'])===3 && array_sum($report['daily']['2026-11-02'])===2,'Daily totals');
    ensure((int)$report['students'][0]['id']===$users[1],'Absence ordering');
    $group=attendance_report($course,$groups[0],'2026-11-01','2026-11-03');
    ensure($group['student_count']===1 && $group['recorded']===3 && $group['leave_total']===0,'Group filter');
    $empty=attendance_report($course,$groups[2],'2026-11-01','2026-11-03');
    ensure($empty['recorded']===0 && $empty['attendance_rate']===null && !$empty['daily'],'Empty report');
    foreach ([['2026-11-03','2026-11-01'],['2026-02-30','2026-11-03'],['2020-01-01','2026-11-03']] as $range) {
        try { attendance_report_range(...$range); throw new LogicException('Invalid range accepted'); } catch (RuntimeException $expected) {}
    }
    $dashboard='admin/attendance_dashboard.php?course_id='.$course.'&from=2026-11-01&until=2026-11-03';
    $html=request_page($teacher,$dashboard);
    ensure(curl_getinfo($teacher,CURLINFO_HTTP_CODE)===200,'Teacher dashboard');
    foreach (attendance_statuses(true) as $key=>$label) ensure(total($html,$key)===1,'Rendered total '.$key);
    ensure(substr_count($html,'data-report-student="'.$users[1].'"')===1,'No duplicate student rows');
    ensure(!str_contains($html,'PRIVATE-NOTE-DO-NOT-SHARE'),'Dashboard omits personal notes');
    preg_match('/id="attendance-line-share" href="([^"]+)"/',$html,$match);
    ensure(isset($match[1]),'LINE share button');
    $lineUrl=html_entity_decode($match[1],ENT_QUOTES,'UTF-8');
    ensure(str_starts_with($lineUrl,'https://line.me/R/share?text='),'Official LINE URL scheme');
    parse_str(parse_url($lineUrl,PHP_URL_QUERY),$query); $share=$query['text'];
    ensure(str_contains($share,'ATT-DASH') && str_contains($share,'ขาดเรียน: 1 ครั้ง') && str_contains($share,'รวมการลา: 3 ครั้ง'),'Share counts match filters');
    ensure(!str_contains($share,'Dashboard Person') && !str_contains($share,'PRIVATE-NOTE') && !str_contains($share,'localhost'),'Only aggregates shared');
    $groupHtml=request_page($teacher,$dashboard.'&group_id='.$groups[0]);
    ensure(total($groupHtml,'sick_leave')===0 && total($groupHtml,'absent')===1,'Rendered group filter');
    $emptyHtml=request_page($teacher,$dashboard.'&group_id='.$groups[2]);
    ensure(str_contains($emptyHtml,'ยังไม่มีข้อมูลเช็คชื่อ') && total($emptyHtml,'absent')===0,'Empty dashboard renders safely');
    $single=request_page($teacher,'admin/attendance_dashboard.php?course_id='.$course.'&from=2026-11-02&until=2026-11-02');
    ensure(total($single,'absent')===1 && total($single,'late')===0,'Inclusive single-day filter');
    foreach ([$student,$outsider] as $client) { request_page($client,$dashboard); ensure(curl_getinfo($client,CURLINFO_HTTP_CODE)===403,'Unauthorized dashboard'); }
    request_page($teacher,$dashboard.'&group_id=2147483647'); ensure(curl_getinfo($teacher,CURLINFO_HTTP_CODE)===403,'Invalid group blocked');
    request_page($teacher,'admin/attendance_dashboard.php?course_id='.$otherCourse); ensure(curl_getinfo($teacher,CURLINFO_HTTP_CODE)===403,'Other course blocked');
    request_page($teacher,'admin/attendance_dashboard.php?course_id='.$course.'&from[]=2026-11-01'); ensure(curl_getinfo($teacher,CURLINFO_HTTP_CODE)===400,'Malformed date blocked');
    ensure(total(request_page($admin,$dashboard),'absent')===1,'Admin dashboard');
    if (in_array('--preview',$argv,true)) file_put_contents(__DIR__.'/attendance-dashboard-preview.html',$html);
    echo "Attendance dashboard tests passed: totals, leave types, date/group filters, deduplication, empty data, privacy, LINE summary, role access\n";
} finally {
    foreach ([$course,$otherCourse] as $id) if ($id) db_exec('DELETE FROM courses WHERE id=?',[$id]);
    foreach ($groups as $id) db_exec('DELETE FROM student_groups WHERE id=?',[$id]);
    foreach ($users as $id) db_exec('DELETE FROM users WHERE id=?',[$id]);
    foreach ($clients as $client) curl_close($client);
    echo "Dashboard test fixtures removed\n";
}
