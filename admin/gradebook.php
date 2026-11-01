<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$me=require_role('admin','teacher');
$courseId=get_int('course_id');
$course=db_one('SELECT * FROM courses WHERE id=?',[$courseId]);
if (!$course || !can_manage_course($courseId)) { http_response_code(403); exit('ไม่มีสิทธิ์จัดการคะแนนรายวิชานี้'); }
$pageTitle='สมุดคะแนนและตัดเกรด — '.$course['code'].' '.$course['title'];
if (!gradebook_ready()) {
    require __DIR__ . '/_top.php';
    echo '<div class="alert alert-warning">กรุณารัน database/migrations/1.14.0.sql ก่อนใช้สมุดคะแนน</div>';
    require __DIR__ . '/_bottom.php'; exit;
}
$groups=grade_groups($courseId); $groupId=get_int('group_id'); $studentId=get_int('student_id');
$groupNames=array_column($groups,'name','id');
if ($groupId && !isset($groupNames[$groupId])) { http_response_code(403); exit('กลุ่มนี้ไม่ได้รับสิทธิ์ในรายวิชา'); }
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $conn->begin_transaction();
    try {
        db_exec('INSERT IGNORE INTO course_grade_config (course_id,scale_json) VALUES (?,?)',[$courseId,json_encode(grade_default_scale())]);
        $locked=db_one('SELECT * FROM course_grade_config WHERE course_id=? FOR UPDATE',[$courseId]);
        if ((int)post('config_version',-1)!==(int)$locked['version']) throw new RuntimeException('ข้อมูลคะแนนเปลี่ยนแปลงแล้ว กรุณาตรวจสอบข้อมูลล่าสุดก่อนบันทึกอีกครั้ง');
        $action=post('action');
        if ($action==='sync') {
            grade_sync($courseId);
        } elseif ($action==='add_manual') {
            $title=post('title');
            if (!is_string($title) || $title==='' || mb_strlen($title)>200) throw new RuntimeException('กรุณากรอกชื่อรายการคะแนนไม่เกิน 200 ตัวอักษร');
            $max=grade_number(post('max_score'),10000);
            if ($max<=0) throw new RuntimeException('คะแนนเต็มต้องมากกว่า 0');
            db_exec("INSERT INTO grade_items (course_id,source_key,kind,title,max_score) VALUES (?,?,'manual',?,?)",[$courseId,'manual:'.bin2hex(random_bytes(16)),$title,$max]);
        } elseif ($action==='save_plan') {
            $items=db_all('SELECT * FROM grade_items WHERE course_id=? ORDER BY id',[$courseId]);
            $weights=post('weights',[]); $policies=post('policies',[]);
            if (!is_array($weights) || !is_array($policies)) throw new RuntimeException('สัดส่วนคะแนนไม่ถูกต้อง');
            $sum=0;
            foreach ($items as $item) {
                $weight=grade_number($weights[$item['id']] ?? '',100); $sum+=(int)round($weight*100);
                $policy=$policies[$item['id']] ?? 'latest';
                if (!in_array($policy,['latest','highest'],true)) throw new RuntimeException('วิธีเลือกคะแนนไม่ถูกต้อง');
                db_exec('UPDATE grade_items SET weight=?,attempt_policy=? WHERE id=? AND course_id=?',[$weight,$policy,(int)$item['id'],$courseId]);
            }
            if ($sum!==10000) throw new RuntimeException('สัดส่วนคะแนนรวมต้องเท่ากับ 100%');
            $scale=grade_validate_scale(post('scale_min',[]),post('scale_label',[]));
            db_exec('UPDATE course_grade_config SET scale_json=? WHERE course_id=?',[json_encode($scale,JSON_UNESCAPED_UNICODE),$courseId]);
        } elseif ($action==='manual_score') {
            $target=(int)post('user_id');
            if (!in_array($target,array_map('intval',array_column(grade_roster($courseId),'id')),true)) throw new RuntimeException('นักเรียนไม่ได้อยู่ในรายวิชานี้');
            $item=db_one("SELECT * FROM grade_items WHERE id=? AND course_id=? AND kind='manual'",[(int)post('item_id'),$courseId]);
            if (!$item) throw new RuntimeException('ไม่พบรายการคะแนน');
            $score=grade_number(post('score'),(float)$item['max_score']);
            $note=post('note');
            if (!is_string($note) || mb_strlen($note)>1000) throw new RuntimeException('หมายเหตุยาวได้ไม่เกิน 1,000 ตัวอักษร');
            $existing=db_one('SELECT * FROM grade_manual_scores WHERE item_id=? AND user_id=? FOR UPDATE',[(int)$item['id'],$target]);
            if ((int)post('score_version',-2)!==($existing ? (int)$existing['version'] : -1)) throw new RuntimeException('คะแนนนี้มีการแก้ไขแล้ว กรุณาตรวจสอบคะแนนล่าสุด');
            db_exec('INSERT INTO grade_manual_scores (item_id,user_id,score,note,updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score),note=VALUES(note),updated_by=VALUES(updated_by),updated_at=NOW(),version=version+1',[(int)$item['id'],$target,$score,$note,(int)$me['id']]);
        } elseif ($action==='finalize') {
            $data=gradebook_data($courseId,$groupId);
            $token=post('finalize_token'); $pending=is_string($token) ? ($_SESSION['grade_previews'][$token] ?? null) : null;
            if (!$pending || $pending['course']!==$courseId || $pending['group']!==$groupId || !hash_equals($pending['hash'],gradebook_fingerprint($data))) throw new RuntimeException('คะแนนหรือเกณฑ์เปลี่ยนไปจากหน้าตัวอย่าง กรุณาตรวจสอบแล้วบันทึกเกรดใหม่');
            if (!$data['rows']) throw new RuntimeException('ไม่มีนักเรียนในกลุ่มที่เลือก');
            foreach ($data['rows'] as $row) {
                $r=$row['result'];
                if (!$r['weight_valid']) throw new RuntimeException('กรุณาตั้งสัดส่วนคะแนนให้รวม 100% ก่อนตัดเกรด');
                if ($r['pending'] || $r['unavailable']) throw new RuntimeException('ยังมีงานรอตรวจ/ให้แก้ไข หรือรายการคะแนนที่ถูกลบ กรุณาจัดการก่อนตัดเกรด');
                if ($r['missing'] && post('missing_zero')!=='1') throw new RuntimeException('มีคะแนนที่ยังไม่มีข้อมูล กรุณาให้คะแนนก่อน หรือเลือกยืนยันให้นับรายการที่ขาดเป็นศูนย์');
            }
            $runId=db_exec('INSERT INTO grade_runs (course_id,group_name,plan_json,created_by) VALUES (?,?,?,?)',[$courseId,$groupId ? $groupNames[$groupId] : null,json_encode(['items'=>$data['items'],'scale'=>$data['config']['scale'],'rounding'=>'round total to 2 decimals before grading','missing_zero'=>post('missing_zero')==='1'],JSON_UNESCAPED_UNICODE),(int)$me['id']]);
            foreach ($data['rows'] as $row) db_exec('INSERT INTO grade_results (run_id,user_id,total,grade_label,snapshot_json) VALUES (?,?,?,?,?)',[$runId,(int)$row['student']['id'],$row['result']['total'],$row['result']['grade'],json_encode($row,JSON_UNESCAPED_UNICODE)]);
            unset($_SESSION['grade_previews'][$token]);
        } else throw new RuntimeException('คำสั่งไม่ถูกต้อง');
        db_exec('UPDATE course_grade_config SET version=version+1 WHERE course_id=?',[$courseId]);
        $conn->commit();
        flash('success',$action==='finalize' ? 'บันทึกผลเกรดแล้ว (เก็บฉบับคะแนนและเกณฑ์ที่ใช้ตัดเกรดไว้)' : 'บันทึกสมุดคะแนนแล้ว');
        redirect('admin/gradebook.php?'.http_build_query(['course_id'=>$courseId,'group_id'=>$groupId,'student_id'=>$studentId]));
    } catch (RuntimeException $ex) { $conn->rollback(); $error=$ex->getMessage(); }
}
$data=gradebook_data($courseId,$groupId); $items=$data['items']; $config=$data['config'];
if ($studentId && !in_array($studentId,array_map('intval',array_column(array_column($data['rows'],'student'),'id')),true)) { http_response_code(403); exit('นักเรียนไม่ได้อยู่ในรายวิชาหรือกลุ่มที่เลือก'); }
$savedRows=db_all('SELECT r.*,g.created_at,g.group_name,g.plan_json FROM grade_results r JOIN grade_runs g ON g.id=r.run_id WHERE g.course_id=? AND r.run_id=(SELECT MAX(r2.run_id) FROM grade_results r2 JOIN grade_runs g2 ON g2.id=r2.run_id WHERE g2.course_id=? AND r2.user_id=r.user_id)',[$courseId,$courseId]);
$saved=[]; foreach ($savedRows as $entry) $saved[$entry['user_id']]=$entry;
$rows=$studentId ? array_values(array_filter($data['rows'],fn($row)=>(int)$row['student']['id']===$studentId)) : $data['rows'];
if (in_array($_GET['export'] ?? '',['current','saved'],true)) {
    $isSaved=$_GET['export']==='saved';
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="grades-'.$courseId.($isSaved ? '-saved' : '-preview').'.csv"'); header('Cache-Control: private, no-store');
    $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
    $csv=function(array $cells) use ($out) {
        $cells=array_map(function($cell) { $value=(string)$cell; return preg_match('/^[\s]*[=+\-@\t\r]/u',$value) ? "'".$value : $value; },$cells);
        fputcsv($out,$cells,',','"','');
    };
    $csv(['รหัสนักเรียน','ชื่อ-นามสกุล','กลุ่มเรียน','คะแนนรวม','เกรด','สถานะ','วันที่บันทึก']);
    foreach ($rows as $row) {
        $s=$row['student']; $r=$row['result']; $prior=$saved[$s['id']] ?? null;
        if ($isSaved && !$prior) continue;
        if ($isSaved) $s=json_decode($prior['snapshot_json'],true)['student'];
        $csv([$s['student_code'],$s['fullname'],$s['group_names'],$isSaved ? $prior['total'] : $r['total'],$isSaved ? $prior['grade_label'] : $r['grade'],$isSaved ? 'ผลเกรดที่บันทึกแล้ว' : 'ตัวอย่าง (ขาด '.$r['missing'].' รอตรวจ '.$r['pending'].' ใช้ไม่ได้ '.$r['unavailable'].')',$isSaved ? $prior['created_at'] : '']);
    }
    fclose($out); exit;
}
$token=bin2hex(random_bytes(32));
$_SESSION['grade_previews']=array_slice($_SESSION['grade_previews'] ?? [],-9,null,true);
$_SESSION['grade_previews'][$token]=['course'=>$courseId,'group'=>$groupId,'hash'=>gradebook_fingerprint($data)];
$weightSum=array_sum(array_column($items,'weight'));
$query=['course_id'=>$courseId,'group_id'=>$groupId];
require __DIR__ . '/_top.php';
?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-light" href="<?= url('admin/lessons.php?course_id='.$courseId) ?>">กลับรายวิชา</a>
    <a class="btn btn-outline-secondary" href="<?= url('admin/grade_history.php?course_id='.$courseId) ?>">ประวัติผลเกรด</a>
    <a class="btn btn-outline-primary" href="?<?= e(http_build_query($query+['export'=>'current','student_id'=>$studentId])) ?>">ส่งออกคะแนนตัวอย่าง CSV</a>
    <a class="btn btn-outline-success" href="?<?= e(http_build_query($query+['export'=>'saved','student_id'=>$studentId])) ?>">ส่งออกผลเกรดที่บันทึก CSV</a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="get" class="row g-2 mb-4">
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
    <div class="col-md-5"><label class="form-label w-100">กลุ่มเรียน<select name="group_id" class="form-select"><option value="0">ผู้เรียนทั้งหมด (รวมลงทะเบียนรายบุคคล)</option><?php foreach ($groups as $group): ?><option value="<?= (int)$group['id'] ?>" <?= $groupId===(int)$group['id'] ? 'selected' : '' ?>><?= e($group['name']) ?></option><?php endforeach; ?></select></label></div>
    <div class="col-md-5"><label class="form-label w-100">นักเรียน<select name="student_id" class="form-select"><option value="0">ทุกคนในกลุ่ม</option><?php foreach (array_column($data['rows'],'student') as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $studentId===(int)$s['id'] ? 'selected' : '' ?>><?= e($s['student_code'].' '.$s['fullname']) ?></option><?php endforeach; ?></select></label></div>
    <div class="col-md-2 align-self-center"><button class="btn btn-vt">แสดงคะแนน</button></div>
</form>
<details class="card p-3 mb-4" <?= !$items ? 'open' : '' ?>>
    <summary class="fw-semibold">กำหนดรายการคะแนน สัดส่วน และเกณฑ์ตัดเกรด (รวม <?= e(number_format($weightSum,2)) ?>%)</summary>
    <div class="d-flex flex-wrap gap-3 my-3">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="config_version" value="<?= $config['version'] ?>"><button name="action" value="sync" class="btn btn-outline-primary">ดึงแบบทดสอบและงานมอบหมายในรายวิชา</button></form>
        <form method="post" class="d-flex flex-wrap gap-2"><?= csrf_field() ?><input type="hidden" name="config_version" value="<?= $config['version'] ?>"><input name="title" class="form-control w-auto" required maxlength="200" placeholder="เพิ่มคะแนน เช่น เข้าเรียน / สอบปลายภาค"><input name="max_score" class="form-control w-auto" type="number" min="0.01" max="10000" step="0.01" required value="100" aria-label="คะแนนเต็มรายการใหม่"><button name="action" value="add_manual" class="btn btn-outline-secondary">เพิ่มรายการกรอกคะแนนเอง</button></form>
    </div>
    <form method="post" id="grade-plan">
        <?= csrf_field() ?><input type="hidden" name="config_version" value="<?= $config['version'] ?>"><input type="hidden" name="action" value="save_plan">
        <p class="small text-muted">กำหนดสัดส่วนรวม 100% รายการที่ไม่ใช้ให้ใส่ 0% · คะแนนแต่ละรายการคิดเป็น (คะแนนที่ได้ ÷ คะแนนเต็มของครั้งที่สอบ/ส่งงาน) × สัดส่วน · คะแนนรวมปัดทศนิยม 2 ตำแหน่งก่อนเทียบเกรด</p>
        <div class="table-responsive"><table class="table"><thead><tr><th>รายการ</th><th>ชนิด</th><th>สัดส่วน %</th><th>ใช้คะแนนครั้งใด</th></tr></thead><tbody>
        <?php foreach ($items as $item): ?><tr><td><?= e($item['title']) ?></td><td><?= ['quiz'=>'ข้อสอบ','assignment'=>'งาน','manual'=>'กรอกเอง'][$item['kind']] ?></td><td><input class="form-control grade-weight" type="number" min="0" max="100" step="0.01" name="weights[<?= (int)$item['id'] ?>]" value="<?= e($item['weight']) ?>" required aria-label="สัดส่วน <?= e($item['title']) ?>"></td><td><select class="form-select" name="policies[<?= (int)$item['id'] ?>]"><option value="latest" <?= $item['attempt_policy']==='latest' ? 'selected' : '' ?>>ล่าสุด</option><option value="highest" <?= $item['attempt_policy']==='highest' ? 'selected' : '' ?>>สูงสุด (คิดตามร้อยละ)</option></select></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <button type="button" id="equal-weights" class="btn btn-sm btn-light mb-3">แบ่งสัดส่วนเท่ากันทุกรายการ</button>
        <h2 class="h6">เกณฑ์เกรด (คะแนนขั้นต่ำเรียงจากมากไปน้อย)</h2>
        <div class="d-flex gap-2 mb-2"><button type="button" class="btn btn-sm btn-light" data-grade-preset="numeric">เกณฑ์ 0–4</button><button type="button" class="btn btn-sm btn-light" data-grade-preset="letter">เกณฑ์ A–F</button></div>
        <div class="row g-2" id="grade-scale">
        <?php for ($i=0;$i<10;$i++): $band=$config['scale'][$i] ?? ['min'=>'','label'=>'']; ?>
            <div class="col-6 col-md-3"><div class="input-group"><input type="number" name="scale_min[]" min="0" max="100" step="0.01" class="form-control" placeholder="ขั้นต่ำ" value="<?= e($band['min']) ?>" aria-label="คะแนนขั้นต่ำเกรดที่ <?= $i+1 ?>"><input name="scale_label[]" class="form-control" maxlength="10" placeholder="เกรด" value="<?= e($band['label']) ?>" aria-label="ชื่อเกรดที่ <?= $i+1 ?>"></div></div>
        <?php endfor; ?></div>
        <button class="btn btn-vt mt-3">บันทึกสัดส่วนและเกณฑ์</button>
    </form>
</details>
<p><?= count($rows) ?> คน · คะแนนปัจจุบันเป็นตัวอย่างจนกดบันทึกผลเกรด · งานฉบับล่าสุดที่รอตรวจหรือให้แก้ไขต้องจัดการก่อนตัดเกรด</p>
<div class="table-responsive card mb-4"><table class="table table-bordered mb-0"><thead><tr><th>รหัส / ชื่อ</th><th>กลุ่ม</th><?php foreach ($items as $item): if ((float)$item['weight']<=0) continue; ?><th><?= e($item['title']) ?><br><small><?= e($item['weight']) ?>%</small></th><?php endforeach; ?><th>รวม / เกรดตัวอย่าง</th><th>ผลเกรดที่บันทึกล่าสุด</th></tr></thead><tbody>
<?php foreach ($rows as $row): $s=$row['student']; $r=$row['result']; $prior=$saved[$s['id']] ?? null; ?>
<tr><td><a href="?<?= e(http_build_query($query+['student_id'=>(int)$s['id']])) ?>"><?= e($s['student_code'].' '.$s['fullname']) ?></a></td><td><?= e($s['group_names'] ?: 'รายบุคคล') ?></td>
<?php foreach ($items as $item): if ((float)$item['weight']<=0) continue; $cell=$row['cells'][$item['id']]; ?><td><?= $cell['score']!==null ? e($cell['score']).' / '.e($cell['max']) : '—' ?><?php if ($cell['state']!=='scored'): ?><br><small class="text-warning-emphasis"><?= ['missing'=>'ไม่มีคะแนน (คิด 0)','pending'=>'รอตรวจ / ให้แก้ไข','unavailable'=>'ต้นทางถูกลบ / เปลี่ยนชนิด'][$cell['state']] ?></small><?php endif; ?></td><?php endforeach; ?>
<td><strong><?= number_format($r['total'],2) ?> / <?= e($r['grade']) ?></strong><?php if (!$r['weight_valid']): ?><br>สัดส่วนยังไม่ครบ 100%<?php endif; ?></td><td><?= $prior ? e($prior['total']).' / '.e($prior['grade_label']).'<br><small>'.e(thai_date($prior['created_at'],true)).'</small>' : 'ยังไม่บันทึก' ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="<?= count($items)+4 ?>">ไม่มีนักเรียนในกลุ่มที่เลือก</td></tr><?php endif; ?></tbody></table></div>
<?php if ($studentId && $rows): $detail=$rows[0]; ?>
<section class="card p-3 mb-4"><h2 class="h5">คะแนนรายบุคคล — <?= e($detail['student']['fullname']) ?></h2>
<?php foreach ($items as $item): $cell=$detail['cells'][$item['id']]; ?>
<div class="border-bottom py-3"><h3 class="h6"><?= e($item['title']) ?> · สัดส่วน <?= e($item['weight']) ?>%</h3>
<?php if ($item['kind']==='manual'): ?><form method="post" class="d-flex flex-wrap gap-2"><?= csrf_field() ?><input type="hidden" name="action" value="manual_score"><input type="hidden" name="config_version" value="<?= $config['version'] ?>"><input type="hidden" name="user_id" value="<?= $studentId ?>"><input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="score_version" value="<?= $cell['version'] ?>"><label>คะแนน (เต็ม <?= e($item['max_score']) ?>)<input name="score" type="number" min="0" max="<?= e($item['max_score']) ?>" step="0.01" class="form-control" required value="<?= e($cell['score']) ?>"></label><label>หมายเหตุ<input name="note" maxlength="1000" class="form-control" value="<?= e($cell['note']) ?>"></label><button class="btn btn-outline-primary align-self-end">บันทึกคะแนน</button></form>
<?php else: ?><p><?= $cell['score']!==null ? e($cell['score']).' / '.e($cell['max']) : 'ยังไม่มีคะแนน' ?> · <?= e(['scored'=>'มีคะแนนแล้ว','missing'=>'ยังไม่มีคะแนน','pending'=>'รอตรวจ / ให้แก้ไข','unavailable'=>'ต้นทางถูกลบหรือเปลี่ยนชนิด'][$cell['state']]) ?></p><?php if ($item['lesson_id']): ?><a href="<?= url('admin/'.($item['kind']==='quiz' ? 'quiz_results.php' : 'assignments.php').'?lesson_id='.$item['lesson_id']) ?>">เปิดรายการคะแนน / ตรวจงาน</a><?php endif; ?><?php endif; ?></div>
<?php endforeach; ?></section>
<?php endif; ?>
<form method="post" class="card p-3 mb-4">
    <?= csrf_field() ?><input type="hidden" name="action" value="finalize"><input type="hidden" name="config_version" value="<?= $config['version'] ?>"><input type="hidden" name="finalize_token" value="<?= e($token) ?>">
    <h2 class="h5">บันทึกผลเกรด — <?= e($groupId ? $groupNames[$groupId] : 'นักเรียนทั้งหมดในรายวิชา') ?> (<?= count($data['rows']) ?> คน)</h2>
    <p>ประกาศให้นักเรียนเห็นในเมนูผลการเรียน บันทึกทั้งกลุ่มที่เลือก แม้กำลังดูรายบุคคล เก็บคะแนนและเกณฑ์ฉบับนี้ไว้เป็นประวัติ เมื่อคะแนนเปลี่ยนให้ตรวจสอบและบันทึกผลเกรดรอบใหม่</p>
    <label class="form-check ms-2 mb-3"><input type="checkbox" class="form-check-input" name="missing_zero" value="1"> ยืนยันให้รายการที่ยังไม่มีคะแนนเป็น 0 (ใช้ไม่ได้กับงานรอตรวจ / ให้แก้ไข)</label>
    <button class="btn btn-success align-self-start">บันทึกและประกาศผลเกรดของกลุ่มที่เลือก</button>
</form>
<script>
document.querySelector('[name=group_id]').addEventListener('change',function(){document.querySelector('[name=student_id]').value='0';});
document.getElementById('equal-weights').addEventListener('click',function(){var inputs=document.querySelectorAll('.grade-weight'),n=inputs.length;if(!n)return;var cents=Math.floor(10000/n);inputs.forEach(function(input,i){input.value=((cents+(i===n-1 ? 10000-cents*n : 0))/100).toFixed(2);});});
document.querySelectorAll('[data-grade-preset]').forEach(function(button){button.addEventListener('click',function(){var scale=button.dataset.gradePreset==='numeric'?[[80,'4'],[75,'3.5'],[70,'3'],[65,'2.5'],[60,'2'],[55,'1.5'],[50,'1'],[0,'0']]:[[80,'A'],[70,'B'],[60,'C'],[50,'D'],[0,'F']];document.querySelectorAll('[name="scale_min[]"]').forEach(function(input,i){input.value=scale[i]?scale[i][0]:'';});document.querySelectorAll('[name="scale_label[]"]').forEach(function(input,i){input.value=scale[i]?scale[i][1]:'';});});});
</script>
<?php require __DIR__ . '/_bottom.php'; ?>
