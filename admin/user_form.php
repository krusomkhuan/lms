<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$me = require_role('admin');
$id = get_int('id');
$user = $id ? db_one('SELECT * FROM users WHERE id = ?', [$id]) : null;
if ($id && !$user) {
    http_response_code(404);
    exit('ไม่พบสมาชิก');
}
$roles = ['admin', 'teacher', 'student'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = post('username');
    $email = post('email');
    $role = in_array(post('role'), $roles, true) ? post('role') : 'student';
    $status = in_array(post('status'), ['active', 'pending', 'banned'], true) ? post('status') : 'active';
    if ($user && (int)$user['id'] === (int)$me['id']) {
        $role = 'admin';      // ป้องกันลดสิทธิ์/ระงับตัวเอง
        $status = 'active';
    }

    if (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $username)) $errors[] = 'ชื่อผู้ใช้ต้องเป็นภาษาอังกฤษ/ตัวเลข/ _ . 3-50 ตัว';
    if (post('fullname') === '') $errors[] = 'กรุณากรอกชื่อ-นามสกุล';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
    if (!$user && strlen(post('password')) < 6) $errors[] = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
    if ($user && post('password') !== '' && strlen(post('password')) < 6) $errors[] = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร';
    if (db_value('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$username, $id])) $errors[] = 'ชื่อผู้ใช้นี้มีอยู่แล้ว';
    if ($email !== '' && db_value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $id])) $errors[] = 'อีเมลนี้มีอยู่แล้ว';

    if (!$errors) {
        $data = [$username, post('fullname'), $email ?: null, post('phone') ?: null, post('student_code') ?: null, $role, $status];
        if ($user) {
            db_exec('UPDATE users SET username=?, fullname=?, email=?, phone=?, student_code=?, role=?, status=? WHERE id=?', array_merge($data, [$id]));
            if (post('password') !== '') {
                db_exec('UPDATE users SET password=? WHERE id=?', [password_hash(post('password'), PASSWORD_DEFAULT), $id]);
            }
        } else {
            $id = db_exec('INSERT INTO users (username,fullname,email,phone,student_code,role,status,password) VALUES (?,?,?,?,?,?,?,?)',
                array_merge($data, [password_hash(post('password'), PASSWORD_DEFAULT)]));
        }
        // กลุ่มเรียนของผู้เรียน: 1 คน 1 กลุ่ม ("keep" = ไม่เปลี่ยน ใช้เมื่อเดิมอยู่หลายกลุ่มและยังไม่ได้เลือก)
        $groupChoice = post('group_id', 'keep');
        if ($role === 'student' && $groupChoice !== 'keep') {
            db_exec('DELETE FROM group_members WHERE user_id = ?', [$id]);
            if ((int)$groupChoice > 0 && db_value('SELECT 1 FROM student_groups WHERE id = ?', [(int)$groupChoice])) {
                db_exec('INSERT INTO group_members (group_id, user_id) VALUES (?, ?)', [(int)$groupChoice, $id]);
            }
        }
        flash('success', 'บันทึกข้อมูลสมาชิกเรียบร้อย');
        redirect('admin/users.php?role=' . $role);
    }
}

