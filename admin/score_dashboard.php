<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$me = require_role('admin', 'teacher');
$courseId = get_int('course_id');
if (!$courseId) {
    $courses = staff_courses($me);
    if (count($courses) === 1) redirect('admin/score_dashboard.php?course_id=' . $courses[0]['id']);
    $pageTitle = 'แดชบอร์ดคะแนน';
    require __DIR__ . '/_top.php';
    ?>
    <p class="text-muted">เลือกรายวิชาเพื่อดูภาพรวมคะแนนและความก้าวหน้าของผู้เรียน</p>
    <div class="row g-3">
        <?php foreach ($courses as $c): ?>
            <div class="col-md-6 col-xl-4"><a class="card border-0 shadow-sm rounded-4 h-100 text-reset" href="<?= url('admin/score_dashboard.php?course_id=' . $c['id']) ?>"><div class="card-body">
                <span class="badge bg-light text-vt border"><?= e($c['code']) ?></span>
                <div class="fw-semibold mt-1"><?= e($c['title']) ?></div>
                <div class="small text-muted mt-2"><i class="bi bi-bar-chart"></i> ดูแดชบอร์ด</div>
            </div></a></div>
        <?php endforeach; ?>
        <?php if (!$courses): ?><div class="col-12 text-center text-muted py-5">ยังไม่มีรายวิชาที่คุณสอน</div><?php endif; ?>
    </div>
    <?php
    require __DIR__ . '/_bottom.php';
    exit;
}
$course = db_one('SELECT * FROM courses WHERE id = ?', [$courseId]);
if (!$course || !can_manage_course($courseId)) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ดูรายวิชานี้');
}

$groups = grade_groups($courseId);
$groupId = get_int('group_id');
$sort = ($_GET['sort'] ?? '') === 'avg' ? 'avg' : 'code';
$roster = grade_roster($courseId, $groupId);
$ids = array_map('intval', array_column($roster, 'id'));
$items = progress_items($courseId);
$results = progress_results($courseId);
$lessons = db_all('SELECT id, title FROM lessons WHERE course_id = ? AND is_published = 1 ORDER BY sort_order, id', [$courseId]);
$lessonDone = [];
$doneByUser = [];
if ($ids && $lessons) {
    $in = implode(',', $ids);
    foreach (db_all("SELECT lp.lesson_id, lp.user_id FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id WHERE l.course_id = ? AND l.is_published = 1 AND lp.user_id IN ($in)", [$courseId]) as $p) {
        $lessonDone[$p['lesson_id']] = ($lessonDone[$p['lesson_id']] ?? 0) + 1;
        $doneByUser[$p['user_id']] = ($doneByUser[$p['user_id']] ?? 0) + 1;
    }
}

// รายคน
$students = [];
foreach ($roster as $s) {
    $r = $results[$s['id']] ?? [];
    $summary = progress_summary($items, $r);
    $students[] = $s + ['results' => $r, 'avg' => $summary['average'], 'lessons' => count($lessons) ? round(($doneByUser[$s['id']] ?? 0) * 100 / count($lessons)) : 0,
        'missing' => count(array_diff_key($items, $r)), 'pending' => $summary['assignment_pending']];
}
if ($sort === 'avg') usort($students, fn($a, $b) => ($a['avg'] ?? -1) <=> ($b['avg'] ?? -1));
$n = count($students);
$avgs = array_filter(array_column($students, 'avg'), fn($v) => $v !== null);
$kpi = [
    'students' => $n,
    'lessons' => $n ? round(array_sum(array_column($students, 'lessons')) / $n) : 0,
    'avg' => $avgs ? round(array_sum($avgs) / count($avgs), 1) : null,
    'pending' => array_sum(array_column($students, 'pending')),
    'risk' => count(array_filter($students, fn($s) => $s['avg'] !== null && $s['avg'] < 50)),
];

