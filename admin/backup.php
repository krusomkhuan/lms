<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/backup.php';

$me = require_role('admin');

// ดาวน์โหลดไฟล์สำรอง
if (isset($_GET['download'])) {
    $path = backup_file((string)$_GET['download']);
    if (!$path) {
        http_response_code(404);
        exit('ไม่พบไฟล์สำรอง');
    }
    header('Content-Type: ' . (substr($path, -4) === '.zip' ? 'application/zip' : 'application/sql'));
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        switch (post('action')) {
            case 'create':
                $name = backup_create((bool)post('with_files'));
                flash('success', "สร้างไฟล์สำรอง $name เรียบร้อย");
                break;

            case 'delete':
                $path = backup_file(post('file'));
                if ($path) {
                    unlink($path);
                    flash('success', 'ลบไฟล์สำรองแล้ว');
                }
                break;

            case 'restore':
            case 'upload_restore':
                if (post('confirm_text') !== 'กู้คืน') {
                    throw new RuntimeException('กรุณาพิมพ์คำว่า "กู้คืน" เพื่อยืนยัน');
                }
                if (post('action') === 'upload_restore') {
                    if (empty($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('กรุณาเลือกไฟล์สำรอง (.zip หรือ .sql) — ไฟล์ต้องไม่เกิน ' . ini_get('upload_max_filesize'));
                    }
                    $ext = strtolower(pathinfo($_FILES['backup']['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['zip', 'sql'], true)) {
                        throw new RuntimeException('รองรับเฉพาะไฟล์ .zip หรือ .sql');
                    }
                    $file = 'uploaded_' . date('Ymd_His') . '.' . $ext;
                    move_uploaded_file($_FILES['backup']['tmp_name'], backup_dir() . '/' . $file);
                } else {
                    $file = post('file');
                }
                $path = backup_file($file);
                if (!$path) {
                    throw new RuntimeException('ไม่พบไฟล์สำรอง');
                }
                $meta = backup_meta($path);
                if (!$meta['has_sql']) {
                    throw new RuntimeException('ไฟล์นี้ไม่มีข้อมูลฐานข้อมูล (database.sql)');
                }
                // สำรองสถานะปัจจุบันอัตโนมัติก่อนกู้คืน เผื่อต้องย้อนกลับ
                $safety = backup_create(true, 'auto');
                $msg = backup_restore($path, (bool)post('restore_files'));
                flash('success', "$msg จากไฟล์ $file (ระบบสำรองข้อมูลก่อนกู้คืนไว้ที่ $safety)");
                if (!empty($meta['app_version']) && $meta['app_version'] !== APP_VERSION) {
                    flash('warning', "ไฟล์สำรองมาจากเวอร์ชัน {$meta['app_version']} แต่ระบบปัจจุบันคือ " . APP_VERSION
                        . ' หากใช้งานผิดปกติให้รันไฟล์ใน database/migrations ที่ใหม่กว่าเวอร์ชันของไฟล์สำรองตามลำดับ');
                }
                break;
        }
    } catch (Throwable $ex) {
        flash('danger', 'ดำเนินการไม่สำเร็จ: ' . $ex->getMessage());
    }
    redirect('admin/backup.php');
}

$backups = backup_list();
$typeLabel = ['manual' => ['สำรองเอง', 'primary'], 'auto' => ['อัตโนมัติก่อนกู้คืน', 'secondary'], 'uploaded' => ['อัปโหลด', 'info']];
$pageTitle = 'สำรอง / กู้คืนข้อมูล';
require __DIR__ . '/_top.php';
?>
<div class="row g-4">
    <div class="col-lg-5">
        <form method="post" class="card border-0 shadow-sm rounded-4">
            <?= csrf_field() ?><input type="hidden" name="action" value="create">
            <div class="card-header bg-white"><b><i class="bi bi-cloud-arrow-down text-success"></i> สำรองข้อมูล</b></div>
            <div class="card-body">
                <p class="small text-muted mb-2">บันทึกฐานข้อมูลทั้งหมด (สมาชิก รายวิชา บทเรียน กลุ่ม ความก้าวหน้า การตั้งค่า) เป็นไฟล์ .zip</p>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="with_files" value="1" id="wf" checked>
                    <label class="form-check-label" for="wf">รวมไฟล์อัปโหลด (สื่อการสอน ภาพปก รูปโปรไฟล์)</label>
                </div>
                <button class="btn btn-success w-100"><i class="bi bi-download"></i> สร้างไฟล์สำรองตอนนี้</button>
                <div class="form-text mt-2">แนะนำให้ดาวน์โหลดไฟล์สำรองเก็บไว้นอกเซิร์ฟเวอร์ด้วย (เช่น Google Drive / Flash drive)</div>
            </div>
        </form>

        <form method="post" enctype="multipart/form-data" class="card border-0 shadow-sm rounded-4 mt-4 border-danger"
              data-confirm="ข้อมูลปัจจุบันทั้งหมดจะถูกแทนที่ด้วยข้อมูลในไฟล์สำรอง ยืนยันกู้คืน?">
            <?= csrf_field() ?><input type="hidden" name="action" value="upload_restore">
            <div class="card-header bg-white"><b><i class="bi bi-cloud-arrow-up text-danger"></i> กู้คืนจากไฟล์ในเครื่อง</b></div>
            <div class="card-body">
                <input type="file" name="backup" accept=".zip,.sql" class="form-control mb-2" required>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="restore_files" value="1" id="rf" checked>
                    <label class="form-check-label" for="rf">กู้คืนไฟล์อัปโหลดด้วย (ถ้ามีในไฟล์สำรอง)</label>
                </div>
                <input name="confirm_text" class="form-control mb-2" placeholder='พิมพ์คำว่า "กู้คืน" เพื่อยืนยัน' required autocomplete="off">
                <button class="btn btn-outline-danger w-100"><i class="bi bi-arrow-counterclockwise"></i> อัปโหลดและกู้คืน</button>
                <div class="form-text mt-2">ขนาดไฟล์สูงสุด <?= e(ini_get('upload_max_filesize')) ?> · ระบบจะสำรองข้อมูลปัจจุบันให้อัตโนมัติก่อนกู้คืนทุกครั้ง</div>
            </div>
        </form>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white d-flex justify-content-between">
                <b>ไฟล์สำรองบนเซิร์ฟเวอร์ (<?= count($backups) ?>)</b>
                <span class="small text-muted"><?= e(format_bytes(array_sum(array_column($backups, 'size')))) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 small">
                    <thead class="table-light"><tr><th>วันที่</th><th>ข้อมูล</th><th class="text-end">ขนาด</th><th class="text-end">จัดการ</th></tr></thead>
                    <tbody>
                    <?php foreach ($backups as $b): $m = $b['meta'];
                        $type = $m['type'] ?? (strpos($b['name'], 'uploaded_') === 0 ? 'uploaded' : 'manual');
                        $tl = $typeLabel[$type] ?? $typeLabel['manual']; ?>
                        <tr>
                            <td class="text-nowrap"><?= thai_date(date('Y-m-d H:i:s', $b['time']), true) ?>
                                <div class="text-muted" style="font-size:.75rem"><?= e($b['name']) ?></div></td>
                            <td>
                                <span class="badge bg-<?= $tl[1] ?>"><?= $tl[0] ?></span>
                                <?php if (!empty($m['app_version'])): ?><span class="badge bg-light text-dark border">v<?= e($m['app_version']) ?></span><?php endif; ?>
                                <div class="text-muted mt-1"><i class="bi bi-database"></i> ฐานข้อมูล
                                    <?php if (!empty($m['with_files'])): ?> + <i class="bi bi-folder"></i> ไฟล์ <?= (int)($m['file_count'] ?? 0) ?><?php endif; ?></div>
                            </td>
                            <td class="text-end text-nowrap"><?= e(format_bytes($b['size'])) ?></td>
                            <td class="text-end text-nowrap">
                                <a href="?download=<?= urlencode($b['name']) ?>" class="btn btn-sm btn-outline-success" title="ดาวน์โหลด"><i class="bi bi-download"></i></a>
                                <button type="button" class="btn btn-sm btn-outline-danger" title="กู้คืน" data-bs-toggle="modal" data-bs-target="#restoreModal" data-file="<?= e($b['name']) ?>"><i class="bi bi-arrow-counterclockwise"></i></button>
                                <form method="post" class="d-inline" data-confirm="ลบไฟล์สำรองนี้?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="file" value="<?= e($b['name']) ?>">
                                    <button class="btn btn-sm btn-outline-secondary" title="ลบ"><i class="bi bi-trash"></i></button></form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$backups): ?><tr><td colspan="4" class="text-center text-muted py-5">ยังไม่มีไฟล์สำรอง</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="restoreModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content" data-confirm="ข้อมูลปัจจุบันทั้งหมดจะถูกแทนที่ ยืนยันกู้คืน?">
            <?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="file" id="restoreFile">
            <div class="modal-header"><h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle"></i> กู้คืนข้อมูล</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p>กู้คืนจากไฟล์ <b id="restoreFileLabel"></b><br><span class="small text-danger">ข้อมูลปัจจุบันทั้งหมดจะถูกแทนที่ด้วยข้อมูลในไฟล์นี้ (ระบบจะสำรองข้อมูลปัจจุบันให้ก่อนอัตโนมัติ)</span></p>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="restore_files" value="1" id="rf2" checked>
                    <label class="form-check-label" for="rf2">กู้คืนไฟล์อัปโหลดด้วย</label>
                </div>
                <input name="confirm_text" class="form-control" placeholder='พิมพ์คำว่า "กู้คืน" เพื่อยืนยัน' required autocomplete="off">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
                <button class="btn btn-danger"><i class="bi bi-arrow-counterclockwise"></i> กู้คืน</button>
            </div>
        </form>
    </div>
</div>
<script>
    document.getElementById('restoreModal').addEventListener('show.bs.modal', function (ev) {
        var f = ev.relatedTarget.getAttribute('data-file');
        document.getElementById('restoreFile').value = f;
        document.getElementById('restoreFileLabel').textContent = f;
    });
</script>
<?php require __DIR__ . '/_bottom.php'; ?>
