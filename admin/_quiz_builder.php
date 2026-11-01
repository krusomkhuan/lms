<?php
// $quizName and $quizValue are supplied by the lesson form ($courseId ด้วย สำหรับคลังข้อสอบ).
if (!isset($quizName, $quizValue, $courseId)) { http_response_code(404); exit; }
// ค่าของแบบทดสอบที่กำลังแก้ไข (เฉพาะสื่อหลัก) — ล้างทิ้งเพื่อไม่ให้ติดไปถึงแถวสื่อเพิ่มเติม
$quizSelf = $quizSelfKey ?? '';
$quizKindSaved = $quizSavedKind ?? '';
$quizAdd = $quizAddUrl ?? '';
unset($quizSelfKey, $quizSavedKind, $quizAddUrl);
if (!isset($quizBankCats, $quizPreTests)) {
    $quizBankCats = question_bank_categories((int)$courseId);
    $quizPreTests = [];
    foreach (grade_sources((int)$courseId) as $key => $source) {
        if ($source['media_type'] === 'quiz' && quiz_parse((string)$source['quiz_data'])['kind'] === 'pre') $quizPreTests[substr($key, 5)] = $source['title'];
    }
}
?>
<div class="quiz-builder border rounded p-3 mb-3" data-import-url="<?= e(url('admin/quiz_import.php')) ?>" data-saved-kind="<?= e($quizKindSaved) ?>">
    <input type="hidden" class="quiz-data" name="<?= e($quizName) ?>" value="<?= e($quizValue ?: '[]') ?>">
    <div class="row g-2 mb-3">
        <div class="col-md-4"><label class="form-label small w-100">ประเภท
            <select class="form-select quiz-kind"><?php foreach (QUIZ_KINDS as $kind => $label): ?><option value="<?= $kind ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
            <?php if ($quizKindSaved === 'pre' && $quizAdd): ?><div class="form-text">ต้องการแบบทดสอบหลังเรียนด้วย? <a href="<?= e($quizAdd) ?>">เพิ่มเป็นสื่อใหม่</a> (อย่าเปลี่ยนประเภทชุดนี้)</div><?php endif; ?></div>
        <div class="col-md-5 quiz-pair-box"><label class="form-label small w-100">เปรียบเทียบกับแบบทดสอบก่อนเรียน
            <select class="form-select quiz-pair"><option value="">— เลือก —</option><?php foreach (array_diff_key($quizPreTests, [$quizSelf => 1]) as $key => $title): ?><option value="<?= e($key) ?>"><?= e($title) ?></option><?php endforeach; ?></select></label>
            <?php if (!array_diff_key($quizPreTests, [$quizSelf => 1])): ?><div class="form-text">ยังไม่มีแบบทดสอบก่อนเรียนในวิชานี้ (บันทึกแบบทดสอบก่อนเรียนก่อน แล้วจึงเลือกคู่)</div><?php endif; ?></div>
        <div class="col-md-6"><label class="form-label small w-100">เปิดสอบ (ไม่บังคับ)<input type="datetime-local" class="form-control quiz-opens"></label></div>
        <div class="col-md-6"><label class="form-label small w-100">ปิดสอบ (ไม่บังคับ)<input type="datetime-local" class="form-control quiz-closes"></label></div>
        <div class="col-md-3"><label class="form-label small w-100">ทำได้สูงสุด (ครั้ง)<input type="number" class="form-control quiz-max" min="0" max="100" value="0"></label><div class="form-text quiz-max-note">0 = ไม่จำกัด</div></div>
        <div class="col-md-9 d-flex align-items-center"><div class="form-check"><label class="form-check-label"><input type="checkbox" class="form-check-input quiz-lockdown">
            <b>ป้องกันการออกจากหน้าสอบ</b> — เปิดแท็บใหม่/สลับหน้าจอ เตือน <?= QUIZ_LEAVE_LIMIT - 1 ?> ครั้ง ครั้งที่ <?= QUIZ_LEAVE_LIMIT ?> ส่งคำตอบอัตโนมัติ (ก่อน–หลังเรียนเปิดให้เป็นค่าเริ่มต้น)</label></div></div>
    </div>
    <div class="mb-3">
        <div class="form-check form-check-inline"><label class="form-check-label"><input type="radio" class="form-check-input quiz-source" value="manual"> สร้างข้อสอบเอง</label></div>
        <div class="form-check form-check-inline"><label class="form-check-label"><input type="radio" class="form-check-input quiz-source" value="bank"> สุ่มจากคลังข้อสอบ</label></div>
    </div>
    <div class="quiz-bank-box">
        <?php if ($quizBankCats): ?>
            <div class="small mb-1">หมวดที่ใช้สุ่ม (ไม่เลือก = ทุกหมวด)</div>
            <?php foreach ($quizBankCats as $name => $n): ?>
                <div class="form-check form-check-inline"><label class="form-check-label"><input type="checkbox" class="form-check-input quiz-cat" value="<?= e($name) ?>" data-count="<?= (int)$n ?>"> <?= e($name) ?> (<?= (int)$n ?> ข้อ)</label></div>
            <?php endforeach; ?>
            <label class="d-block mt-2">จำนวนข้อที่สุ่ม<input type="number" class="form-control quiz-bank-count" min="1" max="100" value="10" style="max-width:10rem"></label>
            <div class="form-text quiz-bank-info"></div>
        <?php else: ?>
            <div class="alert alert-warning small mb-2">คลังข้อสอบของวิชานี้ยังว่าง</div>
        <?php endif; ?>
        <a href="<?= url('admin/question_bank.php?course_id=' . (int)$courseId) ?>" target="_blank" class="small">เปิดคลังข้อสอบ (แท็บใหม่) <i class="bi bi-box-arrow-up-right"></i></a>
        <p class="small text-muted mt-2 mb-0">นักเรียนแต่ละคน/แต่ละครั้งจะได้ข้อสอบสุ่มไม่ซ้ำกัน ข้อละ 1 คะแนน</p>
    </div>
    <div class="quiz-manual-box">
        <div class="d-flex flex-wrap gap-2 align-items-end mb-2">
            <label>จำนวนข้อ (1–100)<input type="number" class="form-control quiz-count" min="1" max="100" value="1"></label>
            <button type="button" class="btn btn-outline-primary quiz-resize">กำหนดจำนวนข้อ</button>
            <div class="form-check ms-2 mb-2"><label class="form-check-label"><input type="checkbox" class="form-check-input quiz-shuffle"> สลับลำดับข้อ (นักเรียนแต่ละครั้งเห็นข้อเรียงไม่เหมือนกัน)</label></div>
        </div>
        <p class="small text-muted">ข้อละ 1 คะแนน กำหนดตัวเลือกได้ 2–10 ตัวเลือกต่อข้อ และเลือกคำตอบที่ถูกต้องหนึ่งข้อ</p>
        <details class="quiz-import border rounded p-2 mb-3">
            <summary class="small fw-semibold"><i class="bi bi-upload"></i> นำเข้าข้อสอบ (วางข้อความจาก Word / ตาราง Excel หรืออัปโหลด .csv)</summary>
            <textarea class="form-control font-monospace small mt-2 quiz-import-text" rows="6" placeholder="1. โจทย์&#10;ก. ตัวเลือก&#10;ข. คำตอบที่ถูก *&#10;ค. ตัวเลือก"></textarea>
            <input type="file" class="form-control form-control-sm mt-2 quiz-import-file" accept=".csv,.txt,.tsv" aria-label="ไฟล์ข้อสอบ">
            <div class="d-flex flex-wrap gap-3 align-items-center mt-2">
                <label class="small"><input type="radio" class="quiz-import-mode" value="replace" checked> แทนที่ข้อเดิมทั้งหมด</label>
                <label class="small"><input type="radio" class="quiz-import-mode" value="append"> ต่อท้ายข้อเดิม</label>
                <button type="button" class="btn btn-sm btn-primary quiz-import-run">นำเข้า</button>
                <a class="small" href="<?= url('admin/quiz_import.php') ?>" target="_blank">รูปแบบการนำเข้า / แม่แบบ</a>
            </div>
            <div class="small mt-2 quiz-import-msg"></div>
        </details>
        <div class="quiz-questions"></div>
    </div>
</div>