// ข้อมูลกราฟ
$itemChart = [];
foreach ($items as $key => $item) {
    $scores = []; $done = 0;
    foreach ($students as $s) if (isset($s['results'][$key])) { $done++; if ($s['results'][$key]['best'] !== null) $scores[] = $s['results'][$key]['best']; }
    $itemChart[] = ['label' => $item['title'], 'avg' => $scores ? round(array_sum($scores) / count($scores), 1) : 0, 'done' => $done, 'kind' => $item['kind'] === 'quiz' ? 'แบบทดสอบ' : 'งาน'];
}
$bands = ['0–49' => [0, 50], '50–59' => [50, 60], '60–69' => [60, 70], '70–79' => [70, 80], '80–100' => [80, 101]];
$distribution = [];
foreach ($bands as $label => [$lo, $hi]) $distribution[$label] = count(array_filter($avgs, fn($v) => $v >= $lo && $v < $hi));
$distribution['ยังไม่มีคะแนน'] = $n - count($avgs);
$prepost = [];
foreach (quiz_ready() ? prepost_pairs($courseId) : [] as $pair) {
    $st = prepost_stats(array_intersect_key(quiz_scores_by_user($pair['pre_key'], 'first'), array_flip($ids)), array_intersect_key(quiz_scores_by_user($pair['post_key'], 'first'), array_flip($ids)));
    if ($st['n']) $prepost[] = ['label' => $pair['post']['title'], 'pre' => $st['pre'], 'post' => $st['post'], 'n' => $st['n']];
}
$lessonChart = array_map(fn($l) => ['label' => $l['title'], 'value' => $n ? round(($lessonDone[$l['id']] ?? 0) * 100 / $n, 1) : 0, 'done' => $lessonDone[$l['id']] ?? 0], $lessons);

// สีพื้นช่องตารางคะแนน: ไล่ระดับสีเดียว (ฟ้าอ่อน → น้ำเงินเข้ม) ตามร้อยละ
$cellStyle = function (?float $v): string {
    if ($v === null) return '';
    $t = max(0, min(1, $v / 100));
    $mix = fn($a, $b) => (int)round($a + ($b - $a) * $t);
    return sprintf('background:rgb(%d,%d,%d);color:%s', $mix(234, 29), $mix(242, 95), $mix(252, 176), $t > 0.55 ? '#fff' : '#0b0b0b');
};

$pageTitle = 'แดชบอร์ดคะแนน: ' . $course['code'] . ' ' . $course['title'];
require __DIR__ . '/_top.php';
?>
<style>
.viz-root { --surface-1: #fff; --text-primary: #0b0b0b; --text-secondary: #52514e; --grid: #e6e6e3; --series-1: #2a78d6; --series-2: #eb6834; }
.kpi .value { font-size: 1.6rem; font-weight: 600; line-height: 1.1; }
.chart-box { position: relative; height: 280px; }
.heat td.cell { text-align: center; min-width: 3.4rem; font-variant-numeric: tabular-nums; }
.heat th.item { writing-mode: vertical-rl; transform: rotate(180deg); white-space: nowrap; max-height: 11rem; overflow: hidden; text-overflow: ellipsis; font-weight: 500; font-size: .8rem; vertical-align: bottom; }
.heat-legend span { display: inline-block; width: 2rem; height: .8rem; }
</style>
<div class="viz-root">
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <a href="<?= url('admin/lessons.php?course_id=' . $courseId) ?>"><i class="bi bi-arrow-left"></i> กลับไปบทเรียน</a>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= url('admin/gradebook.php?course_id=' . $courseId) ?>" class="btn btn-outline-success">สมุดคะแนน / ตัดเกรด</a>
        <a href="<?= url('admin/prepost.php?course_id=' . $courseId) ?>" class="btn btn-outline-primary">ผลก่อน–หลังเรียน</a>
    </div>
</div>
<form class="row g-2 align-items-end mb-4">
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
    <div class="col-md-5"><label class="form-label w-100 small">กลุ่มเรียน<select name="group_id" class="form-select" onchange="this.form.submit()"><option value="0">ผู้เรียนทั้งหมด</option><?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= $groupId === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?></select></label></div>
    <div class="col-md-4"><label class="form-label w-100 small">เรียงตารางตาม<select name="sort" class="form-select" onchange="this.form.submit()"><option value="code">รหัสนักเรียน</option><option value="avg" <?= $sort === 'avg' ? 'selected' : '' ?>>คะแนนเฉลี่ย (น้อย → มาก)</option></select></label></div>
</form>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['bi-people', 'ผู้เรียน', $kpi['students'] . ' คน', 'primary', ''],
        ['bi-journal-check', 'เรียนจบบทเรียนเฉลี่ย', $kpi['lessons'] . '%', 'info', count($lessons) . ' บทเรียน'],
        ['bi-trophy', 'คะแนนเฉลี่ย', $kpi['avg'] === null ? '—' : $kpi['avg'] . '%', 'success', 'จากคะแนนดีที่สุดของแต่ละรายการ'],
        ['bi-hourglass-split', 'งานรอตรวจ', $kpi['pending'] . ' งาน', 'warning', ''],
        ['bi-exclamation-triangle', 'ต้องติดตาม', $kpi['risk'] . ' คน', 'danger', 'คะแนนเฉลี่ยต่ำกว่า 50%'],
    ] as [$icon, $label, $value, $color, $note]): ?>
        <div class="col-6 col-lg"><div class="card stat-card shadow-sm h-100 kpi"><div class="card-body d-flex gap-3 align-items-center">
            <div class="icon bg-<?= $color ?>-subtle text-<?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
            <div><div class="small text-muted"><?= $label ?></div><div class="value"><?= e($value) ?></div><?php if ($note): ?><div class="small text-muted"><?= e($note) ?></div><?php endif; ?></div>
        </div></div></div>
    <?php endforeach; ?>
