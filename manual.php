<?php
// คู่มือการใช้งานระบบ — แยกตามบทบาท: ผู้เรียน / ครูผู้สอน / ผู้ดูแลระบบ / คำถามที่พบบ่อย
require_once __DIR__ . '/includes/bootstrap.php';

$me = current_user();
require_once __DIR__ . '/includes/manual_data.php';
$manual = manual_content();

$defaultTab = $me ? ['admin' => 'admin', 'teacher' => 'teacher', 'student' => 'student'][$me['role']] : 'student';
$tab = array_key_exists($_GET['role'] ?? '', $manual) ? $_GET['role'] : $defaultTab;

$pageTitle = 'คู่มือการใช้งาน';
require __DIR__ . '/includes/header.php';
?>
<div class="bg-white border-bottom no-print">
    <div class="container py-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h2 class="mb-1"><i class="bi bi-book text-vt"></i> คู่มือการใช้งานระบบ</h2>
                <div class="text-muted"><?= e(setting('site_name')) ?> — <?= e(setting('site_slogan')) ?> · เวอร์ชัน <?= e(APP_VERSION) ?></div>
            </div>
            <div class="d-flex gap-2">
                <input type="search" id="manualSearch" class="form-control" placeholder="ค้นหาในคู่มือ..." style="min-width:220px">
                <button class="btn btn-outline-secondary text-nowrap" onclick="window.print()"><i class="bi bi-printer"></i> พิมพ์คู่มือ</button>
            </div>
        </div>
        <ul class="nav nav-pills mt-3 manual-tabs">
            <?php foreach ($manual as $k => $m): ?>
                <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="?role=<?= $k ?>"><i class="bi <?= $m['icon'] ?>"></i> <?= e($m['label']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<div class="container py-4">
    <h3 class="print-only mb-3">คู่มือการใช้งาน <?= e(setting('site_name')) ?> — <?= e($manual[$tab]['label']) ?> (v<?= e(APP_VERSION) ?>)</h3>
    <div class="row g-4">
        <aside class="col-lg-3 no-print">
            <div class="card border-0 shadow-sm rounded-4 sticky-lg-top" style="top:80px">
                <div class="card-header bg-white small fw-semibold">หัวข้อ — <?= e($manual[$tab]['label']) ?></div>
                <nav class="list-group list-group-flush small">
                    <?php foreach ($manual[$tab]['sections'] as $i => $s): ?>
                        <a class="list-group-item list-group-item-action" href="#<?= $s[0] ?>"><i class="bi <?= $s[2] ?> text-vt"></i> <?= ($i + 1) . '. ' . e($s[1]) ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </aside>
        <div class="col-lg-9">
            <?php foreach ($manual[$tab]['sections'] as $i => $s): ?>
                <section class="card border-0 shadow-sm rounded-4 mb-3 manual-section" id="<?= $s[0] ?>">
                    <div class="card-body p-4">
                        <h5 class="mb-3"><span class="badge bg-vt me-1"><?= $i + 1 ?></span> <i class="bi <?= $s[2] ?> text-vt"></i> <?= e($s[1]) ?></h5>
                        <div class="manual-body"><?= $s[3] ?></div>
                    </div>
                </section>
            <?php endforeach; ?>
            <div id="manualEmpty" class="text-center text-muted py-5 d-none"><i class="bi bi-search fs-1 d-block"></i>ไม่พบหัวข้อที่ตรงกับคำค้น ลองแท็บอื่น</div>
            <p class="small text-muted mt-4 no-print">ยังหาคำตอบไม่เจอ? ติดต่อครูผู้สอนหรือผู้ดูแลระบบของสถานศึกษา</p>
        </div>
    </div>
</div>
<script>
    // ค้นหาในหัวข้อของแท็บปัจจุบัน
    document.getElementById('manualSearch').addEventListener('input', function () {
        var q = this.value.trim().toLowerCase(), shown = 0;
        document.querySelectorAll('.manual-section').forEach(function (s) {
            var hit = !q || s.textContent.toLowerCase().indexOf(q) !== -1;
            s.classList.toggle('d-none', !hit);
            if (hit) shown++;
        });
        document.getElementById('manualEmpty').classList.toggle('d-none', shown > 0);
    });
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
