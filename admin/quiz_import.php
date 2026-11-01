<?php
// รูปแบบการนำเข้าข้อสอบ: หน้าอธิบาย (GET), ดาวน์โหลดแม่แบบ (?template=csv|txt), แปลงข้อความ/ไฟล์เป็น JSON ให้ตัวสร้างแบบทดสอบ (POST)
require_once __DIR__ . '/../includes/bootstrap.php';

$me = require_role('admin', 'teacher');

$sampleText = "หมวด: หน่วยที่ 1\n"
    . "1. ข้อใดคือหน่วยประมวลผลกลางของคอมพิวเตอร์\n"
    . "ก. RAM\nข. CPU *\nค. SSD\nง. GPU\n\n"
    . "2. 1 ไบต์มีกี่บิต\n"
    . "ก. 4\nข. 8\nค. 16\nง. 32\n"
    . "เฉลย ข\n\n"
    . "หมวด: หน่วยที่ 2\n"
    . "3. ข้อใดเป็นระบบปฏิบัติการ\n"
    . "ก. Windows *\nข. Excel\nค. Chrome\n";
$sampleRows = [
    ['หมวด', 'โจทย์', 'เฉลย', 'ตัวเลือก ก', 'ตัวเลือก ข', 'ตัวเลือก ค', 'ตัวเลือก ง', 'ตัวเลือก จ'],
    ['หน่วยที่ 1', 'ข้อใดคือหน่วยประมวลผลกลางของคอมพิวเตอร์', 'ข', 'RAM', 'CPU', 'SSD', 'GPU', ''],
    ['หน่วยที่ 1', '1 ไบต์มีกี่บิต', 'ข', '4', '8', '16', '32', ''],
    ['หน่วยที่ 2', 'ข้อใดเป็นระบบปฏิบัติการ', 'ก', 'Windows', 'Excel', 'Chrome', '', ''],
];

$template = $_GET['template'] ?? '';
if ($template === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="quiz-import-template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    foreach ($sampleRows as $row) fputcsv($out, $row, ',', '"', '');
    exit;
}
if ($template === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="quiz-import-template.txt"');
    echo "\xEF\xBB\xBF" . str_replace("\n", "\r\n", $sampleText);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $file = $_FILES['file'] ?? null;
        if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024) throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ (ไม่เกิน 2 MB)');
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'txt', 'tsv'], true)) throw new RuntimeException('รองรับไฟล์ .csv หรือ .txt (ไฟล์ Excel ให้บันทึกเป็น CSV UTF-8 ก่อน)');
            $questions = question_import((string)file_get_contents($file['tmp_name']), $ext !== 'txt');
        } else {
            $questions = question_import((string)post('text'));
        }
        echo json_encode(['questions' => array_map(fn($q) => ['text' => $q['text'], 'options' => $q['options'], 'correct' => $q['correct']], $questions)], JSON_UNESCAPED_UNICODE);
    } catch (RuntimeException $ex) {
        echo json_encode(['error' => $ex->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$pageTitle = 'รูปแบบการนำเข้าข้อสอบ';
require __DIR__ . '/_top.php';
?>
<h1 class="h4 mb-3"><?= e($pageTitle) ?></h1>
<p>นำเข้าข้อสอบได้ทั้งใน <b>คลังข้อสอบ</b> ของรายวิชา และใน <b>แบบทดสอบแต่ละชุด</b> (หน้าแก้ไขบทเรียน → แบบทดสอบ → นำเข้าข้อสอบ) ใช้ได้ 2 รูปแบบ ข้อละ 2–10 ตัวเลือก มีคำตอบถูก 1 ข้อ นำเข้าได้ครั้งละไม่เกิน 500 ข้อ (แบบทดสอบหนึ่งชุดมีได้ไม่เกิน 100 ข้อ)</p>

<div class="row g-3">
    <div class="col-lg-6"><div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body">
        <h2 class="h5">แบบที่ 1: ข้อความ (คัดลอกจาก Word)</h2>
        <ul class="small">
            <li>โจทย์ขึ้นต้นด้วย <b>เลขข้อ</b> เช่น <code>1.</code> <code>2)</code> <code>ข้อ 3</code> (โจทย์ยาวหลายบรรทัดได้)</li>
            <li>ตัวเลือกขึ้นต้นด้วย <code>ก.</code> <code>ข.</code> <code>ค.</code> … หรือ <code>a)</code> <code>b)</code> …</li>
            <li>ระบุคำตอบที่ถูกได้ 2 แบบ: ใส่ <code>*</code> ท้ายตัวเลือกที่ถูก หรือเพิ่มบรรทัด <code>เฉลย ข</code> หลังตัวเลือก</li>
            <li>(เฉพาะคลังข้อสอบ) บรรทัด <code>หมวด: ชื่อหมวด</code> กำหนดหมวดของข้อถัดไป</li>
        </ul>
        <pre class="bg-light border rounded p-3 small mb-3"><?= e($sampleText) ?></pre>
        <a class="btn btn-outline-primary btn-sm" href="?template=txt"><i class="bi bi-download"></i> ดาวน์โหลดแม่แบบ .txt</a>
    </div></div></div>
    <div class="col-lg-6"><div class="card border-0 shadow-sm rounded-4 h-100"><div class="card-body">
        <h2 class="h5">แบบที่ 2: ตาราง Excel / CSV</h2>
        <ul class="small">
            <li>หนึ่งแถวต่อหนึ่งข้อ แถวแรกเป็นหัวตาราง</li>
            <li>คอลัมน์ <b>A หมวด</b> (เว้นว่างได้) · <b>B โจทย์</b> · <b>C เฉลย</b> (ก ข ค … หรือ 1 2 3 …) · <b>D เป็นต้นไป</b> ตัวเลือก ก ข ค … (สูงสุด 10 คอลัมน์)</li>
            <li>อัปโหลดไฟล์ .csv หรือเลือกช่วงข้อมูลใน Excel แล้วคัดลอกมาวางในช่องนำเข้าได้เลย</li>
            <li>บันทึกจาก Excel: <i>ไฟล์ → บันทึกเป็น → CSV UTF-8</i> (ไฟล์ CSV ภาษาไทยแบบเดิมก็อ่านได้)</li>
        </ul>
        <div class="table-responsive"><table class="table table-sm table-bordered small">
            <thead class="table-light"><tr><th></th><?php foreach (range('A', 'H') as $col): ?><th><?= $col ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($sampleRows as $n => $row): ?><tr><th class="table-light"><?= $n + 1 ?></th><?php foreach ($row as $cell): ?><td><?= e($cell) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
        </table></div>
        <a class="btn btn-outline-success btn-sm" href="?template=csv"><i class="bi bi-file-earmark-spreadsheet"></i> ดาวน์โหลดแม่แบบ Excel (.csv)</a>
    </div></div></div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
