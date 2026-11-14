<?php
// หลังบ้าน: สร้าง/ตั้งค่ากระดานกิจกรรม (เทมเพลต Active Learning, เวลา, การมองเห็น, ใบงาน/คะแนน, คอลัมน์, ทีม, บทบาทรายคน)
require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_role('admin', 'teacher');
if (!boards_ready()) { flash('warning', 'กรุณารัน database/migrations/1.17.0.sql ก่อนใช้กระดานกิจกรรม'); redirect('admin/boards.php'); }
$board = get_int('id') ? db_one('SELECT * FROM boards WHERE id = ?', [get_int('id')]) : null;
if (get_int('id') && !$board) { http_response_code(404); exit('ไม่พบกระดาน'); }
$courseId = $board ? (int)$board['course_id'] : get_int('course_id');
$course = db_one('SELECT * FROM courses WHERE id = ?', [$courseId]);
if (!$course || !can_manage_course($courseId)) { http_response_code(403); exit('ไม่มีสิทธิ์จัดการรายวิชานี้'); }
$templates = board_templates();
$templateKey = $board ? $board['template'] : (is_string($_GET['template'] ?? null) && isset($templates[$_GET['template']]) ? $_GET['template'] : '');
$template = $templates[$templateKey] ?? $templates[''];
$defaults = ['title' => '', 'description' => '', 'lesson_id' => get_int('lesson_id') ?: null, 'layout' => 'wall', 'status' => 'open', 'opens_at' => null, 'due_at' => null, 'closes_at' => null, 'accept_late' => 1,
    'require_approval' => 0, 'hide_until_reveal' => 0, 'allow_comments' => 1, 'reaction_mode' => 'like', 'student_role' => 'contributor', 'group_mode' => 'none', 'team_visibility' => 'all',
    'is_assignment' => 0, 'max_points' => '10.00', 'one_answer' => 0];
