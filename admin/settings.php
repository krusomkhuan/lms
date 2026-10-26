<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$me = require_role('admin');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $siteUrl = rtrim(post('site_url'), '/');
    $parts = $siteUrl !== '' ? parse_url($siteUrl) : [];
    if ($siteUrl !== '' && (!filter_var($siteUrl, FILTER_VALIDATE_URL) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']))) {
        $error = 'URL เว็บไซต์ไม่ถูกต้อง ต้องขึ้นต้นด้วย http:// หรือ https:// และไม่มี ? หรือ # เช่น https://www.example.ac.th/lms';
    } else {
        $values = [
            'site_name' => post('site_name') ?: 'Vocational Teacher',
            'site_slogan' => post('site_slogan') ?: 'ครูสมควรออนไลน์',
            'allow_register' => post('allow_register') ? '1' : '0',
            'require_approval' => post('require_approval') ? '1' : '0',
        ];
        if (!BASE_URL_LOCKED) {
            $values['site_url'] = $siteUrl;
        }
        foreach ($values as $k => $val) {
            db_exec('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$k, $val]);
        }
        flash('success', 'บันทึกการตั้งค่าแล้ว');
        // ไปยังหน้าตั้งค่าที่ URL ใหม่ทันที เพื่อให้เห็นผลว่าใช้งานได้
        $newBase = BASE_URL_LOCKED ? BASE_URL : ($siteUrl !== '' ? $siteUrl : detect_base_url());
        redirect($newBase . '/admin/settings.php');
    }
}

$v = fn($k) => $_SERVER['REQUEST_METHOD'] === 'POST' ? post($k) : setting($k);
$pageTitle = 'ตั้งค่าระบบ';
require __DIR__ . '/_top.php';
?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="card border-0 shadow-sm rounded-4" style="max-width:720px">
    <?= csrf_field() ?>
    <div class="card-body p-4">
        <h6 class="text-vt mb-3"><i class="bi bi-globe"></i> เว็บไซต์</h6>
        <div class="mb-3">
            <label class="form-label">URL เว็บไซต์</label>
            <?php if (BASE_URL_LOCKED): ?>
                <input class="form-control" value="<?= e(BASE_URL) ?>" disabled>
                <div class="form-text text-warning-emphasis"><i class="bi bi-lock"></i> ถูกกำหนดไว้ใน <code>config/config.php</code> (BASE_URL) — ลบหรือปิดบรรทัดนั้นเพื่อกลับมาตั้งค่าที่นี่</div>
            <?php else: ?>
                <input name="site_url" class="form-control" value="<?= e($v('site_url')) ?>" placeholder="<?= e(detected_full_url()) ?>">
                <div class="form-text">
                    เว้นว่าง = ตรวจหาอัตโนมัติ (แนะนำ) ขณะนี้ตรวจพบ: <code><?= e(detected_full_url()) ?></code><br>
                    กำหนดเองเมื่อใช้โดเมนจริง เช่น <code>https://www.example.ac.th/lms</code> (ไม่ต้องมี / ปิดท้าย)<br>
                    <span class="text-danger-emphasis">หากตั้งผิดจนเข้าเว็บไม่ได้ ให้เปิดบรรทัด <code>define('BASE_URL', ...)</code> ใน config/config.php ชั่วคราว</span>
                </div>
            <?php endif; ?>
            <div class="small mt-1">URL ที่ใช้งานอยู่: <a href="<?= e(url('index.php')) ?>" target="_blank"><?= e(BASE_URL ?: '/') ?></a></div>
        </div>
        <div class="mb-3"><label class="form-label">ชื่อเว็บไซต์</label><input name="site_name" class="form-control" value="<?= e($v('site_name')) ?>"></div>
        <div class="mb-4"><label class="form-label">สโลแกน</label><input name="site_slogan" class="form-control" value="<?= e($v('site_slogan')) ?>"></div>

        <h6 class="text-vt mb-3"><i class="bi bi-person-plus"></i> การสมัครสมาชิก</h6>
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="allow_register" value="1" id="reg" <?= $v('allow_register') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label" for="reg">เปิดให้ผู้เรียนสมัครสมาชิกเอง</label>
        </div>
        <div class="form-check form-switch mb-4">
            <input class="form-check-input" type="checkbox" name="require_approval" value="1" id="appr" <?= $v('require_approval') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label" for="appr">สมาชิกใหม่ต้องรอผู้ดูแลระบบอนุมัติก่อนใช้งาน</label>
        </div>
        <button class="btn btn-vt px-4"><i class="bi bi-save"></i> บันทึก</button>
    </div>
</form>
<?php require __DIR__ . '/_bottom.php'; ?>
