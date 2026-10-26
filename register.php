<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) redirect('index.php');
if (setting('allow_register') !== '1') {
    flash('warning', 'ระบบปิดรับสมัครสมาชิกชั่วคราว กรุณาติดต่อผู้ดูแลระบบ');
    redirect('login.php');
}

$groups = db_all('SELECT id, name, level FROM student_groups ORDER BY level, name');
$selectedGroup = filter_var(post('group_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = post('username');
    $email = post('email');
    if (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $username)) $errors[] = 'ชื่อผู้ใช้ต้องเป็นภาษาอังกฤษ/ตัวเลข/ _ . ความยาว 3-50 ตัว';
    if (post('fullname') === '') $errors[] = 'กรุณากรอกชื่อ-นามสกุล';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
    if (strlen(post('password')) < 6) $errors[] = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
    if (post('password') !== post('password2')) $errors[] = 'ยืนยันรหัสผ่านไม่ตรงกัน';
    if (!post('agree')) $errors[] = 'กรุณายอมรับนโยบายความเป็นส่วนตัว';
    if (!$selectedGroup || !db_value('SELECT 1 FROM student_groups WHERE id = ?', [$selectedGroup])) {
        $errors[] = 'กรุณาเลือกกลุ่มเรียนที่มีอยู่ในรายการ';
    }
    if (!$errors && db_value('SELECT 1 FROM users WHERE username = ?', [$username])) $errors[] = 'ชื่อผู้ใช้นี้มีผู้ใช้งานแล้ว';
    if (!$errors && $email !== '' && db_value('SELECT 1 FROM users WHERE email = ?', [$email])) $errors[] = 'อีเมลนี้ถูกใช้สมัครแล้ว';

    if (!$errors) {
        $status = setting('require_approval') === '1' ? 'pending' : 'active';
        $conn->begin_transaction();
        try {
            $newId = db_exec("INSERT INTO users (username,password,fullname,email,phone,student_code,role,status) VALUES (?,?,?,?,?,?,'student',?)",
                [$username, password_hash(post('password'), PASSWORD_DEFAULT), post('fullname'), $email ?: null, post('phone') ?: null, post('student_code') ?: null, $status]);
            record_consent(['id' => $newId, 'username' => $username], 'accept');
            db_exec('INSERT INTO group_members (group_id, user_id) VALUES (?, ?)', [$selectedGroup, $newId]);
            $conn->commit();
            flash('success', $status === 'pending' ? 'สมัครสมาชิกสำเร็จ กรุณารอผู้ดูแลระบบอนุมัติ' : 'สมัครสมาชิกสำเร็จ กรุณาเข้าสู่ระบบ');
            redirect('login.php');
        } catch (mysqli_sql_exception $ex) {
            $conn->rollback();
            $errors[] = 'ไม่สามารถสมัครสมาชิกได้ ข้อมูลบัญชีหรือกลุ่มเรียนอาจมีการเปลี่ยนแปลง กรุณาตรวจสอบแล้วลองอีกครั้ง';
            $groups = db_all('SELECT id, name, level FROM student_groups ORDER BY level, name');
        }
    }
}

$pageTitle = 'สมัครสมาชิก';
require __DIR__ . '/includes/header.php';
?>
<div class="container py-5" style="max-width:560px">
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body p-4">
            <h4 class="mb-1">สมัครสมาชิก (ผู้เรียน)</h4>
            <p class="text-muted small">บัญชีครูผู้สอนจะถูกสร้างโดยผู้ดูแลระบบ</p>
            <?php if ($errors): ?>
                <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er) echo '<li>' . e($er) . '</li>'; ?></ul></div>
            <?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-12"><label class="form-label">ชื่อ-นามสกุล *</label><input name="fullname" class="form-control" required value="<?= e(post('fullname')) ?>"></div>
                    <div class="col-md-6"><label class="form-label">รหัสนักศึกษา</label><input name="student_code" class="form-control" value="<?= e(post('student_code')) ?>"></div>
                    <div class="col-md-6"><label class="form-label">เบอร์โทร</label><input name="phone" class="form-control" value="<?= e(post('phone')) ?>"></div>
                    <div class="col-12">
                        <label class="form-label" for="group_id">กลุ่มเรียน *</label>
                        <select name="group_id" id="group_id" class="form-select" required aria-describedby="group_help" <?= !$groups ? 'disabled' : '' ?>>
                            <option value="">-- เลือกกลุ่มเรียน --</option>
                            <?php foreach ($groups as $g): ?>
                                <option value="<?= (int)$g['id'] ?>" <?= $selectedGroup === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['name'] . ($g['level'] ? ' (' . $g['level'] . ')' : '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="group_help" class="form-text">เลือกกลุ่มของตนเอง นักเรียนจะเรียนได้หลายรายวิชาตามที่ครูอนุมัติให้กลุ่มเข้าเรียน</div>
                        <?php if (!$groups): ?><div class="text-danger small mt-1">ยังไม่มีกลุ่มเรียน กรุณาติดต่อครูหรือผู้ดูแลระบบเพื่อเพิ่มกลุ่มก่อนสมัคร</div><?php endif; ?>
                    </div>
                    <div class="col-12"><label class="form-label">อีเมล</label><input name="email" type="email" class="form-control" value="<?= e(post('email')) ?>"></div>
                    <div class="col-12"><label class="form-label">ชื่อผู้ใช้ *</label><input name="username" class="form-control" required value="<?= e(post('username')) ?>"></div>
                    <div class="col-md-6"><label class="form-label">รหัสผ่าน *</label><input name="password" type="password" class="form-control" required></div>
                    <div class="col-md-6"><label class="form-label">ยืนยันรหัสผ่าน *</label><input name="password2" type="password" class="form-control" required></div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="agree" value="1" id="agree" required <?= post('agree') ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="agree">ข้าพเจ้าได้อ่านและยอมรับ <a href="<?= url('privacy.php') ?>" target="_blank">นโยบายความเป็นส่วนตัว</a>
                                และยินยอมให้เก็บรวบรวม ใช้ และเปิดเผยข้อมูลส่วนบุคคลตามนโยบายดังกล่าว *</label>
                        </div>
                    </div>
                </div>
                <button class="btn btn-vt w-100 py-2 mt-4" <?= !$groups ? 'disabled' : '' ?>>สมัครสมาชิก</button>
            </form>
            <p class="text-center mt-3 mb-0 small">มีบัญชีแล้ว? <a href="<?= url('login.php') ?>">เข้าสู่ระบบ</a></p>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
