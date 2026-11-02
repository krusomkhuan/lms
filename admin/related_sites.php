<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$me = require_role('admin');
$pageTitle = 'เว็บไซต์ที่เกี่ยวข้อง';
$sites = related_sites(); $error = ''; $values = array_column($sites, 0);
$values = array_combine(array_keys($sites), $values);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $submitted = post('links', []);
    if (is_array($submitted)) foreach ($sites as $key=>$site) $values[$key] = is_string($submitted[$key] ?? null) ? $submitted[$key] : '';
    try {
        if (!is_array($submitted)) throw new RuntimeException('ข้อมูลลิงก์ไม่ถูกต้อง');
        $urls = [];
        foreach ($sites as $key=>$site) $urls[$key] = related_site_url($submitted[$key] ?? null);
        db_exec('INSERT INTO settings (name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', ['related_site_urls',json_encode($urls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        flash('success', 'บันทึกลิงก์เว็บไซต์ที่เกี่ยวข้องแล้ว');
        redirect('admin/related_sites.php');
    } catch (RuntimeException $ex) {
        if ($ex instanceof mysqli_sql_exception) throw $ex;
        $error = $ex->getMessage();
    }
}
require __DIR__ . '/_top.php';
?>
<p class="text-muted">แก้ไขลิงก์ปลายทางของการ์ด “เว็บไซต์ที่เกี่ยวข้อง” บนหน้าแรก บันทึกแล้วมีผลทันที</p>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post">
    <?= csrf_field() ?>
    <div class="row g-4 mb-4">
    <?php foreach ($sites as $key=>$site): ?>
        <div class="col-md-6"><div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3"><img src="<?= url('assets/img/hubs/' . $site[1]) ?>" class="rounded" style="width:100px" alt=""><div><h5 class="mb-1"><?= e($site[2]) ?></h5><small class="text-muted"><?= e($site[3]) ?></small></div></div>
                <label class="form-label" for="link-<?= e($key) ?>">ลิงก์ <?= e($site[2]) ?></label>
                <input type="url" id="link-<?= e($key) ?>" name="links[<?= e($key) ?>]" class="form-control" required maxlength="2048" value="<?= e($values[$key]) ?>" placeholder="https://example.com/">
                <a href="<?= e($site[0]) ?>" class="small d-inline-block mt-2" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i> เปิดลิงก์ที่บันทึกไว้</a>
            </div>
        </div></div>
    <?php endforeach; ?>
    </div>
    <button class="btn btn-vt px-4"><i class="bi bi-save"></i> บันทึกลิงก์</button>
    <a href="<?= url('index.php') ?>" class="btn btn-outline-secondary" target="_blank" rel="noopener">ดูหน้าแรก</a>
</form>
<?php require __DIR__ . '/_bottom.php'; ?>
