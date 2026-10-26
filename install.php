<?php
define('INSTALLING', true);
require_once __DIR__ . '/includes/bootstrap.php';

$lock = ROOT_PATH . '/config/installed.lock';
if (file_exists($lock)) {
    exit('ติดตั้งระบบแล้ว หากต้องการติดตั้งใหม่ ให้ลบไฟล์ config/installed.lock');
}

$error = '';
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminUser = trim($_POST['username'] ?? '');
    $adminPass = $_POST['password'] ?? '';
    $adminName = trim($_POST['fullname'] ?? '');
    try {
        if (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $adminUser)) throw new RuntimeException('ชื่อผู้ใช้ต้องเป็น a-z, 0-9, _ . อย่างน้อย 3 ตัว');
        if (strlen($adminPass) < 6) throw new RuntimeException('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
        if ($adminName === '') throw new RuntimeException('กรุณากรอกชื่อ-นามสกุล');

        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
        $conn->set_charset('utf8mb4');
        $conn->query('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $conn->select_db(DB_NAME);

        $conn->multi_query(file_get_contents(ROOT_PATH . '/database/lms.sql'));
        do {
            if ($r = $conn->store_result()) $r->free();
        } while ($conn->more_results() && $conn->next_result());

        $adminId = db_exec("INSERT INTO users (username, password, fullname, role) VALUES (?, ?, ?, 'admin')",
            [$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), $adminName]);

        db_exec("INSERT INTO settings (name, value) VALUES ('db_version', ?)", [APP_VERSION]);

        if (!empty($_POST['demo'])) {
            require ROOT_PATH . '/database/demo_data.php';
            install_demo_data($adminId);
        }

        foreach (['', '/lessons', '/covers', '/avatars'] as $d) {
            if (!is_dir(UPLOAD_PATH . $d)) mkdir(UPLOAD_PATH . $d, 0775, true);
        }
        file_put_contents($lock, date('c'));
        $done = true;
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ติดตั้งระบบ Vocational Teacher</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;600&display=swap" rel="stylesheet">
    <style>body{font-family:'Prompt',sans-serif;background:linear-gradient(135deg,#0d3b66,#1b998b);min-height:100vh}</style>
</head>
<body class="d-flex align-items-center py-5">
<div class="container" style="max-width:560px">
    <div class="card shadow-lg border-0">
        <div class="card-body p-4">
            <h3 class="mb-1">ติดตั้งระบบ Vocational Teacher <span class="badge bg-secondary fs-6 align-middle">v<?= e(APP_VERSION) ?></span></h3>
            <p class="text-muted">ครูสมควรออนไลน์ — ระบบจัดการเรียนการสอน (LMS)</p>
            <?php if ($done): ?>
                <div class="alert alert-success">ติดตั้งเรียบร้อยแล้ว!</div>
                <a href="<?= url('login.php') ?>" class="btn btn-primary w-100">ไปหน้าเข้าสู่ระบบ</a>
            <?php else: ?>
                <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
                <div class="alert alert-info small mb-3">
                    ฐานข้อมูล: <b><?= e(DB_NAME) ?></b> @ <?= e(DB_HOST) ?> (ผู้ใช้ <?= e(DB_USER) ?>)<br>
                    แก้ไขได้ที่ <code>config/config.php</code> — ระบบจะสร้างฐานข้อมูลให้อัตโนมัติ
                </div>
                <form method="post">
                    <h6 class="mt-2">บัญชีผู้ดูแลระบบ</h6>
                    <div class="mb-2"><input name="fullname" class="form-control" placeholder="ชื่อ-นามสกุล" required value="<?= e($_POST['fullname'] ?? 'ผู้ดูแลระบบ') ?>"></div>
                    <div class="mb-2"><input name="username" class="form-control" placeholder="ชื่อผู้ใช้" required value="<?= e($_POST['username'] ?? 'admin') ?>"></div>
                    <div class="mb-3"><input name="password" type="password" class="form-control" placeholder="รหัสผ่าน (อย่างน้อย 6 ตัว)" required></div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="demo" id="demo" value="1" checked>
                        <label class="form-check-label" for="demo">สร้างข้อมูลตัวอย่าง (ครู ผู้เรียน กลุ่ม รายวิชา บทเรียน)</label>
                    </div>
                    <button class="btn btn-success w-100">ติดตั้งระบบ</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
