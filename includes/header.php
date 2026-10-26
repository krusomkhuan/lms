<?php
// หน้าบ้าน: ส่วนหัว  ($pageTitle กำหนดก่อน include)
$me = current_user();
$siteName = setting('site_name', 'Vocational Teacher');
$slogan = setting('site_slogan', 'ครูสมควรออนไลน์');
$curPage = basename($_SERVER['SCRIPT_NAME']);
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($pageTitle ?? '') ? $pageTitle . ' | ' : '') . e($siteName) ?> — <?= e($slogan) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="<?= url('assets/css/style.css') ?>?v=<?= e(APP_VERSION) ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark navbar-vt sticky-top shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="<?= url('index.php') ?>">
            <i class="bi bi-mortarboard-fill"></i> <?= e($siteName) ?>
            <small><?= e($slogan) ?></small>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link <?= $curPage === 'index.php' ? 'active' : '' ?>" href="<?= url('index.php') ?>">หน้าแรก</a></li>
                <li class="nav-item"><a class="nav-link <?= $curPage === 'courses.php' ? 'active' : '' ?>" href="<?= url('courses.php') ?>">รายวิชาทั้งหมด</a></li>
                <?php if ($me): ?>
                    <li class="nav-item"><a class="nav-link <?= $curPage === 'my-courses.php' ? 'active' : '' ?>" href="<?= url('my-courses.php') ?>">วิชาของฉัน</a></li>
                <?php endif; ?>
                <li class="nav-item"><a class="nav-link <?= $curPage === 'manual.php' ? 'active' : '' ?>" href="<?= url('manual.php') ?>"><i class="bi bi-book"></i> คู่มือการใช้งาน</a></li>
            </ul>
            <ul class="navbar-nav">
                <?php if ($me): ?>
                    <?php if (in_array($me['role'], ['admin', 'teacher'], true)): ?>
                        <li class="nav-item"><a class="nav-link" href="<?= url('admin/index.php') ?>"><i class="bi bi-speedometer2"></i> หลังบ้าน</a></li>
                    <?php endif; ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" data-bs-toggle="dropdown">
                            <img src="<?= e(avatar_url($me)) ?>" class="avatar" alt=""> <?= e($me['fullname']) ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><span class="dropdown-item-text small"><?= role_badge($me['role']) ?></span></li>
                            <li><a class="dropdown-item" href="<?= url('profile.php') ?>"><i class="bi bi-person"></i> ข้อมูลส่วนตัว</a></li>
                            <li><a class="dropdown-item" href="<?= url('my-courses.php') ?>"><i class="bi bi-journal-bookmark"></i> วิชาของฉัน</a></li>
                            <li><a class="dropdown-item" href="<?= url('manual.php') ?>"><i class="bi bi-book"></i> คู่มือการใช้งาน</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a></li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="<?= url('login.php') ?>">เข้าสู่ระบบ</a></li>
                    <?php if (setting('allow_register') === '1'): ?>
                        <li class="nav-item"><a class="btn btn-warning btn-sm ms-lg-2 mt-1" href="<?= url('register.php') ?>">สมัครสมาชิก</a></li>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
<?php if (empty($noContainerFlash)): ?>
<div class="container mt-3"><?= render_flash() ?></div>
<?php endif; ?>
