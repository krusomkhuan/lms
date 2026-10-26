<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/ai.php';

$me = require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (post('action') === 'test') {
        $r = ai_run($me, 'test', null, 'คุณคือระบบทดสอบการเชื่อมต่อ ตอบสั้นที่สุด', [['role' => 'user', 'content' => 'ตอบว่า "เชื่อมต่อสำเร็จ" เป็นภาษาไทย']], 'low', 1000);
        flash($r['status'] === 'ok' ? 'success' : 'danger', $r['status'] === 'ok'
            ? 'ทดสอบสำเร็จ — ' . ai_model() . ' ตอบว่า: ' . trim(strip_tags($r['text']))
            : 'ทดสอบไม่สำเร็จ: ' . $r['error']);
        redirect('admin/ai_settings.php');
    }

    $values = [
        'ai_enabled' => post('ai_enabled') ? '1' : '0',
        'ai_model' => array_key_exists(post('ai_model'), ai_models()) ? post('ai_model') : 'claude-opus-5-5',
        'ai_teacher_enabled' => post('ai_teacher_enabled') ? '1' : '0',
        'ai_student_enabled' => post('ai_student_enabled') ? '1' : '0',
        'ai_teacher_daily_limit' => (string)max(0, min(1000, (int)post('ai_teacher_daily_limit'))),
        'ai_student_daily_limit' => (string)max(0, min(1000, (int)post('ai_student_daily_limit'))),
    ];
    // API key: เว้นว่าง = ใช้ค่าเดิม, ติ๊กลบ = ลบ
    if (post('clear_key')) {
        $values['ai_api_key'] = '';
    } elseif (post('ai_api_key') !== '') {
        $values['ai_api_key'] = post('ai_api_key');
    }
    foreach ($values as $k => $val) {
        db_exec('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$k, $val]);
    }
    flash('success', 'บันทึกการตั้งค่า AI แล้ว');
    redirect('admin/ai_settings.php');
}

$key = trim(setting('ai_api_key'));
$checks = [
    ['PHP 8.1 ขึ้นไป', PHP_VERSION_ID >= 80100, 'ปัจจุบัน PHP ' . PHP_VERSION],
    ['ติดตั้ง Anthropic PHP SDK', is_file(ROOT_PATH . '/vendor/autoload.php'), 'รัน composer install ในโฟลเดอร์เว็บ'],
    ['มี Claude API key', $key !== '', 'ใส่ API key ด้านล่าง'],
    ['เปิดใช้งานโมดูล AI', setting('ai_enabled') === '1', 'ติ๊ก "เปิดใช้งาน" ด้านล่าง'],
];
$ready = ai_unavailable_reason() === null;
$models = ai_models();

