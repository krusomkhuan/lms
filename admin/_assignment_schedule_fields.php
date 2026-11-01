<?php if (!isset($schedulePrefix, $scheduleValues)) { http_response_code(404); exit; } ?>
<div class="row g-2">
    <div class="col-md-6"><label class="form-label w-100">เริ่มรับงาน (ไม่บังคับ)<input type="datetime-local" class="form-control assignment-schedule-input" name="<?= e($schedulePrefix['open']) ?>" value="<?= e(schedule_input_value($scheduleValues['submission_opens_at'] ?? null)) ?>"></label></div>
    <div class="col-md-6"><label class="form-label w-100">กำหนดส่ง (ไม่บังคับ)<input type="datetime-local" class="form-control assignment-schedule-input" name="<?= e($schedulePrefix['due']) ?>" value="<?= e(schedule_input_value($scheduleValues['submission_due_at'] ?? null)) ?>"></label></div>
    <label class="form-check ms-2"><input class="form-check-input assignment-schedule-input" type="checkbox" name="<?= e($schedulePrefix['late']) ?>" value="1" <?= !empty($scheduleValues['accept_late']) ? 'checked' : '' ?>> รับงานหลังกำหนดส่ง (ติดสถานะส่งล่าช้า)</label>
</div>
<p class="form-text">วันเวลาประเทศไทย · เว้นว่างเพื่อไม่จำกัดเวลา · เมื่อปิดบทเรียน นักเรียนจะส่งงานไม่ได้แม้เปิดรับงานล่าช้า</p>
