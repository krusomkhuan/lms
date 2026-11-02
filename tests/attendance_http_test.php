<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/attendance.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli(DB_HOST,DB_USER,DB_PASS,DB_NAME); $conn->set_charset('utf8mb4');
$conn->query("SET time_zone='+07:00'");
function ensure($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function request_page($client, $path, $data = null) {
    curl_setopt_array($client,[CURLOPT_URL=>'http://localhost/lms/'.$path,CURLOPT_POST=>$data !== null,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>20]);
    if ($data !== null) curl_setopt($client,CURLOPT_POSTFIELDS,http_build_query($data));
    $body = curl_exec($client); ensure($body !== false,curl_error($client));
    ensure(!str_contains($body,'Fatal error') && !str_contains($body,'Warning:'),'PHP error: '.$path);
    return $body;
}
function field($html, $name) {
    preg_match('/name="'.preg_quote($name,'/').'" value="([^"]*)"/',$html,$matches);
    ensure(isset($matches[1]),'Missing '.$name);
    return html_entity_decode($matches[1],ENT_QUOTES,'UTF-8');
}
$users=[]; $clients=[]; $course=0; $group=0;
$savedLinks=db_one("SELECT value FROM settings WHERE name='related_site_urls'");
try {
    ensure(attendance_ready(),'Run attendance migration first');
    $password=bin2hex(random_bytes(20));
    foreach (['teacher','student','student','student','teacher','admin'] as $role) {
        $username='att_http_'.bin2hex(random_bytes(6));
        $users[]=db_exec('INSERT INTO users (username,password,fullname,role,consent_version,consent_at) VALUES (?,?,?,?,?,NOW())',[$username,password_hash($password,PASSWORD_DEFAULT),'Attendance '.count($users),$role,setting('privacy_version','1')]);
        $client=curl_init(); curl_setopt($client,CURLOPT_COOKIEFILE,''); $clients[]=$client;
        $html=request_page($client,'login.php'); request_page($client,'login.php',['csrf'=>field($html,'csrf'),'username'=>$username,'password'=>$password]);
    }
    [$teacher,$student,$student2,$outsider,$otherTeacher,$admin]=$clients;
    $course=db_exec("INSERT INTO courses (code,title,status) VALUES ('ATT-HTTP','Attendance fixture','published')");
    db_exec('INSERT INTO course_teachers VALUES (?,?)',[$course,$users[0]]);
    $group=db_exec("INSERT INTO student_groups (name) VALUES ('Attendance HTTP group')");
    db_exec('INSERT INTO course_groups VALUES (?,?)',[$course,$group]);
    db_exec('INSERT INTO group_members VALUES (?,?),(?,?)',[$group,$users[1],$group,$users[2]]);
    db_exec('INSERT INTO enrollments (course_id,user_id) VALUES (?,?)',[$course,$users[1]]);
    $today=date('Y-m-d'); $yesterday=date('Y-m-d',strtotime('-1 day')); $tomorrow=date('Y-m-d',strtotime('+1 day'));
    $book='admin/attendance.php?course_id='.$course;
    $self='attendance.php?course_id='.$course;
    $html=request_page($teacher,$book);
    ensure(str_contains($html,'name="term_start"'),'Teacher calendar');
    $settings=['action'=>'settings','term_start'=>$yesterday,'term_end'=>date('Y-m-d',strtotime('+30 days')),'weekdays'=>['1','2','3','4','5','6','7'],'holidays'=>'','self_checkin'=>'1'];
    $postSettings=function($changes=[]) use ($teacher,$book,$settings) {
        $html=request_page($teacher,$book);
        return request_page($teacher,$book,array_replace($settings,['csrf'=>field($html,'csrf'),'version'=>field($html,'version')],$changes));
    };
    $postSettings();
    ensure(db_value('SELECT self_checkin FROM attendance_config WHERE course_id=?',[$course])===1,'Calendar saved');
    $html=request_page($student,$self);
    $studentCsrf=field($html,'csrf');
    request_page($student,$self,['csrf'=>field($html,'csrf'),'date'=>$yesterday,'user_id'=>$users[2]]);
    $r=db_one('SELECT * FROM attendance_records WHERE course_id=?',[$course]);
    ensure($r && (int)$r['user_id']===$users[1] && $r['attendance_date']===$today,'Check-in uses session identity and server date');
    request_page($student,$self,['csrf'=>field($html,'csrf')]);
    ensure((int)db_value('SELECT COUNT(*) FROM attendance_records WHERE course_id=?',[$course])===1,'Duplicate check-in');
    $html=request_page($teacher,$book);
    ensure(substr_count($html,'name="marks['.$users[1].']"')===1,'Group and individual enrollment deduplicated');
    ensure(str_contains($html,'data-attendance-all="sick_leave"') && str_contains($html,'data-attendance-all="personal_leave"'),'Five status columns and bulk controls');
    $mark=['csrf'=>field($html,'csrf'),'version'=>field($html,'version'),'action'=>'mark','marks'=>[$users[1]=>'late',$users[2]=>'sick_leave'],'notes'=>[$users[1]=>'Private attendance note',$users[2]=>'Approved leave']];
    request_page($teacher,$book,$mark);
    ensure(db_value('SELECT status FROM attendance_records WHERE course_id=? AND user_id=?',[$course,$users[1]])==='late','Teacher correction');
    ensure(db_value('SELECT status FROM attendance_records WHERE course_id=? AND user_id=?',[$course,$users[2]])==='sick_leave','Sick leave saved');
    ensure(str_contains(request_page($student2,$self),'ลาป่วย'),'Student sees sick leave');
    if (in_array('--preview',$argv,true)) {
        $preview=request_page($teacher,$book);
        $preview=preg_replace('/name="csrf" value="[^"]*"/','name="csrf" value="preview-only"',$preview);
        file_put_contents(__DIR__.'/attendance-preview.html',$preview);
    }
    request_page($teacher,$book,array_replace($mark,['marks'=>[$users[1]=>'absent']]));
    ensure(db_value('SELECT status FROM attendance_records WHERE course_id=? AND user_id=?',[$course,$users[1]])==='late','Stale form rejected');
    $html=request_page($teacher,$book);
    request_page($teacher,$book,['csrf'=>field($html,'csrf'),'version'=>field($html,'version'),'action'=>'mark','marks'=>[$users[2]=>'personal_leave']]);
    ensure(db_value('SELECT status FROM attendance_records WHERE course_id=? AND user_id=?',[$course,$users[2]])==='personal_leave','Personal leave saved');
    ensure(str_contains(request_page($student2,$self),'ลากิจ'),'Student sees personal leave');
    db_exec("UPDATE attendance_records SET status='leave' WHERE course_id=? AND user_id=?",[$course,$users[2]]);
    ensure(str_contains(request_page($student2,$self),'ลา (ข้อมูลเดิม)'),'Legacy leave preserved for student');
    ensure(str_contains(request_page($teacher,$book),'ลา (ข้อมูลเดิม)'),'Legacy leave preserved for teacher');
    $html=request_page($student,$self); request_page($student,$self,['csrf'=>$studentCsrf]);
    ensure(db_value('SELECT status FROM attendance_records WHERE course_id=? AND user_id=?',[$course,$users[1]])==='late','Student cannot overwrite teacher');
    ensure(!str_contains(request_page($student2,$self.'&user_id='.$users[1]),'Private attendance note'),'Student history private');
    foreach ([$student,$outsider,$otherTeacher] as $client) {
        request_page($client,$book); ensure(curl_getinfo($client,CURLINFO_HTTP_CODE)===403,'Unauthorized teacher page');
    }
    request_page($outsider,$self); ensure(curl_getinfo($outsider,CURLINFO_HTTP_CODE)===403,'Non-enrolled student blocked');
    $result=$postSettings(['holidays'=>$today.' | Holiday on existing record']);
    ensure(str_contains($result,'มีข้อมูลเช็คชื่อแล้ว'),'Calendar cannot hide old attendance');
    $html=request_page($teacher,$book.'&date='.$tomorrow);
    request_page($teacher,$book.'&date='.$tomorrow,['csrf'=>field($html,'csrf'),'version'=>field($html,'version'),'action'=>'mark','marks'=>[$users[1]=>'present']]);
    ensure(!db_value('SELECT 1 FROM attendance_records WHERE course_id=? AND attendance_date=?',[$course,$tomorrow]),'Future attendance blocked');
    $postSettings(['holidays'=>$yesterday.' | Holiday']);
    $html=request_page($teacher,$book.'&date='.$yesterday);
    request_page($teacher,$book.'&date='.$yesterday,['csrf'=>field($html,'csrf'),'version'=>field($html,'version'),'action'=>'mark','marks'=>[$users[1]=>'present']]);
    ensure(!db_value('SELECT 1 FROM attendance_records WHERE course_id=? AND attendance_date=?',[$course,$yesterday]),'Holiday attendance blocked');
    // Remove only fixture records to verify a student's direct POST on a holiday is rejected.
    db_exec('DELETE FROM attendance_records WHERE course_id=?',[$course]);
    $postSettings(['holidays'=>$today.' | Today holiday']);
    $html=request_page($student,$self); request_page($student,$self,['csrf'=>$studentCsrf]);
    ensure(!db_value('SELECT 1 FROM attendance_records WHERE course_id=?',[$course]),'Student holiday POST blocked');
    $postSettings(['self_checkin'=>'0']);
    $html=request_page($student,$self); request_page($student,$self,['csrf'=>$studentCsrf]);
    ensure(!db_value('SELECT 1 FROM attendance_records WHERE course_id=?',[$course]),'Self check-in switch enforced');
    $html=request_page($teacher,$book);
    request_page($teacher,$book,['csrf'=>'invalid','version'=>field($html,'version'),'action'=>'mark','marks'=>[$users[1]=>'present']]);
    ensure(!db_value('SELECT 1 FROM attendance_records WHERE course_id=?',[$course]),'CSRF rejected');
    echo "Attendance HTTP tests passed: calendar, duplicate check-in, teacher correction, group roster, privacy, stale forms, holiday/future dates, CSRF\n";

    // Link changes are temporary and the original setting is restored in finally.
    $linksPage='admin/related_sites.php';
    foreach ([$teacher,$student] as $client) { request_page($client,$linksPage); ensure(curl_getinfo($client,CURLINFO_HTTP_CODE)===403,'Only admin edits links'); }
    $links=['docshub'=>'https://example.com/docs?a=1&b=2','prompthub'=>'https://example.com/prompts','teachhub'=>'https://example.com/teach','imagehub'=>'https://example.com/images'];
    $html=request_page($admin,$linksPage);
    request_page($admin,$linksPage,['csrf'=>field($html,'csrf'),'links'=>$links]);
    ensure(json_decode(db_value("SELECT value FROM settings WHERE name='related_site_urls'"),true)===$links,'Links saved');
    $home=request_page($admin,'index.php');
    ensure(str_contains($home,'href="https://example.com/docs?a=1&amp;b=2"'),'Homepage uses escaped saved URL');
    foreach (['javascript:alert(1)','data:text/html,test','https://user:pass@example.com/','not-a-url',[]] as $bad) {
        $html=request_page($admin,$linksPage); $invalid=$links; $invalid['docshub']=$bad;
        request_page($admin,$linksPage,['csrf'=>field($html,'csrf'),'links'=>$invalid]);
        ensure(json_decode(db_value("SELECT value FROM settings WHERE name='related_site_urls'"),true)===$links,'Invalid URL did not partially save');
    }
    request_page($admin,$linksPage,['csrf'=>'invalid','links'=>array_fill_keys(array_keys($links),'https://example.org/')]);
    ensure(json_decode(db_value("SELECT value FROM settings WHERE name='related_site_urls'"),true)===$links,'Link CSRF rejected');
    echo "Related sites HTTP tests passed: admin permission, save, homepage, invalid URL validation, CSRF\n";
} finally {
    if ($savedLinks) db_exec("INSERT INTO settings (name,value) VALUES ('related_site_urls',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[$savedLinks['value']]);
    else db_exec("DELETE FROM settings WHERE name='related_site_urls'");
    if ($course) db_exec('DELETE FROM courses WHERE id=?',[$course]);
    if ($group) db_exec('DELETE FROM student_groups WHERE id=?',[$group]);
    foreach ($users as $uid) db_exec('DELETE FROM users WHERE id=?',[$uid]);
    foreach ($clients as $client) curl_close($client);
    echo "Test fixtures removed and original links restored\n";
}
