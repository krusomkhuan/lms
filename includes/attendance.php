<?php
function attendance_ready(): bool
{
    static $ready;
    return $ready ??= (int)db_value("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('attendance_config','attendance_records')") === 2;
}

function attendance_statuses(bool $includeLegacy = false): array
{
    $statuses = ['present'=>'มาปกติ', 'late'=>'มาสาย', 'absent'=>'ขาดเรียน', 'sick_leave'=>'ลาป่วย', 'personal_leave'=>'ลากิจ'];
    if ($includeLegacy) $statuses['leave'] = 'ลา (ข้อมูลเดิม)';
    return $statuses;
}

function attendance_date($value): string
{
    if (!is_string($value)) throw new RuntimeException('วันที่ไม่ถูกต้อง');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value || $value < '1000-01-01') throw new RuntimeException('กรุณาระบุวันที่ให้ถูกต้อง');
    return $value;
}

function attendance_validate_config($start, $end, $weekdays, $holidayText, bool $self): array
{
    $start = attendance_date($start); $end = attendance_date($end);
    if ($end < $start || (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days > 730) throw new RuntimeException('วันปิดภาคเรียนต้องไม่ก่อนวันเปิด และช่วงภาคเรียนไม่เกิน 2 ปี');
    if (!is_array($weekdays) || !$weekdays || count($weekdays) > 7) throw new RuntimeException('กรุณาเลือกวันเรียนอย่างน้อย 1 วัน');
    foreach ($weekdays as $day) if (!is_string($day) || !preg_match('/^[1-7]$/D', $day)) throw new RuntimeException('วันเรียนไม่ถูกต้อง');
    $weekdays = array_values(array_unique($weekdays)); sort($weekdays);
    if (!is_string($holidayText) || strlen($holidayText) > 60000) throw new RuntimeException('รายการวันหยุดยาวเกินไป');
    $holidays = [];
    foreach (preg_split('/\R/u', $holidayText) as $line) {
        if (trim($line) === '') continue;
        $parts = explode('|', $line, 2);
        $range = explode('..', trim($parts[0]));
        if (count($range) > 2) throw new RuntimeException('รูปแบบวันหยุดไม่ถูกต้อง');
        $from = attendance_date(trim($range[0])); $until = attendance_date(trim($range[1] ?? $range[0]));
        $name = trim($parts[1] ?? 'วันหยุด');
        if ($until < $from || $from < $start || $until > $end || $name === '' || mb_strlen($name) > 150) throw new RuntimeException('วันหยุดต้องอยู่ในภาคเรียน เรียงวันเริ่ม–สิ้นสุด และชื่อไม่เกิน 150 ตัวอักษร');
        $holidays[] = ['from'=>$from, 'until'=>$until, 'name'=>$name];
        if (count($holidays) > 200) throw new RuntimeException('กำหนดวันหยุดได้ไม่เกิน 200 รายการ');
    }
    return ['term_start'=>$start, 'term_end'=>$end, 'weekdays'=>implode(',', $weekdays), 'holidays_json'=>json_encode($holidays, JSON_UNESCAPED_UNICODE), 'self_checkin'=>(int)$self];
}

function attendance_closed_reason(?array $config, string $date): string
{
    attendance_date($date);
    if (!$config) return 'ครูยังไม่ได้ตั้งค่าภาคเรียน';
    if ($date < $config['term_start']) return 'ยังไม่เปิดภาคเรียน';
    if ($date > $config['term_end']) return 'สิ้นสุดภาคเรียนแล้ว';
    foreach (json_decode($config['holidays_json'], true) as $holiday) {
        if ($date >= $holiday['from'] && $date <= $holiday['until']) return 'วันหยุด: ' . $holiday['name'];
    }
    if (!in_array((string)(new DateTimeImmutable($date))->format('N'), explode(',', $config['weekdays']), true)) return 'ไม่ใช่วันเรียนประจำสัปดาห์';
    return '';
}

function attendance_holiday_text(?array $config): string
{
    $lines = [];
    foreach (json_decode($config['holidays_json'] ?? '[]', true) as $h) $lines[] = $h['from'] . ($h['until'] !== $h['from'] ? '..' . $h['until'] : '') . ' | ' . $h['name'];
    return implode("\n", $lines);
}

/** Serialize calendar changes and check-ins so a closed day cannot receive a new record. */
function attendance_lock(int $courseId): ?array
{
    return db_one('SELECT * FROM attendance_config WHERE course_id=? FOR UPDATE', [$courseId]);
}

function attendance_self_checkin(int $courseId, int $userId): void
{
    global $conn;
    $conn->begin_transaction();
    try {
        $config = attendance_lock($courseId);
        $today = date('Y-m-d');
        $reason = attendance_closed_reason($config, $today);
        if ($reason !== '') throw new RuntimeException($reason);
        if (!$config['self_checkin']) throw new RuntimeException('ครูไม่ได้เปิดให้เช็คชื่อด้วยตนเอง');
        if (!db_value("SELECT 1 FROM users WHERE id=? AND role='student' AND status='active'", [$userId])
            || !is_enrolled($courseId, $userId)
            || !db_value("SELECT 1 FROM courses WHERE id=? AND status='published'", [$courseId])) throw new RuntimeException('ไม่มีสิทธิ์เช็คชื่อในรายวิชานี้');
        // A duplicate check-in must never overwrite an absence/leave entered by a teacher.
        db_exec("INSERT INTO attendance_records (course_id,user_id,attendance_date,status,source,marked_by) VALUES (?,?,?,'present','student',?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)", [$courseId,$userId,$today,$userId]);
        if ($conn->affected_rows === 1) db_exec('UPDATE attendance_config SET version=version+1 WHERE course_id=?', [$courseId]);
        $conn->commit();
    } catch (Throwable $ex) { $conn->rollback(); throw $ex; }
}
