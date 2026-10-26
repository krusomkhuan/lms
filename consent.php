<?php
require_once __DIR__ . '/includes/bootstrap.php';

$me = require_login();
$next = $_GET['next'] ?? $_POST['next'] ?? '';
if (!preg_match('#^' . preg_quote(BASE_PATH, '#') . '/[^/\\\\]#', $next) || strpos($next, 'consent.php') !== false) $next = '';
$isUpdate = !empty($me['consent_version']) || db_value("SELECT 1 FROM consent_logs WHERE user_id = ? AND action = 'accept'", [(int)$me['id']]);

if ((string)$me['consent_version'] === privacy_version() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($next ?: 'index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (post('action') === 'accept') {
        if (!post('agree')) {
            flash('warning', 'กรุณาทำเครื่องหมายยอมรับนโยบายความเป็นส่วนตัว');
            redirect('consent.php?next=' . urlencode($next));
        }
        record_consent($me, 'accept');
        flash('success', 'ขอบคุณที่ยอมรับนโยบายความเป็นส่วนตัว');
        redirect($next ?: ($me['role'] === 'student' ? 'my-courses.php' : 'admin/index.php'));
    }
    // ไม่ยอมรับ: บันทึก แล้วออกจากระบบ (และยื่นคำขอลบบัญชีถ้าเลือก)
    record_consent($me, 'decline');
    $msg = 'ท่านไม่ได้ยอมรับนโยบายความเป็นส่วนตัว จึงยังใช้งานระบบไม่ได้';
    if (post('request_delete')) {
        db_exec("INSERT INTO pdpa_requests (user_id, username, fullname, type, detail) VALUES (?,?,?,'delete',?)",
            [(int)$me['id'], $me['username'], $me['fullname'], 'ไม่ยอมรับนโยบายความเป็นส่วนตัว และขอลบบัญชี (ยื่นจากหน้ายอมรับนโยบาย)']);
        $msg .= ' — ส่งคำขอลบบัญชีให้ผู้ดูแลระบบแล้ว';
    }
    $_SESSION = [];
    session_regenerate_id(true);
    flash('info', $msg);
    redirect('login.php');
}

$pageTitle = 'ยอมรับนโยบายความเป็นส่วนตัว';
require __DIR__ . '/includes/header.php';
?>
<div class="container py-4" style="max-width:880px">
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <h3 class="mb-1"><i class="bi bi-shield-check text-vt"></i> นโยบายความเป็นส่วนตัว</h3>
            <p class="text-muted">
                <?= $isUpdate ? 'นโยบายความเป็นส่วนตัวมีการปรับปรุง กรุณาอ่านและยอมรับอีกครั้งเพื่อใช้งานต่อ' : 'กรุณาอ่านและยอมรับนโยบายความเป็นส่วนตัวก่อนเริ่มใช้งานระบบ' ?>
                (เวอร์ชัน <?= e(privacy_version()) ?>)
            </p>
            <div class="border rounded-3 p-3 bg-light lesson-content" style="max-height:50vh;overflow-y:auto"><?= privacy_policy_html() ?></div>

            <form method="post" class="mt-4">
                <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="agree" value="1" id="agree">
                    <label class="form-check-label" for="agree">ข้าพเจ้าได้อ่านและยอมรับนโยบายความเป็นส่วนตัว และยินยอมให้เก็บรวบรวม ใช้ และเปิดเผยข้อมูลส่วนบุคคลตามที่ระบุไว้</label>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button name="action" value="accept" class="btn btn-vt px-4"><i class="bi bi-check2"></i> ยอมรับและใช้งานต่อ</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#declineBox">ไม่ยอมรับ</button>
                </div>
            </form>

            <form method="post" class="collapse mt-3" id="declineBox" onsubmit="return confirm('ยืนยันไม่ยอมรับนโยบาย? ท่านจะถูกออกจากระบบ')">
                <?= csrf_field() ?><input type="hidden" name="action" value="decline">
                <div class="alert alert-warning mb-2 small">หากไม่ยอมรับ ท่านจะไม่สามารถใช้งานระบบได้ และจะถูกออกจากระบบ</div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="request_delete" value="1" id="rd">
                    <label class="form-check-label" for="rd">ขอให้ลบบัญชีและข้อมูลส่วนบุคคลของข้าพเจ้าด้วย</label>
                </div>
                <button class="btn btn-sm btn-outline-danger">ยืนยันไม่ยอมรับ และออกจากระบบ</button>
            </form>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
