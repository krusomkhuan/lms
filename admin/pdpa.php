<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$me = require_role('admin');
$tab = in_array($_GET['tab'] ?? '', ['requests', 'consents', 'policy'], true) ? $_GET['tab'] : 'requests';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');
    try {
        if (in_array($action, ['done', 'reject', 'delete_account'], true)) {
            $req = db_one("SELECT * FROM pdpa_requests WHERE id = ? AND status = 'pending'", [(int)post('id')]);
            if (!$req) throw new RuntimeException('ไม่พบคำร้อง หรือดำเนินการไปแล้ว');
            $note = mb_substr(post('admin_note'), 0, 2000) ?: null;

            if ($action === 'delete_account') {
                $uid = (int)$req['user_id'];
                $u = $uid ? db_one('SELECT * FROM users WHERE id = ?', [$uid]) : null;
                if ($u) {
                    if ($uid === (int)$me['id']) throw new RuntimeException('ไม่สามารถลบบัญชีของตนเองได้');
                    if ($u['role'] === 'admin' && (int)db_value("SELECT COUNT(*) FROM users WHERE role = 'admin'") <= 1) {
                        throw new RuntimeException('ไม่สามารถลบผู้ดูแลระบบคนสุดท้ายได้');
                    }
                    delete_upload($u['avatar']);
                    db_exec('DELETE FROM users WHERE id = ?', [$uid]);   // ข้อมูลที่ผูกกับผู้ใช้ถูกลบตาม (CASCADE)
                }
                $note = $note ?: 'ลบบัญชีและข้อมูลส่วนบุคคลแล้ว';
            }
            db_exec('UPDATE pdpa_requests SET status = ?, admin_note = ?, handled_by = ?, handled_at = NOW() WHERE id = ?',
                [$action === 'reject' ? 'rejected' : 'done', $note, (int)$me['id'], (int)$req['id']]);
            flash('success', $action === 'delete_account' ? "ลบบัญชี {$req['username']} และปิดคำร้องแล้ว" : 'บันทึกผลคำร้องแล้ว');
            redirect('admin/pdpa.php?tab=requests');
        }

        if ($action === 'save_policy') {
            $values = [
                'pdpa_org_name' => post('pdpa_org_name'),
                'pdpa_contact' => post('pdpa_contact'),
                'privacy_policy' => trim(post('privacy_policy')),
            ];
            if (post('new_version')) {
                $values['privacy_version'] = (string)((int)privacy_version() + 1);
                $values['privacy_updated'] = date('Y-m-d');
            }
            foreach ($values as $k => $val) {
                db_exec('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$k, $val]);
            }
            flash('success', post('new_version')
                ? 'ประกาศนโยบายเวอร์ชัน ' . $values['privacy_version'] . ' แล้ว ผู้ใช้ทุกคน (รวมถึงท่าน) ต้องยอมรับใหม่เมื่อเข้าใช้งานครั้งถัดไป'
                : 'บันทึกนโยบายแล้ว (ไม่เปลี่ยนเวอร์ชัน)');
            redirect('admin/pdpa.php?tab=policy');
        }
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('admin/pdpa.php?tab=' . $tab);
}

$reqTypes = pdpa_request_types();
$pending = (int)db_value("SELECT COUNT(*) FROM pdpa_requests WHERE status = 'pending'");
$pageTitle = 'คุ้มครองข้อมูลส่วนบุคคล (PDPA)';
require __DIR__ . '/_top.php';
?>
<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab === 'requests' ? 'active' : '' ?>" href="?tab=requests">คำร้องเจ้าของข้อมูล <?= $pending ? '<span class="badge bg-danger">' . $pending . '</span>' : '' ?></a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'consents' ? 'active' : '' ?>" href="?tab=consents">บันทึกความยินยอม</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'policy' ? 'active' : '' ?>" href="?tab=policy">นโยบายความเป็นส่วนตัว</a></li>
</ul>

<?php if ($tab === 'requests'):
    $requests = db_all("SELECT r.*, u.role, h.fullname AS handler FROM pdpa_requests r
        LEFT JOIN users u ON u.id = r.user_id LEFT JOIN users h ON h.id = r.handled_by
        ORDER BY r.status = 'pending' DESC, r.id DESC LIMIT 300"); ?>
    <div class="card border-0 shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table table-hover mb-0 small">
                <thead class="table-light"><tr><th>วันที่ยื่น</th><th>ผู้ยื่น</th><th>ประเภท / รายละเอียด</th><th>สถานะ</th><th style="min-width:300px">ดำเนินการ</th></tr></thead>
                <tbody>
                <?php foreach ($requests as $r): ?>
                    <tr>
                        <td class="text-nowrap"><?= thai_date($r['created_at'], true) ?>
                            <?php if ($r['status'] === 'pending'):
                                $days = (int)floor((time() - strtotime($r['created_at'])) / 86400); ?>
                                <div class="<?= $days >= 25 ? 'text-danger fw-semibold' : 'text-muted' ?>">ผ่านมา <?= $days ?> วัน (กำหนด 30 วัน)</div>
                            <?php endif; ?></td>
                        <td><?= e($r['fullname']) ?><div class="text-muted"><?= e($r['username']) ?> <?= $r['user_id'] ? role_badge($r['role']) : '<span class="badge bg-dark">ลบบัญชีแล้ว</span>' ?></div></td>
                        <td><b><?= e($reqTypes[$r['type']]) ?></b><div><?= nl2br(e($r['detail'] ?: '-')) ?></div></td>
                        <td>
                            <?php if ($r['status'] === 'pending'): ?><span class="badge bg-warning text-dark">รอดำเนินการ</span>
                            <?php else: ?>
                                <span class="badge bg-<?= $r['status'] === 'done' ? 'success' : 'secondary' ?>"><?= $r['status'] === 'done' ? 'ดำเนินการแล้ว' : 'ปฏิเสธ' ?></span>
                                <div class="text-muted"><?= e($r['handler'] ?? '') ?> · <?= thai_date($r['handled_at'], true) ?></div>
                                <div><?= e($r['admin_note']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['status'] === 'pending'): ?>
                                <form method="post" class="d-flex flex-column gap-1">
                                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <input name="admin_note" class="form-control form-control-sm" placeholder="หมายเหตุถึงผู้ยื่น (แสดงในหน้าข้อมูลส่วนตัว)">
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php if ($r['type'] === 'correct'): ?>
                                            <a href="<?= url('admin/user_form.php?id=' . (int)$r['user_id']) ?>" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-pencil"></i> แก้ไขข้อมูล</a>
                                        <?php endif; ?>
                                        <?php if ($r['type'] === 'delete' && $r['user_id']): ?>
                                            <button name="action" value="delete_account" class="btn btn-sm btn-danger"
                                                    onclick="return confirm('ลบบัญชี <?= e($r['username']) ?> และข้อมูลการเรียนทั้งหมดถาวร?')"><i class="bi bi-trash"></i> อนุมัติลบบัญชี</button>
                                        <?php endif; ?>
                                        <button name="action" value="done" class="btn btn-sm btn-success"><i class="bi bi-check2"></i> ดำเนินการแล้ว</button>
                                        <button name="action" value="reject" class="btn btn-sm btn-outline-secondary">ปฏิเสธ</button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$requests): ?><tr><td colspan="5" class="text-center text-muted py-5">ยังไม่มีคำร้อง</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <p class="small text-muted mt-2">กฎหมายกำหนดให้ดำเนินการตามคำร้องโดยไม่ชักช้า ไม่เกิน 30 วันนับแต่วันที่ได้รับคำขอ — หากปฏิเสธ ควรระบุเหตุผลในหมายเหตุ</p>

<?php elseif ($tab === 'consents'):
    $ver = privacy_version();
    $total = (int)db_value("SELECT COUNT(*) FROM users WHERE status = 'active'");
    $ok = (int)db_value("SELECT COUNT(*) FROM users WHERE status = 'active' AND consent_version = ?", [$ver]);
    $q = trim($_GET['q'] ?? '');
    $logs = db_all('SELECT * FROM consent_logs' . ($q !== '' ? ' WHERE username LIKE ?' : '') . ' ORDER BY id DESC LIMIT 300', $q !== '' ? ["%$q%"] : []);
    $actionLabel = ['accept' => ['ยอมรับ', 'success'], 'decline' => ['ไม่ยอมรับ', 'warning'], 'withdraw' => ['ถอนความยินยอม', 'danger']]; ?>
    <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="card stat-card shadow-sm"><div class="card-body">
            <div class="fs-3 fw-semibold text-success"><?= $ok ?> / <?= $total ?></div><div class="small text-muted">สมาชิกที่ยอมรับนโยบายเวอร์ชัน <?= e($ver) ?></div></div></div></div>
        <div class="col-md-4"><div class="card stat-card shadow-sm"><div class="card-body">
            <div class="fs-3 fw-semibold text-warning"><?= $total - $ok ?></div><div class="small text-muted">ยังไม่ยอมรับ (จะถูกขอเมื่อเข้าสู่ระบบ)</div></div></div></div>
    </div>
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <b>ประวัติความยินยอม (ล่าสุด 300 รายการ)</b>
            <form class="d-flex gap-1"><input type="hidden" name="tab" value="consents">
                <input name="q" class="form-control form-control-sm" placeholder="ชื่อผู้ใช้" value="<?= e($q) ?>"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button></form>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 small">
                <thead class="table-light"><tr><th>วันเวลา</th><th>ชื่อผู้ใช้</th><th>การดำเนินการ</th><th>นโยบาย</th><th>IP</th><th>เบราว์เซอร์</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $l): $al = $actionLabel[$l['action']]; ?>
                    <tr>
                        <td class="text-nowrap"><?= thai_date($l['created_at'], true) ?></td>
                        <td><?= e($l['username']) ?><?= $l['user_id'] ? '' : ' <span class="badge bg-dark">ลบแล้ว</span>' ?></td>
                        <td><span class="badge bg-<?= $al[1] ?>"><?= $al[0] ?></span></td>
                        <td>v<?= e($l['policy_version']) ?></td>
                        <td><?= e($l['ip']) ?></td>
                        <td class="text-truncate" style="max-width:260px" title="<?= e($l['user_agent']) ?>"><?= e($l['user_agent']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?><tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีบันทึก</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>
    <form method="post" class="card border-0 shadow-sm rounded-4">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_policy">
        <div class="card-body p-4">
            <div class="alert alert-info small">
                นโยบายปัจจุบัน: <b>เวอร์ชัน <?= e(privacy_version()) ?></b> ปรับปรุงเมื่อ <?= thai_date(setting('privacy_updated') ?: null) ?> ·
                <a href="<?= url('privacy.php') ?>" target="_blank">ดูหน้านโยบาย</a><br>
                แม่แบบเริ่มต้นเป็นเพียงตัวอย่าง สถานศึกษาควรให้เจ้าหน้าที่คุ้มครองข้อมูลส่วนบุคคล (DPO) หรือฝ่ายกฎหมายตรวจทานก่อนใช้งานจริง
            </div>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">ชื่อผู้ควบคุมข้อมูล (สถานศึกษา)</label>
                    <input name="pdpa_org_name" class="form-control" value="<?= e(setting('pdpa_org_name')) ?>" placeholder="<?= e(setting('site_name')) ?>"></div>
                <div class="col-md-6"><label class="form-label">ช่องทางติดต่อ / เจ้าหน้าที่คุ้มครองข้อมูล (DPO)</label>
                    <input name="pdpa_contact" class="form-control" value="<?= e(setting('pdpa_contact')) ?>" placeholder="เช่น งานทะเบียน โทร 0xx-xxx-xxxx อีเมล dpo@..."></div>
                <div class="col-12">
                    <label class="form-label d-flex justify-content-between">
                        <span>เนื้อหานโยบาย <span class="small text-muted">(HTML พื้นฐาน · เว้นว่าง = ใช้แม่แบบเริ่มต้น ซึ่งใส่ชื่อสถานศึกษาและช่องทางติดต่อให้อัตโนมัติ)</span></span>
                        <button type="button" class="btn btn-sm btn-link p-0" onclick="document.getElementById('pp').value = document.getElementById('tpl').value">ใส่แม่แบบเพื่อแก้ไข</button>
                    </label>
                    <textarea name="privacy_policy" id="pp" rows="16" class="form-control font-monospace small"><?= e(setting('privacy_policy')) ?></textarea>
                    <textarea id="tpl" class="d-none" disabled><?= e(default_privacy_policy()) ?></textarea>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="new_version" value="1" id="nv">
                        <label class="form-check-label" for="nv"><b>ประกาศเป็นเวอร์ชันใหม่ (<?= (int)privacy_version() + 1 ?>)</b> — ผู้ใช้ทุกคนต้องอ่านและยอมรับใหม่ ใช้เมื่อเนื้อหาเปลี่ยนสาระสำคัญ</label>
                    </div>
                </div>
            </div>
            <button class="btn btn-vt mt-3 px-4"><i class="bi bi-save"></i> บันทึก</button>
        </div>
    </form>
<?php endif; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
