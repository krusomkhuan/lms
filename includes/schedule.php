<?php
function schedule_ready(): bool
{
    static $ready;
    return $ready ??= (bool)db_value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'lessons' AND column_name = 'available_from'");
}

function schedule_datetime($value): ?string
{
    if ($value === '' || $value === null) return null;
    if (!is_string($value)) throw new RuntimeException('วันเวลาไม่ถูกต้อง');
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    if (!$dt || $dt->format('Y-m-d\TH:i') !== $value || (int)$dt->format('Y') < 1000) throw new RuntimeException('กรุณากำหนดวันเวลาให้ถูกต้อง');
    return $dt->format('Y-m-d H:i:s');
}

function schedule_range($from, $until): array
{
    $from = schedule_datetime($from);
    $until = schedule_datetime($until);
    if ($from && $until && $until <= $from) throw new RuntimeException('เวลาสิ้นสุดต้องอยู่หลังเวลาเริ่มต้น');
    return [$from, $until];
}

function lesson_schedule_open(array $lesson, ?string $now = null): bool
{
    $now ??= date('Y-m-d H:i:s');
    return (empty($lesson['available_from']) || $now >= $lesson['available_from'])
        && (empty($lesson['available_until']) || $now < $lesson['available_until']);
}

function lesson_schedule_label(array $lesson): string
{
    $parts = [];
    if (!empty($lesson['available_from'])) $parts[] = 'เปิดเรียน ' . thai_date($lesson['available_from'], true);
    if (!empty($lesson['available_until'])) $parts[] = 'ปิดเรียน ' . thai_date($lesson['available_until'], true);
    return implode(' · ', $parts);
}

function assignment_schedule_state(array $media, ?string $now = null): string
{
    $now ??= date('Y-m-d H:i:s');
    if (!empty($media['submission_opens_at']) && $now < $media['submission_opens_at']) return 'upcoming';
    if (!empty($media['submission_due_at']) && $now > $media['submission_due_at']) return !empty($media['accept_late']) ? 'late' : 'closed';
    return 'open';
}

function schedule_input_value($value): string
{
    return $value ? substr(str_replace(' ', 'T', $value), 0, 16) : '';
}

function lesson_schedule_sql(string $alias = ''): string
{
    if (!schedule_ready()) return '';
    $p = $alias !== '' ? $alias . '.' : '';
    // PHP and form dates use Asia/Bangkok; avoid depending on DB session timezone.
    $now = "'" . date('Y-m-d H:i:s') . "'";
    return " AND ({$p}available_from IS NULL OR {$p}available_from <= $now) AND ({$p}available_until IS NULL OR {$p}available_until > $now)";
}