</div>

<?php if (!$n): ?>
    <div class="alert alert-info">ยังไม่มีผู้เรียนในรายวิชา/กลุ่มนี้</div>
<?php else: ?>
<div class="row g-3 mb-4">
    <div class="col-xl-8"><div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body">
        <h2 class="h6">คะแนนเฉลี่ยรายแบบทดสอบ / งาน (%)</h2>
        <?php if ($itemChart): ?><div class="chart-box"><canvas id="itemChart" role="img" aria-label="กราฟคะแนนเฉลี่ยรายแบบทดสอบและงาน"></canvas></div><?php else: ?><p class="text-muted">ยังไม่มีแบบทดสอบหรืองาน</p><?php endif; ?>
    </div></div></div>
    <div class="col-xl-4"><div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body">
        <h2 class="h6">การกระจายคะแนนเฉลี่ยของผู้เรียน (คน)</h2>
        <div class="chart-box"><canvas id="distChart" role="img" aria-label="กราฟการกระจายคะแนนเฉลี่ยของผู้เรียน"></canvas></div>
    </div></div></div>
    <?php if ($prepost): ?>
    <div class="col-xl-6"><div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body">
        <h2 class="h6">ก่อนเรียน–หลังเรียน (ค่าเฉลี่ย %, ครั้งแรก)</h2>
        <div class="chart-box"><canvas id="prepostChart" role="img" aria-label="กราฟเปรียบเทียบคะแนนก่อนเรียนและหลังเรียน"></canvas></div>
    </div></div></div>
    <?php endif; ?>
    <div class="col-xl-<?= $prepost ? 6 : 12 ?>"><div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body">
        <h2 class="h6">ผู้เรียนที่เรียนจบแต่ละบทเรียน (%)</h2>
        <?php if ($lessonChart): ?><div class="chart-box"><canvas id="lessonChart" role="img" aria-label="กราฟร้อยละผู้เรียนที่เรียนจบแต่ละบทเรียน"></canvas></div><?php else: ?><p class="text-muted">ยังไม่มีบทเรียน</p><?php endif; ?>
    </div></div></div>
</div>

<div class="card border-0 shadow-sm rounded-4"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2">
        <h2 class="h6 mb-0">ตารางคะแนนรายคน (คะแนนดีที่สุด %)</h2>
        <div class="heat-legend small text-muted">0% <?php foreach ([0, 25, 50, 75, 100] as $v): ?><span style="<?= $cellStyle((float)$v) ?>"></span><?php endforeach; ?> 100% · <span style="background:#f1f1ef"></span> ยังไม่ทำ/ไม่ส่ง</div>
    </div>
    <div class="table-responsive"><table class="table table-sm table-bordered heat mb-0 align-middle">
        <thead class="table-light"><tr><th>รหัส</th><th>ชื่อ-นามสกุล</th><th class="text-center">เรียนจบ</th><th class="text-center">เฉลี่ย</th>
            <?php foreach ($items as $item): ?><th class="item" title="<?= e($item['title']) ?>"><?= e(mb_strimwidth($item['title'], 0, 28, '…')) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($students as $s): ?>
            <tr>
                <td class="text-nowrap"><?= e($s['student_code']) ?></td>
                <td class="text-nowrap"><?= e($s['fullname']) ?><?= $s['avg'] !== null && $s['avg'] < 50 ? ' <i class="bi bi-exclamation-triangle-fill text-danger" title="ต้องติดตาม"></i>' : '' ?></td>
                <td class="text-center"><?= $s['lessons'] ?>%</td>
                <td class="text-center fw-semibold"><?= $s['avg'] === null ? '—' : $s['avg'] . '%' ?></td>
                <?php foreach ($items as $key => $item): $r = $s['results'][$key] ?? null; $v = $r['best'] ?? null; ?>
                    <td class="cell" style="<?= $r ? $cellStyle($v) : 'background:#f1f1ef;color:#8a8984' ?>" title="<?= e($item['title'] . ($r ? ' · ' . ($item['kind'] === 'quiz' ? 'ทำ ' . $r['attempts'] . ' ครั้ง' : ['graded' => 'ตรวจแล้ว', 'submitted' => 'รอตรวจ', 'revision' => 'ให้แก้ไข'][$r['status']] ?? '') : ' · ยังไม่ทำ')) ?>">
                        <?= $r ? ($v === null ? '<i class="bi bi-hourglass-split" aria-label="รอตรวจ"></i>' : rtrim(rtrim(number_format($v, 1), '0'), '.')) : '–' ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div></div>
