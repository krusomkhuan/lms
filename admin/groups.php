<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$me = require_role('admin', 'teacher');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $g = db_one('SELECT * FROM student_groups WHERE id = ?', [(int)post('id')]);
    if ($g && post('action') === 'delete' && can_manage_group($g)) {
        db_exec('DELETE FROM student_groups WHERE id = ?', [(int)$g['id']]);
        flash('success', 'ลบกลุ่มเรียบร้อย');
    }
    redirect('admin/groups.php');
}

$groups = db_all('SELECT g.*,
        (SELECT GROUP_CONCAT(u.fullname ORDER BY u.fullname SEPARATOR ", ") FROM group_teachers gt JOIN users u ON u.id = gt.teacher_id WHERE gt.group_id = g.id) AS teacher_names,
        (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id) AS members,
        (SELECT GROUP_CONCAT(c.code SEPARATOR ", ") FROM course_groups cg JOIN courses c ON c.id = cg.course_id WHERE cg.group_id = g.id) AS courses
    FROM student_groups g ORDER BY g.level, g.name');

$pageTitle = 'กลุ่มผู้เรียน';
require __DIR__ . '/_top.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="text-muted small">กลุ่มจะแสดงให้นักเรียนเลือกตอนสมัคร ครูประจำรายวิชาอนุมัติกลุ่มเข้าเรียนได้ที่หน้าแก้ไขรายวิชา</div>
    <a href="<?= url('admin/group_form.php') ?>" class="btn btn-vt"><i class="bi bi-plus-lg"></i> สร้างกลุ่ม</a>
</div>
<div class="card border-0 shadow-sm rounded-4">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>ชื่อกลุ่ม</th><th>ระดับชั้น</th><th>ครูผู้สอน</th><th class="text-center">สมาชิก</th><th>รายวิชาที่เรียน</th><th class="text-end">จัดการ</th></tr></thead>
            <tbody>
            <?php foreach ($groups as $g): ?>
                <tr>
                    <td class="fw-semibold"><?= e($g['name']) ?><div class="small text-muted fw-normal"><?= e($g['description']) ?></div></td>
                    <td><?= e($g['level'] ?: '-') ?></td>
                    <td class="small"><?= e($g['teacher_names'] ?: '-') ?></td>
                    <td class="text-center"><span class="badge bg-primary rounded-pill"><?= (int)$g['members'] ?></span></td>
                    <td class="small"><?= e($g['courses'] ?: '-') ?></td>
                    <td class="text-end text-nowrap">
                        <?php if (can_manage_group($g)): ?>
                            <a href="<?= url('admin/group_form.php?id=' . $g['id']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-people"></i> สมาชิก/แก้ไข</a>
                            <form method="post" class="d-inline" data-confirm="ลบกลุ่มนี้? (ไม่ลบบัญชีผู้เรียน)"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $g['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                        <?php else: ?>
                            <a href="<?= url('admin/group_form.php?id=' . $g['id']) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i> ดูสมาชิก</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$groups): ?><tr><td colspan="6" class="text-center text-muted py-5">ยังไม่มีกลุ่มผู้เรียน</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
