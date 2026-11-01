<?php
// หลังบ้าน: ส่วนหัว + เมนูด้านข้าง  ($pageTitle, $me ต้องกำหนดก่อน include)
$curPage = basename($_SERVER['SCRIPT_NAME']);
$isAdmin = $me['role'] === 'admin';
$menu = [
    ['index.php', 'bi-speedometer2', 'แดชบอร์ด', ['index.php']],
    ['courses.php', 'bi-journal-bookmark', 'รายวิชา', ['courses.php', 'course_form.php', 'lessons.php', 'lesson_form.php', 'course_students.php', 'gradebook.php', 'grade_history.php', 'assignments.php', 'quiz_results.php', 'course_backup.php']],
    ['score_dashboard.php', 'bi-bar-chart-line', 'แดชบอร์ดคะแนน', ['score_dashboard.php']],
    ['question_bank.php', 'bi-archive', 'คลังข้อสอบ', ['question_bank.php', 'quiz_import.php', 'prepost.php']],
    ['groups.php', 'bi-people', 'กลุ่มผู้เรียน', ['groups.php', 'group_form.php']],
];
if ($isAdmin) {
    $menu[] = ['users.php?role=teacher', 'bi-person-video3', 'ครูผู้สอน', ['users.php#teacher']];
    $menu[] = ['users.php', 'bi-person-lines-fill', 'สมาชิกทั้งหมด', ['users.php', 'user_form.php']];
    $pdpaPending = (int)db_value("SELECT COUNT(*) FROM pdpa_requests WHERE status = 'pending'");
    $menu[] = ['pdpa.php', 'bi-shield-lock', 'PDPA' . ($pdpaPending ? ' <span class="badge bg-danger rounded-pill">' . $pdpaPending . '</span>' : ''), ['pdpa.php']];
    $menu[] = ['ai_settings.php', 'bi-stars', 'ตั้งค่า AI', ['ai_settings.php']];
    $menu[] = ['backup.php', 'bi-database-down', 'สำรอง/กู้คืนข้อมูล', ['backup.php']];
    $menu[] = ['settings.php', 'bi-gear', 'ตั้งค่าระบบ', ['settings.php']];
}
$activeKey = $curPage === 'users.php' && ($_GET['role'] ?? '') === 'teacher' ? 'users.php#teacher' : $curPage;
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'หลังบ้าน') ?> | หลังบ้าน <?= e(setting('site_name')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="<?= url('assets/css/style.css') ?>?v=<?= e(APP_VERSION) ?>" rel="stylesheet">
</head>
<body>
<div class="admin-wrap">
    <aside class="admin-sidebar" id="sidebar">
        <div class="brand">
            <div class="fw-semibold"><i class="bi bi-mortarboard-fill"></i> <?= e(setting('site_name')) ?></div>
            <small><?= e(setting('site_slogan')) ?> · หลังบ้าน</small>
        </div>
        <nav class="nav flex-column py-2">
            <?php foreach ($menu as $m): ?>
                <?php $active = in_array($activeKey, $m[3], true) && !($m[0] === 'users.php' && $activeKey === 'users.php#teacher'); ?>
                <a class="nav-link <?= $active ? 'active' : '' ?>" href="<?= url('admin/' . $m[0]) ?>"><i class="bi <?= $m[1] ?>"></i> <?= $m[2] ?></a>
            <?php endforeach; ?>
            <hr class="border-secondary mx-3">
            <a class="nav-link" href="<?= url('index.php') ?>" target="_blank"><i class="bi bi-house"></i> ไปหน้าบ้าน</a>
            <a class="nav-link" href="<?= url('student_preview.php') ?>" target="_blank"><i class="bi bi-eye"></i> มุมมองสำหรับผู้เรียน</a>
            <a class="nav-link" href="<?= url('manual.php?role=' . ($isAdmin ? 'admin' : 'teacher')) ?>" target="_blank"><i class="bi bi-book"></i> คู่มือการใช้งาน</a>
            <a class="nav-link" href="<?= url('profile.php') ?>"><i class="bi bi-person"></i> ข้อมูลส่วนตัว</a>
            <a class="nav-link nav-logout" href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a>
        </nav>
        <div class="px-4 py-3 small admin-version">เวอร์ชัน <?= e(APP_VERSION) ?> · <?= thai_date(APP_RELEASE_DATE) ?></div>
    </aside>
    <div class="admin-main">
        <div class="admin-topbar px-3 py-2 d-flex align-items-center gap-2">
            <button class="btn btn-sm btn-light d-lg-none" onclick="document.getElementById('sidebar').classList.toggle('show')"><i class="bi bi-list fs-5"></i></button>
            <h5 class="mb-0 flex-grow-1 text-truncate"><?= e($pageTitle ?? '') ?></h5>
            <img src="<?= e(avatar_url($me)) ?>" class="avatar" alt="">
            <div class="small lh-sm d-none d-sm-block"><?= e($me['fullname']) ?><br><?= role_badge($me['role']) ?></div>
        </div>
        <div class="p-3 p-lg-4">
            <?= render_flash() ?>
