<?php
// เรียนตามลำดับ: เปิดบทเรียนได้เมื่อเรียนจบทุกบทก่อนหน้าที่ผู้เรียนเห็นอยู่ (เผยแพร่ + อยู่ในช่วงเวลาเปิดเรียน)
function lesson_lock_ready(): bool
{
    static $ready;
    return $ready ??= (bool)db_value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'courses' AND column_name = 'sequential_lessons'");
}

/** วิชานี้บังคับเรียนตามลำดับหรือไม่ (ค่าเริ่มต้น: เปิด) */
function course_sequential(int $courseId): bool
{
    if (!lesson_lock_ready()) return true;
    return (int)(db_value('SELECT sequential_lessons FROM courses WHERE id = ?', [$courseId]) ?? 1) === 1;
}

/**
 * บทเรียนที่ยังล็อกของผู้เรียน: [lesson_id => บทเรียนแรกที่ต้องเรียนให้จบก่อน (id, title)]
 * บทที่ยังไม่ถึงเวลาเปิดหรือปิดการเรียนแล้วไม่นับเป็นบทที่ต้องจบก่อน เพื่อไม่ให้ขวางบทถัดไปถาวร
 */
function lesson_locks(int $courseId, int $userId): array
{
    if (!course_sequential($courseId)) return [];
    $rows = db_all('SELECT l.id, l.title' . (schedule_ready() ? ', l.available_from, l.available_until' : '') . ', lp.lesson_id AS done
                    FROM lessons l LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.user_id = ?
                    WHERE l.course_id = ? AND l.is_published = 1 ORDER BY l.sort_order, l.id', [$userId, $courseId]);
    $locks = [];
    $blocker = null;
    foreach ($rows as $row) {
        if ($blocker) $locks[(int)$row['id']] = $blocker;
        elseif (!$row['done'] && lesson_schedule_open($row)) $blocker = ['id' => (int)$row['id'], 'title' => $row['title']];
    }
    return $locks;
}