$values = $board ?: array_merge($defaults, $template['set'], ['title' => $templateKey ? $template['label'] . ' — ' : '']);
$sections = $board ? array_column(db_all('SELECT title FROM board_sections WHERE board_id = ? ORDER BY sort_order, id', [(int)$board['id']]), 'title') : ($template['sections'] ?? []);
$lessons = db_all('SELECT id, title FROM lessons WHERE course_id = ? ORDER BY sort_order, id', [$courseId]);
$error = '';
$self = $board ? 'admin/board_form.php?id=' . $board['id'] : 'admin/board_form.php?course_id=' . $courseId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action', 'save');
    try {
        if ($action === 'save') {
            $title = trim((string)post('title'));
            if ($title === '' || mb_strlen($title) > 200) throw new RuntimeException('กรุณากรอกชื่อกระดานไม่เกิน 200 ตัวอักษร');
            $pick = fn(string $key, array $allowed) => in_array(post($key), $allowed, true) ? post($key) : $allowed[0];
            [$opens, $closes] = schedule_range(post('opens_at'), post('closes_at'));
            $due = schedule_datetime(post('due_at'));
            if ($due && $opens && $due <= $opens) throw new RuntimeException('กำหนดส่งต้องอยู่หลังเวลาเปิดรับ');
            if ($due && $closes && $due > $closes) throw new RuntimeException('กำหนดส่งต้องไม่เกินเวลาปิดรับ');
            $lessonId = (int)post('lesson_id') ?: null;
            if ($lessonId && !in_array($lessonId, array_map('intval', array_column($lessons, 'id')), true)) $lessonId = null;
            $points = post('is_assignment') ? assignment_points(post('max_points', '10')) : (string)($values['max_points'] ?? '10.00');
            $data = [
                'title' => $title, 'description' => (string)post('description'), 'lesson_id' => $lessonId, 'layout' => $pick('layout', array_keys(BOARD_LAYOUTS)), 'template' => $templateKey,
                'status' => $pick('status', ['open', 'closed', 'archived']), 'opens_at' => $opens, 'due_at' => $due, 'closes_at' => $closes, 'accept_late' => post('accept_late') ? 1 : 0,
                'require_approval' => post('require_approval') ? 1 : 0, 'hide_until_reveal' => post('hide_until_reveal') ? 1 : 0, 'allow_comments' => post('allow_comments') ? 1 : 0,
                'reaction_mode' => $pick('reaction_mode', ['like', 'vote', 'star', 'none']), 'student_role' => $pick('student_role', ['contributor', 'commenter', 'viewer']),
                'group_mode' => $pick('group_mode', ['none', 'teams']), 'team_visibility' => $pick('team_visibility', ['all', 'own']),
                'is_assignment' => post('is_assignment') ? 1 : 0, 'max_points' => $points, 'one_answer' => post('one_answer') ? 1 : 0,
            ];
            if (mb_strlen($data['description']) > 200000) throw new RuntimeException('คำอธิบายยาวเกินไป');
            $lines = array_values(array_filter(array_map(fn($l) => mb_substr(trim($l), 0, 100), preg_split('/\R/', (string)post('sections'))), 'strlen'));
            if (count($lines) > 20) throw new RuntimeException('คอลัมน์/หัวข้อได้ไม่เกิน 20 รายการ');
            $conn->begin_transaction();
            if ($board) {
                db_exec('UPDATE boards SET ' . implode(',', array_map(fn($k) => "$k = ?", array_keys($data))) . ', rev = rev + 1 WHERE id = ?', array_merge(array_values($data), [(int)$board['id']]));
                $boardId = (int)$board['id'];
            } else {
                $boardId = db_exec('INSERT INTO boards (' . implode(',', array_keys($data)) . ',course_id,created_by) VALUES (' . str_repeat('?,', count($data)) . '?,?)', array_merge(array_values($data), [$courseId, (int)$me['id']]));
            }
            // คอลัมน์: จับคู่ตามลำดับ (เปลี่ยนชื่อได้โดยโพสต์ยังอยู่ในคอลัมน์เดิม)
            $existing = array_map('intval', array_column(db_all('SELECT id FROM board_sections WHERE board_id = ? ORDER BY sort_order, id', [$boardId]), 'id'));
            foreach ($lines as $i => $line) {
                if (isset($existing[$i])) db_exec('UPDATE board_sections SET title = ?, sort_order = ? WHERE id = ?', [$line, $i, $existing[$i]]);
                else db_exec('INSERT INTO board_sections (board_id,title,sort_order) VALUES (?,?,?)', [$boardId, $line, $i]);
            }
            foreach (array_slice($existing, count($lines)) as $gone) db_exec('DELETE FROM board_sections WHERE id = ?', [$gone]);
            if ($data['is_assignment']) grade_sync($courseId);
            else db_exec("DELETE FROM grade_items WHERE course_id = ? AND source_key = ?", [$courseId, 'board:' . $boardId . ':0']);
            $conn->commit();
            flash('success', $board ? 'บันทึกการตั้งค่ากระดานแล้ว' : 'สร้างกระดานแล้ว' . ($data['group_mode'] === 'teams' ? ' — จัดทีมได้ด้านล่าง' : ''));
            redirect($board || $data['group_mode'] === 'teams' ? 'admin/board_form.php?id=' . $boardId . ($data['group_mode'] === 'teams' ? '#teams' : '') : 'admin/boards.php?course_id=' . $courseId);
        }
        if (!$board) throw new RuntimeException('กรุณาบันทึกกระดานก่อน');
        $boardId = (int)$board['id'];
        $roster = grade_roster($courseId);
        $rosterIds = array_map('intval', array_column($roster, 'id'));
        if ($action === 'teams_auto') {
            $n = max(2, min(30, (int)post('team_count')));
            $conn->begin_transaction();
            db_exec('DELETE FROM board_teams WHERE board_id = ?', [$boardId]);
            $teamIds = [];
            for ($i = 1; $i <= $n; $i++) $teamIds[] = db_exec('INSERT INTO board_teams (board_id,name,sort_order) VALUES (?,?,?)', [$boardId, 'ทีม ' . $i, $i]);
            $shuffled = $rosterIds;
            shuffle($shuffled);
            foreach ($shuffled as $i => $uid) db_exec('INSERT INTO board_team_members (board_id,user_id,team_id) VALUES (?,?,?)', [$boardId, $uid, $teamIds[$i % $n]]);
            db_exec('UPDATE boards SET group_mode = \'teams\', rev = rev + 1 WHERE id = ?', [$boardId]);
            $conn->commit();
            flash('success', 'สุ่มแบ่ง ' . count($shuffled) . ' คน เป็น ' . $n . ' ทีมแล้ว');
        } elseif ($action === 'teams_groups') {
            $conn->begin_transaction();
            db_exec('DELETE FROM board_teams WHERE board_id = ?', [$boardId]);
            foreach (grade_groups($courseId) as $i => $g) {
                $teamId = db_exec('INSERT INTO board_teams (board_id,name,sort_order) VALUES (?,?,?)', [$boardId, $g['name'], $i]);
                foreach (grade_roster($courseId, (int)$g['id']) as $s) db_exec('INSERT IGNORE INTO board_team_members (board_id,user_id,team_id) VALUES (?,?,?)', [$boardId, (int)$s['id'], $teamId]);
            }
            db_exec('UPDATE boards SET group_mode = \'teams\', rev = rev + 1 WHERE id = ?', [$boardId]);
            $conn->commit();
            flash('success', 'สร้างทีมตามกลุ่มผู้เรียนของรายวิชาแล้ว');
        } elseif ($action === 'team_add') {
            $name = mb_substr(trim((string)post('team_name')), 0, 100);
            if ($name === '') throw new RuntimeException('กรุณาตั้งชื่อทีม');
            db_exec('INSERT INTO board_teams (board_id,name,sort_order) VALUES (?,?,?)', [$boardId, $name, (int)db_value('SELECT COALESCE(MAX(sort_order),0)+1 FROM board_teams WHERE board_id = ?', [$boardId])]);
            board_bump($boardId);
            flash('success', 'เพิ่มทีมแล้ว');
        } elseif ($action === 'team_rename' || $action === 'team_delete') {
            $team = db_one('SELECT * FROM board_teams WHERE id = ? AND board_id = ?', [(int)post('team_id'), $boardId]);
            if (!$team) throw new RuntimeException('ไม่พบทีม');
            if ($action === 'team_delete') db_exec('DELETE FROM board_teams WHERE id = ?', [(int)$team['id']]);
            else db_exec('UPDATE board_teams SET name = ? WHERE id = ?', [mb_substr(trim((string)post('team_name')), 0, 100) ?: $team['name'], (int)$team['id']]);
            board_bump($boardId);
            flash('success', $action === 'team_delete' ? 'ลบทีมแล้ว (โพสต์ยังอยู่ แต่ไม่ผูกทีม)' : 'เปลี่ยนชื่อทีมแล้ว');
        } elseif ($action === 'roster_save') {
            $teams = array_map('intval', array_column(db_all('SELECT id FROM board_teams WHERE board_id = ?', [$boardId]), 'id'));
            $teamOf = (array)post('team', []);
            $roleOf = (array)post('role', []);
            $conn->begin_transaction();
            foreach ($rosterIds as $uid) {
                $team = (int)($teamOf[$uid] ?? 0);
                db_exec('DELETE FROM board_team_members WHERE board_id = ? AND user_id = ?', [$boardId, $uid]);
                if (in_array($team, $teams, true)) {
                    db_exec('INSERT INTO board_team_members (board_id,user_id,team_id) VALUES (?,?,?)', [$boardId, $uid, $team]);
                    // โพสต์เดิมย้ายตามทีมใหม่ เพื่อให้แยกพื้นที่ทีมถูกต้อง
                    db_exec('UPDATE board_posts SET team_id = ? WHERE board_id = ? AND user_id = ?', [$team, $boardId, $uid]);
                }
                $role = (string)($roleOf[$uid] ?? '');
                db_exec('DELETE FROM board_members WHERE board_id = ? AND user_id = ?', [$boardId, $uid]);
                if (isset(BOARD_ROLES[$role]) && $role !== 'manager') db_exec('INSERT INTO board_members (board_id,user_id,role) VALUES (?,?,?)', [$boardId, $uid, $role]);
            }
            board_bump($boardId);
            $conn->commit();
            flash('success', 'บันทึกทีมและบทบาทแล้ว');
        }
        redirect($self . '#teams');
    } catch (RuntimeException $ex) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        $error = $ex->getMessage();
        if ($action === 'save') {
            $values = array_merge($values, $_POST, ['lesson_id' => (int)post('lesson_id') ?: null]);
            foreach (['accept_late', 'require_approval', 'hide_until_reveal', 'allow_comments', 'is_assignment', 'one_answer'] as $box) $values[$box] = empty($_POST[$box]) ? 0 : 1;
            foreach (['opens_at', 'due_at', 'closes_at'] as $at) $values[$at] = is_string($_POST[$at] ?? null) ? str_replace('T', ' ', $_POST[$at]) : null;
            $sections = preg_split('/\R/', (string)post('sections'));
        }
    }
}
$v = fn($k) => $values[$k] ?? '';
$checked = fn($k) => !empty($values[$k]) ? 'checked' : '';
$pageTitle = ($board ? 'ตั้งค่ากระดาน' : 'สร้างกระดานกิจกรรม') . ' — ' . $course['code'];
require __DIR__ . '/_top.php';
?>
<a href="<?= url('admin/boards.php?course_id=' . $courseId) ?>"><i class="bi bi-arrow-left"></i> กระดานของวิชา <?= e($course['title']) ?></a>
<?php if ($board): ?> · <a href="<?= url('board.php?id=' . $board['id']) ?>" target="_blank">เปิดกระดาน</a> · <a href="<?= url('admin/board_report.php?id=' . $board['id']) ?>">ตรวจคำตอบ/รายงาน</a><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger mt-3"><?= e($error) ?></div><?php endif; ?>

