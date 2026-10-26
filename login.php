<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) redirect('index.php');

$next = $_GET['next'] ?? $_POST['next'] ?? '';
// อนุญาตเฉพาะ path ภายในเว็บ
if (!preg_match('#^' . preg_quote(BASE_PATH, '#') . '/[^/\\\\]#', $next)) $next = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $user = db_one('SELECT * FROM users WHERE username = ? OR (email <> \'\' AND email = ?)', [post('username'), post('username')]);
    if (!$user || !password_verify(post('password'), $user['password'])) {
        flash('danger', 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง');
    } elseif ($user['status'] === 'pending') {
        flash('warning', 'บัญชีของคุณรอการอนุมัติจากผู้ดูแลระบบ');
    } elseif ($user['status'] === 'banned') {
        flash('danger', 'บัญชีของคุณถูกระงับการใช้งาน');
    } else {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        flash('success', 'ยินดีต้อนรับ ' . $user['fullname']);
        if ($next) redirect($next);
        redirect($user['role'] === 'student' ? 'my-courses.php' : 'admin/index.php');
    }
}

$pageTitle = 'เข้าสู่ระบบ';
require __DIR__ . '/includes/header.php';
?>
<div class="container py-5" style="max-width:440px">
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body p-4">
            <div class="text-center mb-3">
                <i class="bi bi-mortarboard-fill display-5 text-vt"></i>
                <h4 class="mt-2 mb-0">เข้าสู่ระบบ</h4>
                <div class="text-muted small"><?= e(setting('site_slogan')) ?></div>
            </div>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="next" value="<?= e($next) ?>">
                <div class="mb-3">
                    <label class="form-label">ชื่อผู้ใช้ หรือ อีเมล</label>
                    <input name="username" class="form-control" required autofocus value="<?= e(post('username')) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">รหัสผ่าน</label>
                    <input name="password" type="password" class="form-control" required>
                </div>
                <button class="btn btn-vt w-100 py-2">เข้าสู่ระบบ</button>
            </form>
            <?php if (setting('allow_register') === '1'): ?>
                <p class="text-center mt-3 mb-0 small">ยังไม่มีบัญชี? <a href="<?= url('register.php') ?>">สมัครสมาชิก</a></p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