$v = fn($k, $d = '') => $_SERVER['REQUEST_METHOD'] === 'POST' ? post($k, $d) : ($user[$k] ?? $d);
$curRole = $v('role', in_array($_GET['role'] ?? '', $roles, true) ? $_GET['role'] : 'student');
$pageTitle = $user ? 'แก้ไขสมาชิก' : ($curRole === 'teacher' ? 'เพิ่มครูผู้สอน' : 'เพิ่มสมาชิก');
$allGroups = db_all('SELECT id, name FROM student_groups ORDER BY name');
$userGroups = $user ? db_all('SELECT g.id, g.name FROM group_members gm JOIN student_groups g ON g.id = gm.group_id WHERE gm.user_id = ? ORDER BY g.name', [$id]) : [];
$groupValue = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)post('group_id', 'keep') : (count($userGroups) > 1 ? 'keep' : (string)($userGroups[0]['id'] ?? '0'));
require __DIR__ . '/_top.php';
?>
<a href="<?= url('admin/users.php') ?>"><i class="bi bi-arrow-left"></i> สมาชิกทั้งหมด</a>
<?php if ($errors): ?><div class="alert alert-danger mt-3"><ul class="mb-0"><?php foreach ($errors as $er) echo '<li>' . e($er) . '</li>'; ?></ul></div><?php endif; ?>
<form method="post" class="card border-0 shadow-sm rounded-4 mt-3" style="max-width:820px">
    <?= csrf_field() ?>
    <div class="card-body p-4">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">ประเภทสมาชิก</label>
                <select name="role" class="form-select" <?= $user && (int)$user['id'] === (int)$me['id'] ? 'disabled' : '' ?>>
                    <?php foreach ($roles as $r): ?><option value="<?= $r ?>" <?= $curRole === $r ? 'selected' : '' ?>><?= role_label($r) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-md-6"><label class="form-label">สถานะ</label>
                <select name="status" class="form-select">
                    <?php foreach (['active' => 'ใช้งาน', 'pending' => 'รออนุมัติ', 'banned' => 'ระงับ'] as $k => $l): ?><option value="<?= $k ?>" <?= $v('status', 'active') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-md-8"><label class="form-label">ชื่อ-นามสกุล *</label><input name="fullname" class="form-control" required value="<?= e($v('fullname')) ?>"></div>
            <div class="col-md-4"><label class="form-label">รหัสนักศึกษา / รหัสครู</label><input name="student_code" class="form-control" value="<?= e($v('student_code')) ?>"></div>
            <div class="col-md-6"><label class="form-label">อีเมล</label><input name="email" type="email" class="form-control" value="<?= e($v('email')) ?>"></div>
            <div class="col-md-6"><label class="form-label">เบอร์โทร</label><input name="phone" class="form-control" value="<?= e($v('phone')) ?>"></div>
            <div class="col-md-6"><label class="form-label">ชื่อผู้ใช้ *</label><input name="username" class="form-control" required value="<?= e($v('username')) ?>" autocomplete="off"></div>
            <div class="col-md-6"><label class="form-label">รหัสผ่าน <?= $user ? '<span class="small text-muted">(เว้นว่างหากไม่เปลี่ยน)</span>' : '*' ?></label>
                <input name="password" type="password" class="form-control" <?= $user ? '' : 'required' ?> autocomplete="new-password"></div>
            <div class="col-md-6" id="groupField"><label class="form-label" for="groupSelect">กลุ่มเรียน</label>
                <select name="group_id" id="groupSelect" class="form-select">
                    <?php if (count($userGroups) > 1): ?><option value="keep" <?= $groupValue === 'keep' ? 'selected' : '' ?>>— ยังไม่เปลี่ยน (อยู่ <?= count($userGroups) ?> กลุ่ม) —</option><?php endif; ?>
                    <option value="0" <?= $groupValue === '0' ? 'selected' : '' ?>>— ไม่อยู่ในกลุ่ม —</option>
                    <?php foreach ($allGroups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= $groupValue === (string)$g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?>
                </select>
                <?php if (count($userGroups) > 1): ?><div class="form-text text-danger"><i class="bi bi-exclamation-triangle"></i> ตอนนี้อยู่ <?= count($userGroups) ?> กลุ่ม: <?= e(implode(', ', array_column($userGroups, 'name'))) ?> — เลือกกลุ่มที่ถูกต้องแล้วกดบันทึก</div>
                <?php else: ?><div class="form-text">ผู้เรียน 1 คนอยู่ได้ 1 กลุ่ม · เปลี่ยนกลุ่มแล้วสิทธิ์เรียนรายวิชาของกลุ่มจะเปลี่ยนตาม</div><?php endif; ?>
            </div>
        </div>
        <button class="btn btn-vt mt-4 px-4"><i class="bi bi-save"></i> บันทึก</button>
    </div>
</form>
<script>
    // ช่องกลุ่มเรียนแสดงเฉพาะประเภท "ผู้เรียน"
    (function () {
        var role = document.querySelector('[name=role]'), box = document.getElementById('groupField');
        if (!role || !box) return;
        var sync = function () { box.hidden = role.value !== 'student'; };
        role.addEventListener('change', sync); sync();
    })();
</script>

<?php require __DIR__ . '/_bottom.php'; ?>
