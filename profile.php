<?php
require_once __DIR__ . '/includes/bootstrap.php';

$me = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('action'), ['pdpa_request', 'withdraw'], true)) {
    verify_csrf();
    if (post('action') === 'withdraw') {
        // ถอนความยินยอม: บันทึก + ออกจากระบบ (เข้าใหม่จะถูกขอความยินยอมอีกครั้ง)
        record_consent($me, 'withdraw');
        $_SESSION = [];
        session_regenerate_id(true);
        flash('info', 'ถอนความยินยอมแล้ว หากต้องการให้ลบข้อมูล กรุณาเข้าสู่ระบบและยื่นคำร้อง "ขอลบบัญชี" หรือติดต่อผู้ดูแลระบบ');
        redirect('login.php');
    }
    $type = array_key_exists(post('type'), pdpa_request_types()) ? post('type') : 'other';
    if (db_value("SELECT 1 FROM pdpa_requests WHERE user_id = ? AND type = ? AND status = 'pending'", [(int)$me['id'], $type])) {
        flash('warning', 'ท่านมีคำร้องประเภทนี้ที่รอดำเนินการอยู่แล้ว');
    } else {
        db_exec('INSERT INTO pdpa_requests (user_id, username, fullname, type, detail) VALUES (?,?,?,?,?)',
            [(int)$me['id'], $me['username'], $me['fullname'], $type, mb_substr(post('detail'), 0, 2000) ?: null]);
        flash('success', 'ส่งคำร้องแล้ว ผู้ดูแลระบบจะพิจารณาภายใน 30 วัน');
    }
    redirect('profile.php#pdpa');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = post('email');
    try {
        if (post('fullname') === '') throw new RuntimeException('กรุณากรอกชื่อ-นามสกุล');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('รูปแบบอีเมลไม่ถูกต้อง');
        if ($email !== '' && db_value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, (int)$me['id']])) throw new RuntimeException('อีเมลนี้ถูกใช้แล้ว');

        $avatar = upload_file('avatar', 'avatars', allowed_ext('cover'));
        if ($avatar) {
            delete_upload($me['avatar']);
        }
        db_exec('UPDATE users SET fullname=?, email=?, phone=?, student_code=?, avatar=? WHERE id=?',
            [post('fullname'), $email ?: null, post('phone') ?: null, post('student_code') ?: null, $avatar ?: $me['avatar'], (int)$me['id']]);

        if (post('new_password') !== '') {
            if (!password_verify(post('old_password'), $me['password'])) throw new RuntimeException('รหัสผ่านเดิมไม่ถูกต้อง (ข้อมูลอื่นบันทึกแล้ว)');
            if (strlen(post('new_password')) < 6) throw new RuntimeException('รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร');
            db_exec('UPDATE users SET password=? WHERE id=?', [password_hash(post('new_password'), PASSWORD_DEFAULT), (int)$me['id']]);
        }
        flash('success', 'บันทึกข้อมูลเรียบร้อย');
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('profile.php');
}