$usage = db_all("SELECT model, role, feature, COUNT(*) n, SUM(status='ok') ok, SUM(input_tokens) tin, SUM(output_tokens) tout, SUM(cache_read_tokens) tcache
                 FROM ai_logs WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY model, role, feature ORDER BY n DESC");
$cost = 0.0;
foreach ($usage as &$u) {
    $p = $models[$u['model']] ?? ['in' => 0, 'out' => 0];
    $u['cost'] = ($u['tin'] * $p['in'] + $u['tout'] * $p['out']) / 1e6;
    $cost += $u['cost'];
}
unset($u);
$featureLabel = ['lesson' => 'ร่างบทเรียน', 'exercise' => 'แบบฝึกหัด', 'quiz' => 'ข้อสอบ', 'rubric' => 'Rubric', 'summary' => 'สรุปผลการเรียน',
    'improve' => 'แนวทางปรับปรุงการสอน', 'learn_summary' => 'ผู้เรียน: สรุปบทเรียน', 'learn_explain' => 'ผู้เรียน: อธิบาย', 'learn_hint' => 'ผู้เรียน: คำใบ้',
    'learn_ask' => 'ผู้เรียน: ถามคำถาม', 'test' => 'ทดสอบการเชื่อมต่อ'];

$pageTitle = 'ตั้งค่าโมดูล AI';
require __DIR__ . '/_top.php';
?>
<div class="row g-4">
    <div class="col-xl-7">
        <form method="post" class="card border-0 shadow-sm rounded-4">
            <?= csrf_field() ?>
            <div class="card-body p-4">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="ai_enabled" value="1" id="aiOn" <?= setting('ai_enabled') === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="aiOn">เปิดใช้งานโมดูล AI (Claude)</label>
                </div>
                <div class="alert alert-info small py-2">
                    <i class="bi bi-shield-lock"></i> PDPA: ผู้ช่วย AI ส่งคำถามและเนื้อหาบทเรียนไปประมวลผลที่ Anthropic (สหรัฐอเมริกา) — แม่แบบนโยบายความเป็นส่วนตัวมีหัวข้อนี้แล้ว
                    เมื่อเปิดใช้ครั้งแรก แนะนำให้<a href="<?= url('admin/pdpa.php?tab=policy') ?>">ประกาศนโยบายเวอร์ชันใหม่</a> เพื่อให้ผู้ใช้รับทราบและยอมรับ
                </div>

                <label class="form-label">Claude API key</label>
                <input name="ai_api_key" type="password" class="form-control" autocomplete="off"
                       placeholder="<?= $key !== '' ? 'ตั้งค่าแล้ว (ลงท้าย …' . e(substr($key, -4)) . ') — เว้นว่างเพื่อใช้ค่าเดิม' : 'sk-ant-...' ?>">
                <div class="form-text mb-1">สร้างได้ที่ console.anthropic.com → API Keys · เก็บในฐานข้อมูลของระบบ ไม่แสดงกลับ และ<b>ไม่รวมในไฟล์สำรองข้อมูล</b></div>
                <?php if ($key !== ''): ?>
                    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="clear_key" value="1" id="ck"><label class="form-check-label small" for="ck">ลบ API key</label></div>
                <?php endif; ?>

                <label class="form-label mt-2">โมเดล</label>
                <select name="ai_model" class="form-select mb-1">
                    <?php foreach ($models as $id => $m): ?>
                        <option value="<?= $id ?>" <?= ai_model() === $id ? 'selected' : '' ?>><?= e($m['label']) ?> · $<?= $m['in'] ?> / $<?= $m['out'] ?> ต่อ 1M token</option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mb-3">ราคา input / output (USD) ต่อ 1 ล้าน token · Opus/Sonnet เปิดโมเดลสำรองอัตโนมัติเมื่อระบบความปลอดภัยปฏิเสธคำขอ</div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="ai_teacher_enabled" value="1" id="te" <?= setting('ai_teacher_enabled', '1') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="te"><b>AI Teaching Assistant</b> (ครู)</label></div>
                        <label class="form-label small mt-2">จำนวนครั้ง/คน/วัน</label>
                        <input name="ai_teacher_daily_limit" type="number" min="0" max="1000" class="form-control form-control-sm" value="<?= e(setting('ai_teacher_daily_limit', '50')) ?>">
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="ai_student_enabled" value="1" id="se" <?= setting('ai_student_enabled', '1') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="se"><b>AI Learning Assistant</b> (ผู้เรียน)</label></div>
                        <label class="form-label small mt-2">จำนวนครั้ง/คน/วัน</label>
                        <input name="ai_student_daily_limit" type="number" min="0" max="1000" class="form-control form-control-sm" value="<?= e(setting('ai_student_daily_limit', '30')) ?>">
                    </div>
                </div>
                <div class="form-text">ครูปิดผู้ช่วยของผู้เรียนเป็นรายวิชาได้ (เช่น ช่วงสอบ) ที่หน้าแก้ไขรายวิชา</div>
                <button class="btn btn-vt mt-4 px-4"><i class="bi bi-save"></i> บันทึก</button>
            </div>
        </form>
    </div>

    <div class="col-xl-5">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white"><b>ความพร้อมของระบบ</b></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($checks as $c): ?>
                    <li class="list-group-item d-flex gap-2">
                        <i class="bi <?= $c[1] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>"></i>
                        <div><?= e($c[0]) ?><?= $c[1] ? '' : '<div class="small text-muted">' . e($c[2]) . '</div>' ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="card-body">
                <?php if ($ready): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test">
                        <button class="btn btn-outline-primary w-100"><i class="bi bi-plug"></i> ทดสอบการเชื่อมต่อ</button></form>
                <?php else: ?>
                    <div class="small text-danger"><?= e(ai_unavailable_reason()) ?></div>
                    <div class="small text-muted mt-1">ดูขั้นตอนติดตั้งใน README.md หัวข้อ "โมดูล AI"</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mt-4">
            <div class="card-header bg-white d-flex justify-content-between"><b>การใช้งาน 30 วันล่าสุด</b><span class="small">ประมาณ <b>$<?= number_format($cost, 2) ?></b></span></div>
            <div class="table-responsive">
                <table class="table table-sm small mb-0">
                    <thead class="table-light"><tr><th>การใช้งาน</th><th class="text-end">ครั้ง</th><th class="text-end">token เข้า/ออก</th><th class="text-end">USD</th></tr></thead>
                    <tbody>
                    <?php foreach ($usage as $u): ?>
                        <tr><td><?= e($featureLabel[$u['feature']] ?? $u['feature']) ?><div class="text-muted"><?= e($u['model']) ?></div></td>
                            <td class="text-end"><?= (int)$u['ok'] ?>/<?= (int)$u['n'] ?></td>
                            <td class="text-end"><?= number_format($u['tin']) ?> / <?= number_format($u['tout']) ?></td>
                            <td class="text-end"><?= number_format($u['cost'], 3) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$usage): ?><tr><td colspan="4" class="text-center text-muted py-3">ยังไม่มีการใช้งาน</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-body small text-muted pt-2">ค่าใช้จ่ายเป็นค่าประมาณจากราคาปกติ (ไม่หักส่วนลดจากแคช) ยอดจริงดูที่ console.anthropic.com · ระบบไม่เก็บข้อความคำถาม/คำตอบของผู้เรียน</div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
