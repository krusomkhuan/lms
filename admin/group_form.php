<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$me = require_role('admin', 'teacher');
$isAdmin = $me['role'] === 'admin';
$id = get_int('id');
$group = $id ? db_one('SELECT * FROM student_groups WHERE id = ?', [$id]) : null;
if ($id && !$group) {
    http_response_code(404);
    exit('ไม่พบกลุ่ม');
}
$canEdit = !$group || can_manage_group($group);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canEdit) {
        http_response_code(403);
        exit('ไม่มีสิทธิ์แก้ไขกลุ่มนี้');
    }
    $action = post('action');
    if ($action === 'save') {
        if (post('name') === '') {
            flash('danger', 'กรุณากรอกชื่อกลุ่ม');
            redirect('admin/group_form.php' . ($id ? '?id=' . $id : ''));
        }
        $teacherIds = array_map('intval', (array)post('teachers', []));
        // ครูที่แก้ไขต้องยังอยู่ในรายชื่อผู้สอน (ป้องกันตัดสิทธิ์ตัวเองโดยไม่ตั้งใจ)
        if (!$isAdmin && !in_array((int)$me['id'], $teacherIds, true)) $teacherIds[] = (int)$me['id'];
        if ($group) {
            db_exec('UPDATE student_groups SET name=?, level=?, description=? WHERE id=?', [post('name'), post('level') ?: null, post('description') ?: null, $id]);
        } else {
            $id = db_exec('INSERT INTO student_groups (name, level, description) VALUES (?,?,?)', [post('name'), post('level') ?: null, post('description') ?: null]);
        }
        db_exec('DELETE FROM group_teachers WHERE group_id = ?', [$id]);
        foreach (array_unique($teacherIds) as $t) {
            db_exec("INSERT IGNORE INTO group_teachers (group_id, teacher_id) SELECT ?, id FROM users WHERE id = ? AND role IN ('teacher','admin')", [$id, $t]);
        }
        flash('success', 'บันทึกกลุ่มเรียบร้อย');
    } elseif ($group && $action === 'add') {
        $n = 0;
        foreach ((array)post('user_ids', []) as $u) {
            $n += db_exec("INSERT IGNORE INTO group_members (group_id, user_id) SELECT ?, id FROM users WHERE id = ? AND role = 'student'", [$id, (int)$u]);
        }
        // เพิ่มด้วยรหัสนักศึกษา (บรรทัดละ 1 รหัส หรือคั่นด้วย , )
        foreach (preg_split('/[\s,]+/', (string)post('codes'), -1, PREG_SPLIT_NO_EMPTY) as $code) {
            $n += db_exec("INSERT IGNORE INTO group_members (group_id, user_id) SELECT ?, id FROM users WHERE (student_code = ? OR username = ?) AND role = 'student'", [$id, $code, $code]);
        }
        flash('success', "เพิ่มสมาชิก $n คน");
    } elseif ($group && $action === 'remove') {
        db_exec('DELETE FROM group_members WHERE group_id = ? AND user_id = ?', [$id, (int)post('user_id')]);
        flash('info', 'นำสมาชิกออกจากกลุ่มแล้ว');
    }
    redirect('admin/group_form.php?id=' . $id);
}

$teachers = db_all("SELECT id, fullname, username FROM users WHERE role IN ('teacher','admin') AND status = 'active' ORDER BY role DESC, fullname");
$selTeachers = $group
    ? array_map('intval', array_column(db_all('SELECT teacher_id FROM group_teachers WHERE group_id = ?', [$id]), 'teacher_id'))
    : ($isAdmin ? [] : [(int)$me['id']]);