<?php if (!$board): ?>
    <h2 class="h6 mt-3">1. เลือกกิจกรรม (Active Learning) <a class="small fw-normal ms-2" href="<?= url('manual.php?role=boards') ?>" target="_blank" rel="noopener"><i class="bi bi-book"></i> คู่มือกิจกรรมทั้งหมด</a></h2>
    <div class="row g-2 mb-3">
        <?php foreach ($templates as $key => $t): ?>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="card h-100 border-2 <?= $key === $templateKey ? 'border-primary' : 'border-light' ?> shadow-sm">
                    <a class="card-body p-2 text-decoration-none text-body" href="<?= url('admin/board_form.php?course_id=' . $courseId . ($key !== '' ? '&template=' . $key : '') . (get_int('lesson_id') ? '&lesson_id=' . get_int('lesson_id') : '')) ?>">
                        <div class="fw-semibold"><i class="bi <?= $t['icon'] ?>"></i> <?= e($t['label']) ?></div><div class="small text-muted"><?= e($t['hint']) ?></div></a>
                    <a class="small px-2 pb-2" href="<?= url('manual.php?role=boards#board-' . ($key !== '' ? $key : 'blank')) ?>" target="_blank" rel="noopener"><i class="bi bi-book"></i> วิธีใช้</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <h2 class="h6">2. ตั้งค่ากระดาน</h2>
