<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/functions.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');
$base = 'http://localhost/lms/';
function ensure($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function request_page($client, $path, $data = null) {
    global $base;
    curl_setopt_array($client, [CURLOPT_URL => $base . $path, CURLOPT_POST => $data !== null, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20]);
    if ($data !== null) curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($data));
    $body = curl_exec($client);
    ensure($body !== false, curl_error($client));
    ensure(!str_contains($body, 'Fatal error') && !str_contains($body, 'Warning:'), 'PHP output error on ' . $path);
    return $body;
}
function field($html, $name) {
    preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m);
    ensure(isset($m[1]), 'Missing field: ' . $name);
    return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
}
$users=[]; $clients=[]; $course=null; $groups=[];
function version_of($html) { return field($html,'config_version'); }
try {
    $password=bin2hex(random_bytes(20));
    foreach (['teacher','student','student','teacher'] as $role) {
        $username='grade_http_'.bin2hex(random_bytes(6));
        $users[]=db_exec('INSERT INTO users (username,password,fullname,role,consent_version,consent_at) VALUES (?,?,?,?,?,NOW())',[$username,password_hash($password,PASSWORD_DEFAULT),'Grade HTTP fixture',$role,setting('privacy_version','1')]);
        $client=curl_init(); curl_setopt($client,CURLOPT_COOKIEFILE,''); $clients[]=$client;
        $html=request_page($client,'login.php'); request_page($client,'login.php',['csrf'=>field($html,'csrf'),'username'=>$username,'password'=>$password]);
    }
    [$teacher,$student,$student2,$outsider]=$clients;
    $course=db_exec("INSERT INTO courses (code,title,status,ai_student_enabled) VALUES ('GRADE-TEST','Grade HTTP fixture','published',0)");
    db_exec('UPDATE courses SET sequential_lessons=0 WHERE id=?',[$course]); // ทดสอบการให้คะแนน ไม่ใช่การเรียนตามลำดับ
    db_exec('INSERT INTO course_teachers VALUES (?,?)',[$course,$users[0]]);
    foreach (['Group one','Group two'] as $name) {
        $groups[]=db_exec('INSERT INTO student_groups (name) VALUES (?)',[$name]);
        db_exec('INSERT INTO course_groups VALUES (?,?)',[$course,end($groups)]);
    }
    db_exec('INSERT INTO group_members VALUES (?,?),(?,?),(?,?)',[$groups[0],$users[1],$groups[1],$users[1],$groups[1],$users[2]]);
    db_exec('INSERT INTO enrollments (course_id,user_id) VALUES (?,?)',[$course,$users[1]]);
    $quizData=json_encode([['text'=>'Q','options'=>['a','b'],'correct'=>0]]);
    $quiz=db_exec("INSERT INTO lessons (course_id,title,media_type,quiz_data) VALUES (?,'Quiz','quiz',?)",[$course,$quizData]);
    $assignment=db_exec("INSERT INTO lessons (course_id,title,media_type,assignment_points) VALUES (?,'Report','assignment',20)",[$course]);
    $extra=db_exec("INSERT INTO lesson_media (lesson_id,title,media_type,assignment_points) VALUES (?,'Optional','assignment',10)",[$assignment]);
    foreach ([[8,10],[9,20]] as [$score,$total]) db_exec('INSERT INTO quiz_attempts (lesson_id,user_id,quiz_title,score,total,answers,quiz_snapshot,submission_token) VALUES (?,?,?,?,?,?,?,?)',[$quiz,$users[1],'Quiz',$score,$total,'[]',$quizData,bin2hex(random_bytes(32))]);
    db_exec("INSERT INTO assignment_submissions (lesson_id,user_id,assignment_title,max_score,score,status,submission_token) VALUES (?,?,'Report',10,9,'graded',?)",[$assignment,$users[1],bin2hex(random_bytes(32))]);
    $pending=db_exec("INSERT INTO assignment_submissions (lesson_id,user_id,assignment_title,max_score,submission_text,submission_token) VALUES (?,?,'Report',20,'Report body',?)",[$assignment,$users[1],bin2hex(random_bytes(32))]);
    $book='admin/gradebook.php?course_id='.$course;
    $html=request_page($teacher,$book);
    $html=request_page($teacher,$book,['csrf'=>field($html,'csrf'),'config_version'=>version_of($html),'action'=>'sync']);
    ensure((int)db_value('SELECT COUNT(*) FROM grade_items WHERE course_id=?',[$course])===3,'Import all assessments');
    $html=request_page($teacher,$book,['csrf'=>field($html,'csrf'),'config_version'=>version_of($html),'action'=>'add_manual','title'=>'Attendance','max_score'=>'100']);
    $items=db_all('SELECT * FROM grade_items WHERE course_id=? ORDER BY id',[$course]);
    $weights=[]; $policies=[]; $manualId=0;
    foreach ($items as $item) {
        $weights[$item['id']]=$item['kind']==='manual' ? '20' : ($item['source_media_id'] ? '0' : '40');
        $policies[$item['id']]=$item['kind']==='quiz' ? 'highest' : 'latest';
        if ($item['kind']==='manual') $manualId=(int)$item['id'];
    }
    $plan=['csrf'=>field($html,'csrf'),'config_version'=>version_of($html),'action'=>'save_plan','weights'=>$weights,'policies'=>$policies,'scale_min'=>['80','75','70','65','60','55','50','0'],'scale_label'=>['4','3.5','3','2.5','2','1.5','1','0']];
    $bad=$plan; $bad['weights'][$manualId]='19.99'; request_page($teacher,$book,$bad);
    ensure((float)db_value('SELECT SUM(weight) FROM grade_items WHERE course_id=?',[$course])===0.0,'Partial plan not rolled back');
    $html=request_page($teacher,$book,$plan);
    $individual=$book.'&group_id='.$groups[0].'&student_id='.$users[1];
    $html=request_page($teacher,$individual);
    $html=request_page($teacher,$individual,['csrf'=>field($html,'csrf'),'config_version'=>version_of($html),'action'=>'manual_score','item_id'=>$manualId,'user_id'=>$users[1],'score'=>'90','score_version'=>'-1','note'=>'Attendance']);
    ensure(db_value('SELECT score FROM grade_manual_scores WHERE item_id=? AND user_id=?',[$manualId,$users[1]])==='90.00','Manual score save');
    $groupBook=$book.'&group_id='.$groups[0];
    $html=request_page($teacher,$groupBook);
    $final=['csrf'=>field($html,'csrf'),'config_version'=>version_of($html),'action'=>'finalize','finalize_token'=>field($html,'finalize_token'),'missing_zero'=>'1'];
    request_page($teacher,$groupBook,$final);
    ensure((int)db_value('SELECT COUNT(*) FROM grade_runs WHERE course_id=?',[$course])===0,'Pending work finalized');
    $report='admin/assignments.php?lesson_id='.$assignment;
    $html=request_page($teacher,$report);
    request_page($teacher,$report,['csrf'=>field($html,'csrf'),'submission_id'=>$pending,'grade_version'=>'0','status'=>'graded','score'=>'15','feedback'=>'Good']);
    // A preview token taken before grading must now be rejected.
    request_page($teacher,$groupBook,$final);
    ensure((int)db_value('SELECT COUNT(*) FROM grade_runs WHERE course_id=?',[$course])===0,'Stale preview finalized');
    $html=request_page($teacher,$groupBook);
    $final=['csrf'=>field($html,'csrf'),'config_version'=>version_of($html),'action'=>'finalize','finalize_token'=>field($html,'finalize_token')];
    request_page($teacher,$groupBook,$final);
    $saved=db_one('SELECT r.* FROM grade_results r JOIN grade_runs g ON g.id=r.run_id WHERE g.course_id=?',[$course]);
    ensure($saved && $saved['total']==='80.00' && $saved['grade_label']==='4','Weighted final grade');
    ensure((int)db_value('SELECT COUNT(*) FROM grade_results r JOIN grade_runs g ON g.id=r.run_id WHERE g.course_id=?',[$course])===1,'Group filtering/deduplication');
    ensure(str_contains(request_page($student,'my-grades.php'),'GRADE-TEST'),'Student saved grade');
    ensure(!str_contains(request_page($student2,'my-grades.php?user_id='.$users[1]),'GRADE-TEST'),'Another student grade disclosure');
    $csv=request_page($teacher,$groupBook.'&export=saved'); ensure(str_contains($csv,'80.00') && str_contains($csv,'Grade HTTP fixture'),'Saved CSV');
    if (in_array('--preview',$argv,true)) {
        $preview=request_page($teacher,$groupBook);
        $preview=preg_replace('/name="(csrf|finalize_token)" value="[^"]*"/','name="$1" value="preview-only"',$preview);
        file_put_contents(__DIR__.'/gradebook-preview.html',$preview);
    }
    $html=request_page($teacher,$book); $v=version_of($html);
    request_page($teacher,$book,['csrf'=>field($html,'csrf'),'config_version'=>$v,'action'=>'finalize','finalize_token'=>field($html,'finalize_token')]);
    ensure((int)db_value('SELECT COUNT(*) FROM grade_runs WHERE course_id=?',[$course])===1,'Missing scores silently finalized');
    $html=request_page($teacher,$book);
    request_page($teacher,$book,['csrf'=>field($html,'csrf'),'config_version'=>version_of($html),'action'=>'finalize','finalize_token'=>field($html,'finalize_token'),'missing_zero'=>'1']);
    ensure((int)db_value('SELECT COUNT(*) FROM grade_results r JOIN grade_runs g ON g.id=r.run_id WHERE g.course_id=?',[$course])===3,'Finalize full roster');
    // History is immutable even when the underlying teacher score changes.
    db_exec('UPDATE grade_manual_scores SET score=0 WHERE item_id=? AND user_id=?',[$manualId,$users[1]]);
    ensure(db_value('SELECT total FROM grade_results WHERE run_id=? AND user_id=?',[(int)$saved['run_id'],$users[1]])==='80.00','Saved grade changed with live score');
    $history=request_page($teacher,'admin/grade_history.php?course_id='.$course.'&run_id='.$saved['run_id']); ensure(str_contains($history,'80.00'),'Grade history');
    foreach ([$student,$outsider] as $client) { request_page($client,$book); ensure(curl_getinfo($client,CURLINFO_HTTP_CODE)===403,'Gradebook unauthorized'); }
    // Timed teaching is enforced on direct URLs and POSTs; teachers can preview.
    $timed=db_exec("INSERT INTO lessons (course_id,title,media_type,content) VALUES (?,'Timed','text','SECRET-TIMED-CONTENT')",[$course]);
    $edit='admin/lesson_form.php?id='.$timed;
    $html=request_page($teacher,$edit);
    request_page($teacher,$edit,['csrf'=>field($html,'csrf'),'title'=>'Timed','media_type'=>'text','content'=>'SECRET-TIMED-CONTENT','is_published'=>'1','available_from'=>'2099-01-01T09:00','available_until'=>'2099-01-02T09:00']);
    ensure(db_value('SELECT available_from FROM lessons WHERE id=?',[$timed])==='2099-01-01 09:00:00','Teaching date save');
    if (in_array('--preview',$argv,true)) {
        $preview=request_page($teacher,$edit);
        $preview=preg_replace('/name="csrf" value="[^"]*"/','name="csrf" value="preview-only"',$preview);
        file_put_contents(__DIR__.'/schedule-preview.html',$preview);
    }
    ensure(!str_contains(request_page($student,'lesson.php?id='.$timed),'SECRET-TIMED-CONTENT'),'Early lesson access');
    ensure(str_contains(request_page($teacher,'lesson.php?id='.$timed),'SECRET-TIMED-CONTENT'),'Teacher timed preview');
    db_exec("UPDATE lessons SET available_from='2000-01-01',available_until='2000-01-02' WHERE id=?",[$timed]);
    ensure(!str_contains(request_page($student,'lesson.php?id='.$timed),'SECRET-TIMED-CONTENT'),'Closed lesson access');
    $workPath='lesson.php?id='.$assignment;
    $html=request_page($student,$workPath);
    $send=['csrf'=>field($html,'csrf'),'action'=>'submit_assignment','submission_token'=>field($html,'submission_token'),'submission_text'=>'Scheduled report'];
    $count=(int)db_value('SELECT COUNT(*) FROM assignment_submissions WHERE lesson_id=?',[$assignment]);
    db_exec("UPDATE lessons SET submission_opens_at='2099-01-01',submission_due_at='2099-01-02' WHERE id=?",[$assignment]);
    request_page($student,$workPath,$send);
    ensure((int)db_value('SELECT COUNT(*) FROM assignment_submissions WHERE lesson_id=?',[$assignment])===$count,'Early submission accepted');
    ensure(!str_contains(request_page($student,$workPath),'value="submit_assignment"'),'Early submission form');
    db_exec("UPDATE lessons SET submission_opens_at='2000-01-01',submission_due_at='2000-01-02' WHERE id=?",[$assignment]);
    request_page($student,$workPath,$send);
    ensure((int)db_value('SELECT COUNT(*) FROM assignment_submissions WHERE lesson_id=?',[$assignment])===$count,'Closed submission accepted');
    db_exec('UPDATE lessons SET accept_late=1 WHERE id=?',[$assignment]);
    $html=request_page($student,$workPath);
    request_page($student,$workPath,['csrf'=>field($html,'csrf'),'action'=>'submit_assignment','submission_token'=>field($html,'submission_token'),'submission_text'=>'Late report']);
    $late=db_one('SELECT * FROM assignment_submissions WHERE lesson_id=? ORDER BY id DESC LIMIT 1',[$assignment]);
    ensure((int)$late['is_late']===1 && $late['due_at_snapshot']==='2000-01-02 00:00:00','Late flag and deadline snapshot');
    echo "Gradebook/scheduling HTTP tests passed: weighted grades, groups, manual scores, pending/missing blocks, snapshots, exports, student access, teaching windows, submission windows and late status\n";
} finally {
    if ($course) db_exec('DELETE FROM courses WHERE id=?',[$course]);
    foreach ($groups as $g) db_exec('DELETE FROM student_groups WHERE id=?',[$g]);
    foreach ($users as $u) db_exec('DELETE FROM users WHERE id=?',[$u]);
    foreach ($clients as $c) curl_close($c);
    echo "Test fixtures removed\n";
}
