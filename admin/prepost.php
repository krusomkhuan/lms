<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$me = require_role('admin', 'teacher');
$courseId = get_int('course_id');
$course = db_one('SELECT * FROM courses WHERE id = ?', [$courseId]);
if (!$course || !can_manage_course($courseId)) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ดูรายวิชานี้');
}
$policies = ['first' => 'ครั้งแรก', 'highest' => 'สูงสุด', 'latest' => 'ล่าสุด'];
$postPolicy = isset($policies[$_GET['policy'] ?? '']) ? $_GET['policy'] : 'first';
$groups = grade_groups($courseId);
$groupId = get_int('group_id');
$roster = grade_roster($courseId, $groupId);
$pairs = quiz_ready() ? prepost_pairs($courseId) : [];
foreach ($pairs as &$pair) {
    // ก่อนเรียนใช้คะแนนครั้งแรกเสมอ (วัดพื้นฐานก่อนเรียน)
    $pair['pre_scores'] = quiz_scores_by_user($pair['pre_key'], 'first');
    $pair['post_scores'] = quiz_scores_by_user($pair['post_key'], $postPolicy);
    $inRoster = array_flip(array_column($roster, 'id'));
    $pair['stats'] = prepost_stats(array_intersect_key($pair['pre_scores'], $inRoster), array_intersect_key($pair['post_scores'], $inRoster));
}
unset($pair);
$pct = fn($s) => $s ? $s['score'] . '/' . $s['total'] . ' (' . $s['percent'] . '%)' : '—';

if (($_GET['export'] ?? '') === 'csv' && isset($pairs[get_int('pair')])) {
    $pair = $pairs[get_int('pair')];
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="prepost-' . $courseId . '-' . get_int('pair') . '.csv"'); header('Cache-Control: private, no-store');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['รหัสนักเรียน', 'ชื่อ-นามสกุล', 'กลุ่มเรียน', 'ก่อนเรียน (คะแนน)', 'ก่อนเรียน (เต็ม)', 'ก่อนเรียน (%)', 'หลังเรียน (คะแนน)', 'หลังเรียน (เต็ม)', 'หลังเรียน (%)', 'ผลต่าง (%)'], ',', '"', '');
    foreach ($roster as $s) {
        $a = $pair['pre_scores'][$s['id']] ?? null; $b = $pair['post_scores'][$s['id']] ?? null;
        fputcsv($out, [$s['student_code'], $s['fullname'], $s['group_names'], $a['score'] ?? '', $a['total'] ?? '', $a['percent'] ?? '', $b['score'] ?? '', $b['total'] ?? '', $b['percent'] ?? '', $a && $b ? round($b['percent'] - $a['percent'], 2) : ''], ',', '"', '');
    }
    exit;
}

$pageTitle = 'ผลก่อนเรียน–หลังเรียน: ' . $course['code'] . ' ' . $course['title'];
require __DIR__ . '/_top.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <a href="<?= url('admin/lessons.php?course_id=' . $courseId) ?>"><i class="bi bi-arrow-left"></i> กลับไปบทเรียน</a>
    <a href="<?= url('admin/question_bank.php?course_id=' . $courseId) ?>" class="btn btn-outline-primary"><i class="bi bi-archive"></i> คลังข้อสอบ</a>
</div>
<form class="row g-2 align-items-end mb-3">
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
    <div class="col-md-5"><label class="form-label w-100">กลุ่มเรียน<select name="group_id" class="form-select"><option value="0">ผู้เรียนทั้งหมด</option><?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= $groupId === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?></select></label></div>
    <div class="col-md-4"><label class="form-label w-100">คะแนนหลังเรียนที่ใช้<select name="policy" class="form-select"><?php foreach ($policies as $k => $label): ?><option value="<?= $k ?>" <?= $postPolicy === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label></div>
    <div class="col-md-3"><button class="btn btn-outline-secondary w-100">แสดง</button></div>
</form>
<p class="small text-muted">คะแนนก่อนเรียนใช้ครั้งแรกที่ทำเสมอ · สถิติคิดเฉพาะนักเรียนที่ทำครบทั้งก่อนและหลังเรียน · ค่า t คือ t-test แบบกลุ่มตัวอย่างไม่อิสระ (df = n − 1)</p>

<?php if (!$pairs): ?>
    <div class="alert alert-info">ยังไม่มีคู่แบบทดสอบก่อน–หลังเรียน — ในหน้าแก้ไขบทเรียน สร้างแบบทดสอบประเภท "ก่อนเรียน" และ "หลังเรียน" แล้วเลือกคู่ที่แบบทดสอบหลังเรียน</div>
<?php endif; ?>
<?php foreach ($pairs as $i => $pair): $st = $pair['stats']; ?>
<div class="card border-0 shadow-sm rounded-4 mb-4"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between gap-2">
        <h2 class="h6 mb-3"><?= e($pair['pre']['title']) ?> <i class="bi bi-arrow-right"></i> <?= e($pair['post']['title']) ?></h2>
        <a class="btn btn-sm btn-outline-success" href="?<?= e(http_build_query(['course_id' => $courseId, 'group_id' => $groupId, 'policy' => $postPolicy, 'pair' => $i, 'export' => 'csv'])) ?>"><i class="bi bi-download"></i> CSV</a>
    </div>
    <?php if ($st['n']): ?>
    <div class="row g-2 text-center mb-3">
        <?php foreach ([['ทำครบ', $st['n'] . ' คน'], ['ก่อนเรียนเฉลี่ย', $st['pre'] . '%'], ['หลังเรียนเฉลี่ย', $st['post'] . '%'], ['พัฒนาขึ้นเฉลี่ย', ($st['diff'] > 0 ? '+' : '') . $st['diff'] . '%'], ['คะแนนดีขึ้น', $st['improved'] . ' คน'], ['t (df=' . ($st['n'] - 1) . ')', $st['t'] ?? '—']] as [$label, $value]): ?>
            <div class="col-6 col-md-2"><div class="border rounded-3 p-2"><div class="small text-muted"><?= $label ?></div><div class="fw-semibold"><?= e((string)$value) ?></div></div></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="table-responsive"><table class="table table-sm table-striped mb-0">
        <thead><tr><th>รหัส</th><th>ชื่อ-นามสกุล</th><th>ก่อนเรียน</th><th>หลังเรียน</th><th>ผลต่าง</th></tr></thead>
        <tbody>
        <?php foreach ($roster as $s): $a = $pair['pre_scores'][$s['id']] ?? null; $b = $pair['post_scores'][$s['id']] ?? null; $d = $a && $b ? round($b['percent'] - $a['percent'], 2) : null; ?>
            <tr><td><?= e($s['student_code']) ?></td><td><?= e($s['fullname']) ?></td><td><?= e($pct($a)) ?></td><td><?= e($pct($b)) ?><?= $b && $b['attempts'] > 1 ? ' <span class="small text-muted">(' . $b['attempts'] . ' ครั้ง)</span>' : '' ?></td>
                <td class="<?= $d === null ? '' : ($d > 0 ? 'text-success' : ($d < 0 ? 'text-danger' : '')) ?>"><?= $d === null ? '—' : ($d > 0 ? '+' : '') . $d . '%' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$roster): ?><tr><td colspan="5" class="text-muted">ยังไม่มีผู้เรียน</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div></div>
<?php endforeach; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
