<?php
function gradebook_ready(): bool
{
    static $ready;
    return $ready ??= (bool)db_value("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='grade_results'");
}

function grade_default_scale(): array
{
    return [['min'=>80,'label'=>'4'],['min'=>75,'label'=>'3.5'],['min'=>70,'label'=>'3'],['min'=>65,'label'=>'2.5'],['min'=>60,'label'=>'2'],['min'=>55,'label'=>'1.5'],['min'=>50,'label'=>'1'],['min'=>0,'label'=>'0']];
}

function grade_number($value, float $max): float
{
    if (!is_scalar($value) || !preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', (string)$value) || (float)$value > $max) throw new RuntimeException('คะแนนหรือสัดส่วนไม่ถูกต้อง (ทศนิยมไม่เกิน 2 ตำแหน่ง)');
    return (float)$value;
}

function grade_validate_scale($mins, $labels): array
{
    if (!is_array($mins) || !is_array($labels) || count($mins) > 15 || count($labels) > 15) throw new RuntimeException('เกณฑ์เกรดไม่ถูกต้อง');
    $scale = []; $seen = [];
    foreach ($mins as $i => $min) {
        $label = $labels[$i] ?? '';
        if (!is_string($label)) throw new RuntimeException('ชื่อเกรดไม่ถูกต้อง');
        $label = trim($label);
        if ($min === '' && $label === '') continue;
        if ($label === '' || mb_strlen($label) > 10 || isset($seen[$label])) throw new RuntimeException('ชื่อเกรดต้องไม่ว่าง ไม่ซ้ำ และไม่เกิน 10 ตัวอักษร');
        $number = grade_number($min, 100);
        if ($scale && $number >= $scale[count($scale)-1]['min']) throw new RuntimeException('เกณฑ์คะแนนต้องเรียงจากมากไปน้อยและไม่ซ้ำ');
        $scale[] = ['min'=>$number, 'label'=>$label]; $seen[$label] = true;
    }
    if (count($scale) < 2 || $scale[count($scale)-1]['min'] != 0) throw new RuntimeException('ต้องมีอย่างน้อย 2 เกรด และเกณฑ์สุดท้ายต้องเป็น 0');
    return $scale;
}

function grade_label(float $total, array $scale): string
{
    foreach ($scale as $band) if ($total >= $band['min']) return $band['label'];
    throw new RuntimeException('เกณฑ์เกรดไม่ครอบคลุมคะแนน');
}

function grade_groups(int $courseId): array
{
    return db_all('SELECT g.id,g.name FROM student_groups g JOIN course_groups cg ON cg.group_id=g.id WHERE cg.course_id=? ORDER BY g.name', [$courseId]);
}

function grade_roster(int $courseId, int $groupId = 0): array
{
    $params = [$courseId,$courseId,$courseId];
    $filter = '';
    if ($groupId) {
        $filter = ' AND EXISTS (SELECT 1 FROM group_members gm JOIN course_groups cg ON cg.group_id=gm.group_id WHERE gm.user_id=u.id AND cg.course_id=? AND gm.group_id=?)';
        array_push($params, $courseId, $groupId);
    }
    return db_all("SELECT u.id,u.fullname,u.student_code,u.username,
        (SELECT GROUP_CONCAT(g.name ORDER BY g.name SEPARATOR ', ') FROM group_members gm JOIN student_groups g ON g.id=gm.group_id JOIN course_groups cg ON cg.group_id=g.id WHERE gm.user_id=u.id AND cg.course_id=?) AS group_names
        FROM users u WHERE u.role='student' AND u.id IN
        (SELECT user_id FROM enrollments WHERE course_id=? UNION SELECT gm.user_id FROM group_members gm JOIN course_groups cg ON cg.group_id=gm.group_id WHERE cg.course_id=?)" . $filter . ' ORDER BY u.student_code,u.fullname,u.id', $params);
}

function grade_sources(int $courseId): array
{
    $sources = [];
    foreach (db_all("SELECT * FROM lessons WHERE course_id=? AND media_type IN ('quiz','assignment')", [$courseId]) as $l) {
        $l['source_media_id'] = 0; $l['lesson_id'] = (int)$l['id'];
        $sources[$l['media_type'].':'.$l['id'].':0'] = $l;
    }
    if (lesson_media_ready()) foreach (db_all("SELECT m.* FROM lesson_media m JOIN lessons l ON l.id=m.lesson_id WHERE l.course_id=? AND m.media_type IN ('quiz','assignment')", [$courseId]) as $m) {
        $m['source_media_id'] = (int)$m['id'];
        $sources[$m['media_type'].':'.$m['lesson_id'].':'.$m['id']] = $m;
    }
    return $sources;
}

function grade_sync(int $courseId): void
{
    foreach (grade_sources($courseId) as $key => $source) {
        $max = $source['media_type'] === 'quiz' ? quiz_total((string)$source['quiz_data']) : (float)$source['assignment_points'];
        db_exec('INSERT INTO grade_items (course_id,source_key,kind,lesson_id,source_media_id,title,max_score,attempt_policy) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),max_score=VALUES(max_score)',
            [$courseId,$key,$source['media_type'],(int)$source['lesson_id'],(int)$source['source_media_id'],$source['title'],$max,$source['media_type']==='quiz' ? 'highest' : 'latest']);
    }
}

function grade_config(int $courseId): array
{
    $config = db_one('SELECT * FROM course_grade_config WHERE course_id=?', [$courseId]);
    return $config ? ['scale'=>json_decode($config['scale_json'], true), 'version'=>(int)$config['version']] : ['scale'=>grade_default_scale(),'version'=>0];
}

function grade_pick(array $attempts, string $policy, string $kind): array
{
    if (!$attempts) return ['score'=>null,'max'=>null,'state'=>'missing','pending'=>false];
    $latest = $attempts[0];
    $pending = $kind === 'assignment' && $latest['status'] !== 'graded';
    $chosen = $latest;
    if ($policy === 'highest') {
        $chosen = null;
        foreach ($attempts as $attempt) {
            if ($attempt['score'] === null || (float)$attempt['max'] <= 0 || ($kind === 'assignment' && $attempt['status'] !== 'graded')) continue;
            if (!$chosen || (float)$attempt['score']/(float)$attempt['max'] > (float)$chosen['score']/(float)$chosen['max']) $chosen=$attempt;
        }
    }
    return ['score'=>$chosen['score'] ?? null, 'max'=>$chosen['max'] ?? null,'state'=>$pending ? 'pending' : 'scored','pending'=>$pending,'record_id'=>$chosen['id'] ?? $latest['id']];
}

function grade_calculate(array $items, array $cells, array $scale): array
{
    $sum=0.0; $weights=0; $missing=0; $pending=0; $unavailable=0;
    foreach ($items as $item) {
        $weight=(float)$item['weight']; $weights+=(int)round($weight*100);
        if ($weight <= 0) continue;
        $cell=$cells[$item['id']];
        if ($cell['state']==='unavailable') $unavailable++;
        elseif ($cell['pending']) $pending++;
        elseif ($cell['score']===null) $missing++;
        if ($cell['score']!==null && (float)$cell['max'] > 0) $sum += (float)$cell['score']/(float)$cell['max']*$weight;
    }
    $total=round(min(100, max(0,$sum)),2);
    return ['total'=>$total,'grade'=>$weights===10000 ? grade_label($total,$scale) : '—','weight_valid'=>$weights===10000,'missing'=>$missing,'pending'=>$pending,'unavailable'=>$unavailable];
}

function gradebook_data(int $courseId, int $groupId = 0): array
{
    $items=db_all('SELECT * FROM grade_items WHERE course_id=? ORDER BY id',[$courseId]);
    $sources=grade_sources($courseId); $scores=[]; $manual=[];
    if (quiz_ready()) foreach (db_all('SELECT a.id,a.lesson_id,a.source_media_id,a.user_id,a.score,a.total AS max FROM quiz_attempts a JOIN lessons l ON l.id=a.lesson_id WHERE l.course_id=? ORDER BY a.id DESC',[$courseId]) as $a) {
        $a['status']='graded'; $scores['quiz:'.$a['lesson_id'].':'.$a['source_media_id'].':'.$a['user_id']][]=$a;
    }
    if (assignments_ready()) foreach (db_all('SELECT s.id,s.lesson_id,s.source_media_id,s.user_id,s.score,s.max_score AS max,s.status FROM assignment_submissions s JOIN lessons l ON l.id=s.lesson_id WHERE l.course_id=? ORDER BY s.id DESC',[$courseId]) as $s) $scores['assignment:'.$s['lesson_id'].':'.$s['source_media_id'].':'.$s['user_id']][]=$s;
    foreach (db_all('SELECT m.* FROM grade_manual_scores m JOIN grade_items i ON i.id=m.item_id WHERE i.course_id=?',[$courseId]) as $m) $manual[$m['item_id'].':'.$m['user_id']]=$m;
    $config=grade_config($courseId); $rows=[];
    foreach (grade_roster($courseId,$groupId) as $student) {
        $cells=[];
        foreach ($items as $item) {
            if ($item['kind']==='manual') {
                $entry=$manual[$item['id'].':'.$student['id']] ?? null;
                $cell=['score'=>$entry['score'] ?? null,'max'=>$item['max_score'],'state'=>$entry ? 'scored' : 'missing','pending'=>false,'version'=>$entry ? (int)$entry['version'] : -1,'note'=>$entry['note'] ?? ''];
            } elseif (!isset($sources[$item['source_key']])) {
                $cell=['score'=>null,'max'=>null,'state'=>'unavailable','pending'=>false];
            } else $cell=grade_pick($scores[$item['source_key'].':'.$student['id']] ?? [],$item['attempt_policy'],$item['kind']);
            $cells[$item['id']]=$cell;
        }
        $rows[]=['student'=>$student,'cells'=>$cells,'result'=>grade_calculate($items,$cells,$config['scale'])];
    }
    return ['items'=>$items,'rows'=>$rows,'config'=>$config];
}

function gradebook_fingerprint(array $data): string
{
    return hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
}
