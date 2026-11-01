<?php
if (!isset($row, $rowIndex, $types, $fileTypes)) {
    http_response_code(403);
    exit;
}
?>
<div class="extra-link-row border rounded-3 p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
        <strong class="media-row-heading">สื่อเพิ่มเติม</strong>
        <button type="button" class="btn btn-sm btn-outline-danger remove-link">ลบรายการนี้</button>
    </div>
    <div class="row g-2">
        <div class="col-md-4"><label class="form-label small w-100">ชนิดสื่อ
            <select name="extra_types[<?= e($rowIndex) ?>]" class="form-select mt-1 media-row-type">
                <?php foreach ($types as $kind => $definition): ?><option value="<?= $kind ?>" <?= $row['type'] === $kind ? 'selected' : '' ?>><?= e($definition['label']) ?></option><?php endforeach; ?>
            </select>
        </label></div>
        <div class="col-md-8"><label class="form-label small w-100">ชื่อสื่อ (ไม่บังคับ)
            <input name="extra_titles[<?= e($rowIndex) ?>]" maxlength="200" class="form-control mt-1" value="<?= e($row['title']) ?>" placeholder="เช่น วิดีโอแนะนำ / ใบงาน PDF / เสียงบรรยาย">
        </label></div>
        <div class="col-12 media-row-assignment" <?= $row['type'] !== 'assignment' ? 'hidden' : '' ?>>
            <label class="form-label w-100">คะแนนเต็มของงาน<input class="form-control assignment-points" type="number" name="extra_assignment_points[<?= e($rowIndex) ?>]" min="0.01" max="10000" step="0.01" value="<?= e($row['assignment_points'] ?? '100') ?>"></label>
            <?php $schedulePrefix = ['open' => 'extra_submission_opens_at[' . $rowIndex . ']', 'due' => 'extra_submission_due_at[' . $rowIndex . ']', 'late' => 'extra_accept_late[' . $rowIndex . ']']; $scheduleValues = $row; require __DIR__ . '/_assignment_schedule_fields.php'; ?>
            <p class="form-text">กรอกคำสั่งงานในช่องคำอธิบายด้านล่าง นักเรียนส่งข้อความหรือไฟล์ได้</p>
        </div>
        <div class="col-12 media-row-quiz" <?= $row['type'] !== 'quiz' ? 'hidden' : '' ?>>
            <?php $quizName = 'extra_quizzes[' . $rowIndex . ']'; $quizValue = $row['quiz_data'] ?? '[]'; require __DIR__ . '/_quiz_builder.php'; ?>
        </div>
        <div class="col-12 media-row-url" <?= in_array($row['type'], ['text', 'file'], true) ? 'hidden' : '' ?>>
            <label class="form-label small w-100">ลิงก์
                <input name="extra_urls[<?= e($rowIndex) ?>]" type="url" maxlength="500" class="form-control mt-1" value="<?= e($row['url']) ?>" placeholder="https://">
            </label>
        </div>
        <div class="col-12 media-row-file" <?= !in_array($row['type'], $fileTypes, true) ? 'hidden' : '' ?>>
            <label class="form-label small w-100">อัปโหลดไฟล์
                <input name="extra_files[<?= e($rowIndex) ?>]" type="file" class="form-control mt-1" <?= in_array($row['type'], $fileTypes, true) ? 'accept=".' . e(implode(',.', allowed_ext($row['type']))) . '"' : '' ?>>
            </label>
            <div class="form-text">ไฟล์ละไม่เกิน <?= MAX_UPLOAD_MB ?> MB และขนาดรวมไม่เกินที่เซิร์ฟเวอร์กำหนด<?= $error ? ' · กรุณาเลือกไฟล์อีกครั้งก่อนบันทึก' : '' ?></div>
        </div>
        <div class="col-12"><label class="form-label small w-100"><span class="media-row-content-label"><?= $row['type'] === 'text' ? 'เนื้อหาข้อความ' : 'คำอธิบายสื่อ (ไม่บังคับ)' ?></span>
            <textarea name="extra_contents[<?= e($rowIndex) ?>]" rows="4" class="form-control mt-1" placeholder="เนื้อหาหรือคำอธิบายสำหรับสื่อรายการนี้"><?= e($row['content']) ?></textarea>
        </label></div>
    </div>
</div>