$groups = db_all('SELECT g.name FROM group_members gm JOIN student_groups g ON g.id = gm.group_id WHERE gm.user_id = ?', [(int)$me['id']]);
$myRequests = db_all('SELECT * FROM pdpa_requests WHERE user_id = ? ORDER BY id DESC', [(int)$me['id']]);
$reqTypes = pdpa_request_types();
$reqStatus = ['pending' => ['รอดำเนินการ', 'warning'], 'done' => ['ดำเนินการแล้ว', 'success'], 'rejected' => ['ปฏิเสธ', 'secondary']];
$pageTitle = 'ข้อมูลส่วนตัว';
require __DIR__ . '/includes/header.php';
?>
<div class="container py-4" style="max-width:820px">
    <h3 class="mb-3">ข้อมูลส่วนตัว</h3>
    <form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <?= csrf_field() ?>
            <div class="d-flex align-items-center gap-3 mb-4">
                <img src="<?= e(avatar_url($me)) ?>" class="rounded-circle" style="width:84px;height:84px;object-fit:cover" alt="">
                <div>
                    <div class="fw-semibold"><?= e($me['username']) ?> <?= role_badge($me['role']) ?></div>
                    <div class="small text-muted">สมาชิกเมื่อ <?= thai_date($me['created_at']) ?></div>
                    <?php if ($groups): ?><div class="small">กลุ่ม: <?= e(implode(', ', array_column($groups, 'name'))) ?></div><?php endif; ?>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">ชื่อ-นามสกุล</label><input name="fullname" class="form-control" required value="<?= e($me['fullname']) ?>"></div>
                <div class="col-md-6"><label class="form-label">รหัสนักศึกษา / รหัสครู</label><input name="student_code" class="form-control" value="<?= e($me['student_code']) ?>"></div>
                <div class="col-md-6"><label class="form-label">อีเมล</label><input name="email" type="email" class="form-control" value="<?= e($me['email']) ?>"></div>
                <div class="col-md-6"><label class="form-label">เบอร์โทร</label><input name="phone" class="form-control" value="<?= e($me['phone']) ?>"></div>
                <div class="col-12"><label class="form-label">รูปโปรไฟล์</label><input name="avatar" type="file" accept="image/*" class="form-control"></div>
            </div>
            <hr class="my-4">
            <h6>เปลี่ยนรหัสผ่าน <small class="text-muted">(เว้นว่างหากไม่ต้องการเปลี่ยน)</small></h6>
            <div class="row g-3">
                <div class="col-md-6"><input name="old_password" type="password" class="form-control" placeholder="รหัสผ่านเดิม" autocomplete="current-password"></div>
                <div class="col-md-6"><input name="new_password" type="password" class="form-control" placeholder="รหัสผ่านใหม่" autocomplete="new-password"></div>
            </div>
            <button class="btn btn-vt mt-4 px-4">บันทึก</button>
        </div>
    </form>

    <div class="card border-0 shadow-sm rounded-4 mt-4" id="pdpa">
        <div class="card-body p-4">
            <h5 class="mb-1"><i class="bi bi-shield-lock text-vt"></i> ข้อมูลส่วนบุคคล (PDPA)</h5>
            <p class="small text-muted">
                ยอมรับ<a href="<?= url('privacy.php') ?>" target="_blank">นโยบายความเป็นส่วนตัว</a> เวอร์ชัน <?= e($me['consent_version'] ?: '-') ?>
                เมื่อ <?= thai_date($me['consent_at'], true) ?>
            </p>
            <div class="d-flex flex-wrap gap-2 mb-4">
                <a href="<?= url('my-data.php') ?>" class="btn btn-outline-primary"><i class="bi bi-filetype-json"></i> ดาวน์โหลดข้อมูลของฉัน</a>
                <form method="post" onsubmit="return confirm('ถอนความยินยอม? ท่านจะถูกออกจากระบบ และต้องยอมรับนโยบายอีกครั้งจึงจะใช้งานได้')">
                    <?= csrf_field() ?><input type="hidden" name="action" value="withdraw">
                    <button class="btn btn-outline-danger"><i class="bi bi-x-octagon"></i> ถอนความยินยอม</button>
                </form>
            </div>

            <h6>ยื่นคำร้องใช้สิทธิเจ้าของข้อมูล</h6>
            <form method="post" class="row g-2">
                <?= csrf_field() ?><input type="hidden" name="action" value="pdpa_request">
                <div class="col-md-5">
                    <select name="type" class="form-select">
                        <?php foreach ($reqTypes as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-7"><input name="detail" class="form-control" maxlength="2000" placeholder="รายละเอียด เช่น ข้อมูลที่ต้องการแก้ไข / เหตุผล"></div>
                <div class="col-12"><button class="btn btn-sm btn-vt"><i class="bi bi-send"></i> ส่งคำร้อง</button></div>
            </form>

            <?php if ($myRequests): ?>
                <div class="table-responsive mt-3">
                    <table class="table table-sm small mb-0">
                        <thead><tr><th>วันที่</th><th>ประเภท</th><th>สถานะ</th><th>หมายเหตุจากผู้ดูแล</th></tr></thead>
                        <tbody>
                        <?php foreach ($myRequests as $r): $st = $reqStatus[$r['status']]; ?>
                            <tr>
                                <td class="text-nowrap"><?= thai_date($r['created_at'], true) ?></td>
                                <td><?= e($reqTypes[$r['type']]) ?></td>
                                <td><span class="badge bg-<?= $st[1] ?>"><?= $st[0] ?></span></td>
                                <td><?= e($r['admin_note'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
