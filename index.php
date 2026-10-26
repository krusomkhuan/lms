<?php
require_once __DIR__ . '/includes/bootstrap.php';

$latest = db_all(course_list_sql() . " WHERE c.status = 'published' ORDER BY c.created_at DESC LIMIT 8");
$stats = [
    'courses'  => (int)db_value("SELECT COUNT(*) FROM courses WHERE status = 'published'"),
    'lessons'  => (int)db_value('SELECT COUNT(*) FROM lessons WHERE is_published = 1'),
    'teachers' => (int)db_value("SELECT COUNT(*) FROM users WHERE role = 'teacher' AND status = 'active'"),
    'students' => (int)db_value("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'"),
];

$pageTitle = 'หน้าแรก';
$noContainerFlash = true;
require __DIR__ . '/includes/header.php';
?>
<section class="hero">
    <div class="container position-relative" style="z-index:1">
        <?= render_flash() ?>
        <div class="row align-items-center">
            <div class="col-lg-7">
                <div class="slogan mb-2"><i class="bi bi-stars"></i> <?= e(setting('site_slogan')) ?></div>
                <h1 class="display-5 fw-semibold mb-3"><?= e(setting('site_name')) ?></h1>
                <p class="lead opacity-75 mb-4">ระบบจัดการเรียนการสอนออนไลน์สำหรับครูอาชีวศึกษา รวมรายวิชา สื่อการสอนหลากหลายรูปแบบ
                    ทั้งวิดีโอ เอกสาร รูปภาพ ไฟล์เสียง และลิงก์ เรียนได้ทุกที่ ทุกเวลา</p>
                <form action="<?= url('courses.php') ?>" class="d-flex gap-2" style="max-width:520px">
                    <input name="q" class="form-control form-control-lg" placeholder="ค้นหารายวิชา รหัสวิชา...">
                    <button class="btn btn-warning btn-lg px-4"><i class="bi bi-search"></i></button>
                </form>
            </div>
            <div class="col-lg-5 d-none d-lg-block text-center">
                <i class="bi bi-laptop" style="font-size:11rem;opacity:.9"></i>
            </div>
        </div>
    </div>
</section>

<div class="container" style="margin-top:-2.5rem;position:relative;z-index:2">
    <div class="row g-3">
        <?php foreach ([['courses', 'รายวิชา', 'bi-journal-bookmark', 'primary'], ['lessons', 'บทเรียน/สื่อ', 'bi-collection-play', 'danger'], ['teachers', 'ครูผู้สอน', 'bi-person-video3', 'success'], ['students', 'ผู้เรียน', 'bi-people', 'warning']] as $s): ?>
            <div class="col-6 col-md-3">
                <div class="card stat-card shadow-sm">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="icon bg-<?= $s[3] ?>-subtle text-<?= $s[3] ?>"><i class="bi <?= $s[2] ?>"></i></div>
                        <div><div class="fs-4 fw-semibold"><?= number_format($stats[$s[0]]) ?></div><div class="small text-muted"><?= $s[1] ?></div></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-end mb-3">
        <h4 class="mb-0">รายวิชาล่าสุด</h4>
        <a href="<?= url('courses.php') ?>">ดูทั้งหมด <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="row g-4">
        <?php foreach ($latest as $c) include __DIR__ . '/includes/course_card.php'; ?>
        <?php if (!$latest): ?><div class="col-12 text-muted">ยังไม่มีรายวิชาที่เผยแพร่</div><?php endif; ?>
    </div>

    <h4 class="mt-5 mb-3">เว็บไซต์ที่เกี่ยวข้อง</h4>
    <div class="row g-4">
        <?php foreach ([
            ['https://krusomkhuan.com/docshub/', 'docshub.svg', 'Docs Hub', 'ศูนย์รวมงานจัดเก็บเอกสาร'],
            ['https://krusomkhuan.com/prompthub', 'prompthub.svg', 'Prompt Hub', 'เปลี่ยนไอเดียให้เป็นจริงด้วย AI'],
            ['https://krusomkhuan.com/teachhub', 'teachhub.svg', 'Teach Hub', 'ระบบงานพัฒนาหลักสูตรและการจัดการเรียนรู้'],
            ['https://krusomkhuan.com/docshub/photo-albums.php', 'imagehub.svg', 'Image Hub', 'คลังรูปภาพและสื่อกราฟิก'],
        ] as $h): ?>
            <div class="col-sm-6 col-lg-4 col-xl-3">
                <a href="<?= e($h[0]) ?>" target="_blank" rel="noopener" class="card course-card shadow-sm text-reset">
                    <img src="<?= url('assets/img/hubs/' . $h[1]) ?>" class="cover" alt="<?= e($h[2]) ?>">
                    <div class="card-body">
                        <h6 class="card-title fw-semibold mb-2"><?= e($h[2]) ?></h6>
                        <div class="small text-muted"><?= e($h[3]) ?></div>
                    </div>
                    <div class="card-footer bg-white border-0 small text-vt"><i class="bi bi-box-arrow-up-right"></i> เข้าสู่เว็บไซต์</div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4 mt-4">
        <?php foreach ([
            ['bi-collection-play', 'สื่อหลากหลายรูปแบบ', 'วิดีโอ YouTube, ไฟล์วิดีโอ, PDF, รูปภาพ, ไฟล์เสียง, ไฟล์ดาวน์โหลด และลิงก์ภายนอก'],
            ['bi-people', 'จัดกลุ่มผู้เรียน', 'จัดผู้เรียนเป็นห้อง/กลุ่ม แล้วมอบสิทธิ์เข้าเรียนรายวิชาให้ทั้งกลุ่มได้ในคลิกเดียว'],
            ['bi-graph-up-arrow', 'ติดตามความก้าวหน้า', 'ครูดูได้ว่าผู้เรียนแต่ละคนเรียนจบไปกี่บท ผู้เรียนเห็นความก้าวหน้าของตนเอง'],
        ] as $f): ?>
            <div class="col-md-4">
                <div class="d-flex gap-3">
                    <i class="bi <?= $f[0] ?> fs-2 text-vt"></i>
                    <div><h6 class="fw-semibold"><?= $f[1] ?></h6><p class="text-muted small mb-0"><?= $f[2] ?></p></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
