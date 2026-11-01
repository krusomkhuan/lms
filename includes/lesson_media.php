<?php
/** รองรับเว็บที่ยังไม่ได้รัน migration โดยยังเปิดบทเรียนเดิมได้ */
function lesson_media_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = (bool)db_value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'lesson_media'");
    }
    return $ready;
}

/** สื่อเพิ่มเติมที่มองเห็นได้ แบ่งตามบทเรียน โดยตรวจสิทธิ์รายวิชาก่อนเรียก */
function course_lesson_media(int $courseId, bool $canManage): array
{
    if (!lesson_media_ready()) return [];
    $rows = db_all('SELECT m.* FROM lesson_media m JOIN lessons l ON l.id = m.lesson_id
        WHERE l.course_id = ?' . ($canManage ? '' : ' AND l.is_published = 1 AND m.is_published = 1') . '
        ORDER BY m.sort_order, m.id', [$courseId]);
    $byLesson = [];
    foreach ($rows as $row) $byLesson[(int)$row['lesson_id']][] = $row;
    return $byLesson;
}