$members = $group ? db_all('SELECT u.* FROM group_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id = ? ORDER BY u.student_code, u.fullname', [$id]) : [];
$candidates = $group ? db_all("SELECT id, fullname, student_code FROM users WHERE role = 'student' AND status = 'active'
                AND id NOT IN (SELECT user_id FROM group_members WHERE group_id = ?) ORDER BY student_code, fullname", [$id]) : [];

$pageTitle = $group ? 'กลุ่ม: ' . $group['name'] : 'สร้างกลุ่มผู้เรียน';
require __DIR__ . '/_top.php';
?>
<a href="<?= url('admin/groups.php') ?>"><i class="bi bi-arrow-left"></i> กลุ่มทั้งหมด</a>
<div class="row g-4 mt-1">
    <div class="col-lg-4">
        <form method="post" class="card border-0 shadow-sm rounded-4">
            <?= csrf_field() ?><input type="hidden" name="action" value="save">
            <div class="card-header bg-white"><b>ข้อมูลกลุ่ม</b></div>
            <div class="card-body">
                <fieldset <?= $canEdit ? '' : 'disabled' ?>>
                    <div class="mb-2"><label class="form-label">ชื่อกลุ่ม *</label><input name="name" class="form-control" required value="<?= e($group['name'] ?? '') ?>" placeholder="เช่น ปวช.1/1 ช่างไฟฟ้า"></div>
                    <div class="mb-2"><label class="form-label">ระดับชั้น</label><input name="level" class="form-control" value="<?= e($group['level'] ?? '') ?>" placeholder="ปวช.1 / ปวส.2"></div>
                    <div class="mb-2"><label class="form-label">ครูผู้สอน <span class="small text-muted">(เลือกได้หลายคน)</span></label>
                        <div class="border rounded p-2" style="max-height:220px;overflow-y:auto">
                            <?php foreach ($teachers as $t): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="teachers[]" value="<?= $t['id'] ?>" id="gt<?= $t['id'] ?>" <?= in_array((int)$t['id'], $selTeachers, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="gt<?= $t['id'] ?>"><?= e($t['fullname']) ?> <span class="text-muted small">(<?= e($t['username']) ?>)</span></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mb-3"><label class="form-label">รายละเอียด</label><textarea name="description" class="form-control" rows="2"><?= e($group['description'] ?? '') ?></textarea></div>
                    <button class="btn btn-vt w-100"><i class="bi bi-save"></i> บันทึก</button>
                </fieldset>
            </div>
        </form>

        <?php if ($group && $canEdit): ?>
            <form method="post" class="card border-0 shadow-sm rounded-4 mt-4">
                <?= csrf_field() ?><input type="hidden" name="action" value="add">
                <div class="card-header bg-white"><b>เพิ่มสมาชิก</b></div>
                <div class="card-body">
                    <select name="user_ids[]" class="form-select" multiple size="8">
                        <?php foreach ($candidates as $c): ?><option value="<?= $c['id'] ?>"><?= e(($c['student_code'] ? $c['student_code'] . ' ' : '') . $c['fullname']) ?></option><?php endforeach; ?>
                    </select>
                    <div class="form-text mb-2">กด Ctrl / Shift ค้างเพื่อเลือกหลายคน</div>
                    <label class="form-label small">หรือวางรหัสนักศึกษา / ชื่อผู้ใช้ (หลายรายการ)</label>
                    <textarea name="codes" class="form-control" rows="3" placeholder="66201001&#10;66201002"></textarea>
                    <button class="btn btn-vt w-100 mt-2"><i class="bi bi-person-plus"></i> เพิ่มเข้ากลุ่ม</button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($group): ?>
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white"><b>สมาชิกในกลุ่ม <?= count($members) ?> คน</b></div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>#</th><th>รหัส</th><th>ชื่อ-นามสกุล</th><th>ชื่อผู้ใช้</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($members as $i => $m): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= e($m['student_code'] ?: '-') ?></td>
                                <td><?= e($m['fullname']) ?></td>
                                <td class="small text-muted"><?= e($m['username']) ?></td>
                                <td class="text-end">
                                    <?php if ($canEdit): ?>
                                        <form method="post" data-confirm="นำออกจากกลุ่ม?"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="user_id" value="<?= $m['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button></form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$members): ?><tr><td colspan="5" class="text-center text-muted py-4">ยังไม่มีสมาชิก</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
