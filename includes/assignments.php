<?php
function assignments_ready(): bool
{
    static $ready;
    return $ready ??= (bool)db_value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'assignment_submissions'");
}

function assignment_points($value): string
{
    if (!is_scalar($value) || !preg_match('/^\d{1,5}(?:\.\d{1,2})?$/D', (string)$value) || (float)$value <= 0 || (float)$value > 10000) {
        throw new RuntimeException('คะแนนเต็มของงานต้องมากกว่า 0 และไม่เกิน 10,000 (ทศนิยมได้ 2 ตำแหน่ง)');
    }
    return number_format((float)$value, 2, '.', '');
}

function assignment_score($value, string $maximum): string
{
    if (!is_scalar($value) || !preg_match('/^\d{1,5}(?:\.\d{1,2})?$/D', (string)$value) || (float)$value > (float)$maximum) {
        throw new RuntimeException('คะแนนต้องอยู่ระหว่าง 0 ถึง ' . $maximum);
    }
    return number_format((float)$value, 2, '.', '');
}

function assignment_status(string $status): string
{
    return ['submitted' => 'รอตรวจ', 'graded' => 'ตรวจแล้ว', 'revision' => 'ให้แก้ไขงาน'][$status] ?? $status;
}

function assignment_fingerprint(array $media): string
{
    return hash('sha256', json_encode([$media['title'], $media['content'], $media['assignment_points'], $media['submission_opens_at'] ?? null, $media['submission_due_at'] ?? null, $media['accept_late'] ?? 0], JSON_UNESCAPED_UNICODE));
}

function assignment_upload(): ?array
{
    $file = $_FILES['submission_file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (!is_string($file['name'] ?? null) || !is_string($file['tmp_name'] ?? null) || $file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ กรุณาเลือกไฟล์อีกครั้ง');
    if ($file['size'] > MAX_UPLOAD_MB * 1024 * 1024) throw new RuntimeException('ไฟล์มีขนาดเกิน ' . MAX_UPLOAD_MB . ' MB');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, allowed_ext('file'), true)) throw new RuntimeException('ชนิดไฟล์ไม่รองรับ อนุญาต: ' . implode(', ', allowed_ext('file')));
    $dir = UPLOAD_PATH . '/submissions';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException('ไม่สามารถสร้างที่เก็บไฟล์งานได้');
    // Fail closed unless the Apache access rule shipped with the app is present.
    if (!is_file($dir . '/.htaccess') || !str_contains(file_get_contents($dir . '/.htaccess'), 'Require all denied')) throw new RuntimeException('ที่เก็บไฟล์งานยังไม่พร้อม กรุณาติดต่อผู้ดูแลระบบ');
    $name = bin2hex(random_bytes(24)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ');
    return ['path' => 'submissions/' . $name, 'name' => mb_substr(basename(str_replace('\\', '/', $file['name'])), 0, 255)];
}