<?php endif; ?>
</div>

<?php if ($n): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(() => {
    if (!window.Chart) return;
    const css = getComputedStyle(document.querySelector('.viz-root'));
    const v = name => css.getPropertyValue(name).trim();
    Chart.defaults.font.family = "'Prompt', sans-serif";
    Chart.defaults.color = v('--text-secondary');
    Chart.defaults.plugins.tooltip.padding = 10;
    const short = s => s.length > 22 ? s.slice(0, 21) + '…' : s;
    const bar = {borderRadius: 4, borderSkipped: 'start', maxBarThickness: 36, borderWidth: 0};
    const pctAxis = {min: 0, max: 100, grid: {color: v('--grid')}, border: {display: false}, ticks: {callback: x => x + '%'}};
    const catAxis = {grid: {display: false}, border: {color: v('--grid')}, ticks: {callback(value) { return short(this.getLabelForValue(value)); }}};
    const items = <?= json_encode($itemChart, JSON_UNESCAPED_UNICODE) ?>;
    if (document.getElementById('itemChart')) new Chart(document.getElementById('itemChart'), {
        type: 'bar',
        data: {labels: items.map(i => i.label), datasets: [{label: 'คะแนนเฉลี่ย', data: items.map(i => i.avg), backgroundColor: v('--series-1'), ...bar}]},
        options: {maintainAspectRatio: false, scales: {y: pctAxis, x: catAxis}, plugins: {legend: {display: false},
            tooltip: {callbacks: {label: c => 'เฉลี่ย ' + c.parsed.y + '%', afterLabel: c => items[c.dataIndex].kind + ' · ทำแล้ว ' + items[c.dataIndex].done + '/' + <?= $n ?> + ' คน'}}}}
    });
    const dist = <?= json_encode($distribution, JSON_UNESCAPED_UNICODE) ?>;
    // ไล่ระดับสีเดียวตามช่วงคะแนน (ช่อง "ยังไม่มีคะแนน" เป็นสีเทา)
    const ramp = ['#cfe1f7', '#9cc3ef', '#6aa3e6', '#2a78d6', '#1d5fb0', '#d9d8d4'];
    new Chart(document.getElementById('distChart'), {
        type: 'bar',
        data: {labels: Object.keys(dist), datasets: [{label: 'จำนวนผู้เรียน', data: Object.values(dist), backgroundColor: ramp, ...bar}]},
        options: {maintainAspectRatio: false, scales: {y: {beginAtZero: true, grid: {color: v('--grid')}, border: {display: false}, ticks: {precision: 0}}, x: {grid: {display: false}, border: {color: v('--grid')}}},
            plugins: {legend: {display: false}, tooltip: {callbacks: {label: c => c.parsed.y + ' คน'}}}}
    });
    const prepost = <?= json_encode($prepost, JSON_UNESCAPED_UNICODE) ?>;
    if (document.getElementById('prepostChart')) new Chart(document.getElementById('prepostChart'), {
        type: 'bar',
        data: {labels: prepost.map(p => p.label), datasets: [
            {label: 'ก่อนเรียน', data: prepost.map(p => p.pre), backgroundColor: v('--series-2'), ...bar},
            {label: 'หลังเรียน', data: prepost.map(p => p.post), backgroundColor: v('--series-1'), ...bar}]},
        options: {maintainAspectRatio: false, scales: {y: pctAxis, x: catAxis}, datasets: {bar: {categoryPercentage: .6, barPercentage: .9}},
            plugins: {legend: {position: 'bottom', labels: {boxWidth: 12, boxHeight: 12, useBorderRadius: true, borderRadius: 3}},
                tooltip: {callbacks: {label: c => c.dataset.label + ' ' + c.parsed.y + '%', footer: items => 'ทำครบทั้งสองครั้ง ' + prepost[items[0].dataIndex].n + ' คน'}}}}
    });
    const lessons = <?= json_encode($lessonChart, JSON_UNESCAPED_UNICODE) ?>;
    if (document.getElementById('lessonChart')) new Chart(document.getElementById('lessonChart'), {
        type: 'bar',
        data: {labels: lessons.map(l => l.label), datasets: [{label: 'เรียนจบ', data: lessons.map(l => l.value), backgroundColor: v('--series-1'), ...bar}]},
        options: {maintainAspectRatio: false, scales: {y: pctAxis, x: catAxis}, plugins: {legend: {display: false},
            tooltip: {callbacks: {label: c => 'เรียนจบ ' + c.parsed.y + '% (' + lessons[c.dataIndex].done + '/' + <?= $n ?> + ' คน)'}}}}
    });
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/_bottom.php'; ?>