<?php endif; ?>

<form method="post" class="mt-3">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4"><div class="card-body">
                <label class="form-label w-100">ชื่อกระดาน *<input name="title" class="form-control form-control-lg mt-1" required maxlength="200" value="<?= e($v('title')) ?>" placeholder="เช่น ใบงานที่ 1 ออกแบบโลโก้ร้านค้า"></label>
                <label class="form-label w-100" for="boardDescription">คำถาม / คำสั่งงาน / คำอธิบาย (แสดงด้านบนกระดาน)</label>
                <textarea name="description" id="boardDescription" rows="6" class="form-control"><?= e($v('description')) ?></textarea>
                <h3 class="h6 mt-4">รูปแบบการแสดงผล</h3>
                <div class="row g-2">
                    <?php foreach (BOARD_LAYOUTS as $key => [$label, $icon, $hint]): ?>
                        <div class="col-6 col-md-4">
                            <input type="radio" class="btn-check" name="layout" id="lay-<?= $key ?>" value="<?= $key ?>" <?= $v('layout') === $key ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary w-100 text-start" for="lay-<?= $key ?>"><i class="bi <?= $icon ?>"></i> <b><?= e($label) ?></b><small class="d-block"><?= e($hint) ?></small></label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="form-text">ผู้เรียนสลับมุมมองได้เอง แต่ครั้งแรกจะเห็นตามที่ครูเลือก · Word Cloud เหมาะกับคำถามที่ตอบสั้น ๆ</p>
                <label class="form-label w-100 mt-2">คอลัมน์ / หัวข้อ (บรรทัดละ 1 หัวข้อ ใช้กับรูปแบบ Columns)
                    <textarea name="sections" rows="3" class="form-control mt-1" placeholder="เช่น&#10;K – สิ่งที่รู้แล้ว&#10;W – สิ่งที่อยากรู้&#10;L – สิ่งที่ได้เรียนรู้"><?= e(implode("\n", $sections)) ?></textarea></label>
            </div></div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 mb-3"><div class="card-body">
                <label class="form-label w-100">บทเรียนที่เชื่อม (แสดงปุ่มเปิดกระดานในบทเรียน)
                    <select name="lesson_id" class="form-select mt-1"><option value="">— ไม่ผูกบทเรียน —</option>
                        <?php foreach ($lessons as $l): ?><option value="<?= (int)$l['id'] ?>" <?= (int)$v('lesson_id') === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['title']) ?></option><?php endforeach; ?>
                    </select></label>
                <label class="form-label w-100">สถานะ
                    <select name="status" class="form-select mt-1"><?php foreach (['open' => 'เปิดรับคำตอบ', 'closed' => 'ปิดรับ (ดูได้อย่างเดียว)', 'archived' => 'เก็บถาวร (ซ่อนจากนักเรียน)'] as $k => $t): ?><option value="<?= $k ?>" <?= $v('status') === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
                <label class="form-label w-100">เปิดรับตั้งแต่<input type="datetime-local" name="opens_at" class="form-control" value="<?= e(schedule_input_value($v('opens_at'))) ?>"></label>
                <label class="form-label w-100">กำหนดส่ง<input type="datetime-local" name="due_at" class="form-control" value="<?= e(schedule_input_value($v('due_at'))) ?>"></label>
                <label class="form-check"><input type="checkbox" class="form-check-input" name="accept_late" value="1" <?= $checked('accept_late') ?>> รับหลังกำหนดส่ง (ติดสถานะส่งเกินเวลา)</label>
                <label class="form-label w-100 mt-2">ปิดรับอัตโนมัติ<input type="datetime-local" name="closes_at" class="form-control" value="<?= e(schedule_input_value($v('closes_at'))) ?>"></label>
                <p class="form-text mb-0">เวลาประเทศไทย เว้นว่างเพื่อไม่จำกัด</p>
            </div></div>
            <div class="card border-0 shadow-sm rounded-4 mb-3"><div class="card-body">
                <h3 class="h6">ใบงาน / คะแนน</h3>
                <label class="form-check form-switch"><input type="checkbox" class="form-check-input" name="is_assignment" value="1" <?= $checked('is_assignment') ?>> เป็นใบงาน: ตรวจการส่ง ให้คะแนน และส่งเข้าสมุดคะแนน</label>
                <label class="form-label w-100 mt-2">คะแนนเต็ม<input type="number" name="max_points" min="0.01" max="10000" step="0.01" class="form-control" value="<?= e($v('max_points')) ?>"></label>
                <label class="form-check"><input type="checkbox" class="form-check-input" name="one_answer" value="1" <?= $checked('one_answer') ?>> 1 คน 1 คำตอบ (ส่งซ้ำ = แก้คำตอบเดิม)</label>
            </div></div>
            <div class="card border-0 shadow-sm rounded-4 mb-3"><div class="card-body">
                <h3 class="h6">การมองเห็นและการมีส่วนร่วม</h3>
                <label class="form-check"><input type="checkbox" class="form-check-input" name="hide_until_reveal" value="1" <?= $checked('hide_until_reveal') ?>> ซ่อนคำตอบของเพื่อนจนกว่าครูเปิดเฉลย (กันลอก)</label>
                <label class="form-check"><input type="checkbox" class="form-check-input" name="require_approval" value="1" <?= $checked('require_approval') ?>> ครูอนุมัติก่อนเผยแพร่</label>
                <label class="form-check"><input type="checkbox" class="form-check-input" name="allow_comments" value="1" <?= $checked('allow_comments') ?>> เปิดให้แสดงความคิดเห็น</label>
                <label class="form-label w-100 mt-2">การตอบสนองต่อโพสต์
                    <select name="reaction_mode" class="form-select mt-1"><?php foreach (['like' => 'ถูกใจ (หัวใจ)', 'vote' => 'โหวต', 'star' => 'ให้ดาว 1–5', 'none' => 'ปิด'] as $k => $t): ?><option value="<?= $k ?>" <?= $v('reaction_mode') === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
                <label class="form-label w-100">สิทธิ์เริ่มต้นของผู้เรียน
                    <select name="student_role" class="form-select mt-1"><?php foreach (['contributor' => 'โพสต์และแสดงความคิดเห็นได้', 'commenter' => 'แสดงความคิดเห็นได้อย่างเดียว', 'viewer' => 'ผู้ชม (ดูอย่างเดียว)'] as $k => $t): ?><option value="<?= $k ?>" <?= $v('student_role') === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
                <label class="form-label w-100">การแบ่งกลุ่ม
                    <select name="group_mode" class="form-select mt-1"><option value="none" <?= $v('group_mode') === 'none' ? 'selected' : '' ?>>ไม่แบ่งทีม</option><option value="teams" <?= $v('group_mode') === 'teams' ? 'selected' : '' ?>>แบ่งทีมในกระดานนี้</option></select></label>
                <label class="form-label w-100">ทีมเห็นโพสต์ของทีมอื่น
                    <select name="team_visibility" class="form-select mt-1"><option value="all" <?= $v('team_visibility') === 'all' ? 'selected' : '' ?>>เห็นทุกทีม</option><option value="own" <?= $v('team_visibility') === 'own' ? 'selected' : '' ?>>เห็นเฉพาะทีมตัวเอง (แยกพื้นที่ทำงาน)</option></select></label>
            </div></div>
            <button class="btn btn-vt btn-lg w-100"><i class="bi bi-save"></i> <?= $board ? 'บันทึก' : 'สร้างกระดาน' ?></button>
        </div>
    </div>
