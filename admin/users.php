<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$me = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $uid = (int)post('id');
    $back = 'admin/users.php?' . http_build_query(array_filter(['role' => post('back_role'), 'status' => post('back_status')]));
    if ($uid === (int)$me['id']) {
        flash('warning', 'ไม่สามารถดำเนินการกับบัญชีของตนเองได้');
        redirect($back);
    }
    if (post('action') === 'delete') {
        $u = db_one('SELECT avatar FROM users WHERE id = ?', [$uid]);
        delete_upload($u['avatar'] ?? null);
        $workFiles = assignments_ready() ? db_all('SELECT file_path FROM assignment_submissions WHERE user_id = ?', [$uid]) : [];
        db_exec('DELETE FROM users WHERE id = ?', [$uid]);
        foreach ($workFiles as $file) delete_upload($file['file_path']);
        flash('success', 'ลบสมาชิกเรียบร้อย');
    } elseif (in_array(post('action'), ['active', 'banned'], true)) {
        db_exec('UPDATE users SET status = ? WHERE id = ?', [post('action'), $uid]);
        flash('success', post('action') === 'active' ? 'เปิดใช้งาน/อนุมัติบัญชีแล้ว' : 'ระงับบัญชีแล้ว');
    }
    redirect($back);
}

$role = in_array($_GET['role'] ?? '', ['admin', 'teacher', 'student'], true) ? $_GET['role'] : '';
$status = in_array($_GET['status'] ?? '', ['active', 'pending', 'banned'], true) ? $_GET['status'] : '';
$q = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if ($role) { $where[] = 'role = ?'; $params[] = $role; }
if ($status) { $where[] = 'status = ?'; $params[] = $status; }
if ($q !== '') {
    $where[] = '(fullname LIKE ? OR username LIKE ? OR email LIKE ? OR student_code LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
$users = db_all('SELECT u.*,
        (SELECT COUNT(*) FROM course_teachers ct WHERE ct.teacher_id = u.id) AS teach_count,
        (SELECT GROUP_CONCAT(g.name SEPARATOR ", ") FROM group_members gm JOIN student_groups g ON g.id = gm.group_id WHERE gm.user_id = u.id) AS group_names
    FROM users u' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY FIELD(role, "admin", "teacher", "student"), fullname', $params);

$pageTitle = $role === 'teacher' ? 'ครูผู้สอน' : ($role === 'student' ? 'ผู้เรียน' : 'สมาชิกทั้งหมด');
require __DIR__ . '/_top.php';
$statusBadge = ['active' => '<span class="badge bg-success">ใช้งาน</span>', 'pending' => '<span class="badge bg-warning text-dark">รออนุมัติ</span>', 'banned' => '<span class="badge bg-dark">ระงับ</span>'];
?>
<div class="d-flex flex-wrap gap-2 justify-content-between mb-3">
    <form class="d-flex flex-wrap gap-2">
        <select name="role" class="form-select w-auto" onchange="this.form.submit()">
            <option value="">ทุกประเภท</option>
            <?php foreach (['admin', 'teacher', 'student'] as $r): ?><option value="<?= $r ?>" <?= $role === $r ? 'selected' : '' ?>><?= role_label($r) ?></option><?php endforeach; ?>
        </select>
        <select name="status" class="form-select w-auto" onchange="this.form.submit()">
            <option value="">ทุกสถานะ</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>ใช้งาน</option>
            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>รออนุมัติ</option>
            <option value="banned" <?= $status === 'banned' ? 'selected' : '' ?>>ระงับ</option>
        </select>
        <input name="q" class="form-control w-auto" placeholder="ค้นหาชื่อ / รหัส / อีเมล" value="<?= e($q) ?>">
        <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    </form>
    <div class="d-flex gap-2">
        <a href="<?= url('admin/user_form.php?role=teacher') ?>" class="btn btn-primary"><i class="bi bi-person-video3"></i> เพิ่มครูผู้สอน</a>
        <a href="<?= url('admin/user_form.php?role=student') ?>" class="btn btn-vt"><i class="bi bi-person-plus"></i> เพิ่มผู้เรียน</a>
    </div>
</div>
<div class="card border-0 shadow-sm rounded-4">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>สมาชิก</th><th>ชื่อผู้ใช้</th><th>ประเภท</th><th>ข้อมูล</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><div class="d-flex align-items-center gap-2"><img src="<?= e(avatar_url($u)) ?>" class="avatar" alt="">
                        <div><?= e($u['fullname']) ?><div class="small text-muted"><?= e($u['email']) ?></div></div></div></td>
                    <td class="small"><?= e($u['username']) ?><?= $u['student_code'] ? '<div class="text-muted">' . e($u['student_code']) . '</div>' : '' ?></td>
                    <td><?= role_badge($u['role']) ?></td>
                    <td class="small text-muted">
                        <?php if ($u['role'] !== 'student'): ?>สอน <?= (int)$u['teach_count'] ?> วิชา<?php endif; ?>
                        <?= e($u['group_names']) ?>
                    </td>
                    <td><?= $statusBadge[$u['status']] ?></td>
                    <td class="text-end text-nowrap">
                        <a href="<?= url('admin/user_form.php?id=' . $u['id']) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                        <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="back_role" value="<?= e($role) ?>"><input type="hidden" name="back_status" value="<?= e($status) ?>">
                                <?php if ($u['status'] !== 'active'): ?>
                                    <button name="action" value="active" class="btn btn-sm btn-outline-success" title="อนุมัติ/เปิดใช้งาน"><i class="bi bi-check-lg"></i></button>
                                <?php else: ?>
                                    <button name="action" value="banned" class="btn btn-sm btn-outline-dark" title="ระงับ"><i class="bi bi-slash-circle"></i></button>
                                <?php endif; ?>
                            </form>
                            <form method="post" class="d-inline" data-confirm="ลบสมาชิกนี้ถาวร? (ความก้าวหน้าการเรียนจะถูกลบด้วย)"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="back_role" value="<?= e($role) ?>"><input type="hidden" name="back_status" value="<?= e($status) ?>">
                                <button name="action" value="delete" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$users): ?><tr><td colspan="6" class="text-center text-muted py-5">ไม่พบสมาชิก</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