</form>

<?php if ($board):
    $teams = db_all('SELECT t.*, (SELECT COUNT(*) FROM board_team_members m WHERE m.team_id = t.id) AS n FROM board_teams t WHERE t.board_id = ? ORDER BY t.sort_order, t.id', [(int)$board['id']]);
    $teamOf = array_column(db_all('SELECT user_id, team_id FROM board_team_members WHERE board_id = ?', [(int)$board['id']]), 'team_id', 'user_id');
    $roleOf = array_column(db_all('SELECT user_id, role FROM board_members WHERE board_id = ?', [(int)$board['id']]), 'role', 'user_id');
    $roster = grade_roster($courseId); ?>
    <section class="card border-0 shadow-sm rounded-4 mt-4" id="teams"><div class="card-body">
        <h2 class="h5">ทีมและสิทธิ์รายคน</h2>
        <p class="small text-muted">ทีมใช้แยกพื้นที่ทำงานในกระดานเดียว (ตั้ง “ทีมเห็นเฉพาะทีมตัวเอง” เพื่อแยกพื้นที่) · บทบาทรายคน เช่น ให้หัวหน้าห้องเป็นผู้ดูแลกระดาน (อนุมัติ/ลบโพสต์ได้ แต่ให้คะแนนไม่ได้)</p>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <form method="post" class="d-flex gap-1" data-confirm="สุ่มแบ่งทีมใหม่ ทีมเดิมจะถูกแทนที่?"><?= csrf_field() ?><input type="hidden" name="action" value="teams_auto">
                <input type="number" name="team_count" min="2" max="30" value="<?= max(2, count($teams) ?: 5) ?>" class="form-control form-control-sm" style="width:5rem" aria-label="จำนวนทีม">
                <button class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-shuffle"></i> สุ่มแบ่งทีม</button></form>
            <form method="post" data-confirm="สร้างทีมตามกลุ่มผู้เรียน (ห้อง) ทีมเดิมจะถูกแทนที่?"><?= csrf_field() ?><input type="hidden" name="action" value="teams_groups"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-people"></i> ทีมตามกลุ่มผู้เรียน</button></form>
            <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="team_add"><input name="team_name" class="form-control form-control-sm" maxlength="100" placeholder="ชื่อทีมใหม่" aria-label="ชื่อทีมใหม่"><button class="btn btn-sm btn-outline-secondary text-nowrap">เพิ่มทีม</button></form>
        </div>
        <?php if ($teams): ?>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <?php foreach ($teams as $t): ?>
                    <form method="post" class="d-flex gap-1 align-items-center border rounded-3 px-2 py-1"><?= csrf_field() ?><input type="hidden" name="team_id" value="<?= (int)$t['id'] ?>">
                        <input name="team_name" value="<?= e($t['name']) ?>" class="form-control form-control-sm border-0" style="width:9rem" maxlength="100" aria-label="ชื่อทีม">
                        <span class="badge bg-secondary"><?= (int)$t['n'] ?></span>
                        <button class="btn btn-sm btn-link p-0" name="action" value="team_rename" title="บันทึกชื่อ"><i class="bi bi-check2"></i></button>
                        <button class="btn btn-sm btn-link text-danger p-0" name="action" value="team_delete" title="ลบทีม" onclick="return confirm('ลบทีมนี้?')"><i class="bi bi-trash"></i></button></form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="roster_save">
            <div class="table-responsive" style="max-height:28rem"><table class="table table-sm align-middle">
                <thead class="table-light sticky-top"><tr><th>รหัส</th><th>ชื่อ</th><th>ทีม</th><th>บทบาทในกระดาน</th></tr></thead>
                <tbody>
                <?php foreach ($roster as $s): $uid = (int)$s['id']; ?>
                    <tr><td class="small"><?= e($s['student_code'] ?: '-') ?></td><td><?= e($s['fullname']) ?></td>
                        <td><select name="team[<?= $uid ?>]" class="form-select form-select-sm" aria-label="ทีมของ <?= e($s['fullname']) ?>"><option value="">— ไม่มีทีม —</option>
                            <?php foreach ($teams as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)($teamOf[$uid] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></td>
                        <td><select name="role[<?= $uid ?>]" class="form-select form-select-sm" aria-label="บทบาทของ <?= e($s['fullname']) ?>"><option value="">ค่าเริ่มต้นของกระดาน</option>
                            <?php foreach (['moderator', 'contributor', 'commenter', 'viewer'] as $r): ?><option value="<?= $r ?>" <?= ($roleOf[$uid] ?? '') === $r ? 'selected' : '' ?>><?= e(BOARD_ROLES[$r]) ?></option><?php endforeach; ?></select></td></tr>
                <?php endforeach; ?>
                <?php if (!$roster): ?><tr><td colspan="4" class="text-muted text-center py-3">ยังไม่มีผู้เรียนในรายวิชา</td></tr><?php endif; ?>
                </tbody>
            </table></div>
            <?php if ($roster): ?><button class="btn btn-vt"><i class="bi bi-save"></i> บันทึกทีมและบทบาท</button><?php endif; ?>
        </form>
    </div></section>
<?php endif; ?>
<?php $editorSelector = '#boardDescription'; require __DIR__ . '/_editor.php'; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
