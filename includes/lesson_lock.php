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

function media_progress_ready(): bool
{
    static $ready;
    return $ready ??= (bool)db_value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'media_progress'");
}

/**
 * ลำดับสื่อภายในบทเรียน (วิชาที่เรียนตามลำดับ): [lesson_id => ['locked' => [media_id => ชื่อรายการที่ต้องทำก่อน], 'pending' => media_id|null, 'pending_title' => ...]]
 * เนื้อหาหลัก = media_id 0 · สื่อทั่วไปผ่านเมื่อเปิดดู · แบบทดสอบผ่านเมื่อส่งคำตอบ · งานผ่านเมื่อส่งงาน
 * แบบทดสอบ/งานที่ยังไม่เปิดหรือปิดรับแล้วไม่ขวางรายการถัดไป · บทที่เรียนจบแล้วเปิดทุกรายการ
 */
function lesson_step_state(int $courseId, int $userId, array $lessons, array $mediaByLesson, array $doneLessonIds): array
{
    if (!media_progress_ready() || !course_sequential($courseId) || !$lessons) return [];
    $ids = array_map(fn($l) => (int)$l['id'], $lessons);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $keys = fn(string $sql) => array_fill_keys(array_map(fn($r) => $r['lesson_id'] . ':' . $r['mid'], db_all($sql, array_merge([$userId], $ids))), true);
    $viewed = $keys("SELECT lesson_id, media_id AS mid FROM media_progress WHERE user_id = ? AND lesson_id IN ($in)");
    $quizDone = quiz_ready() ? $keys("SELECT DISTINCT lesson_id, source_media_id AS mid FROM quiz_attempts WHERE user_id = ? AND lesson_id IN ($in)") : [];
    $workDone = assignments_ready() ? $keys("SELECT DISTINCT lesson_id, source_media_id AS mid FROM assignment_submissions WHERE user_id = ? AND lesson_id IN ($in)") : [];
    $done = array_flip(array_map('intval', $doneLessonIds));
    $state = [];
    foreach ($lessons as $lesson) {
        $lid = (int)$lesson['id'];
        $info = ['locked' => [], 'pending' => null, 'pending_title' => ''];
        if (!isset($done[$lid])) {
            $steps = array_merge([[0, $lesson, 'เนื้อหาหลัก']], array_map(fn($m) => [(int)$m['id'], $m, $m['title']], $mediaByLesson[$lid] ?? []));
            $blocker = null;
            foreach ($steps as [$mid, $row, $title]) {
                if ($blocker !== null) { $info['locked'][$mid] = $blocker; continue; }
                $key = $lid . ':' . $mid;
                $passed = match ($row['media_type']) {
                    'quiz' => isset($quizDone[$key]) || !quiz_ready() || quiz_schedule_state(quiz_parse((string)($row['quiz_data'] ?? ''))) !== 'open',
                    'assignment' => isset($workDone[$key]) || !assignments_ready() || !in_array(assignment_schedule_state($row), ['open', 'late'], true),
                    default => isset($viewed[$key]),
                };
                if (!$passed) {
                    $blocker = $title;
                    $info['pending'] = $mid;
                    $info['pending_title'] = $title;
                }
            }
        }
        $state[$lid] = $info;
    }
    return $state;
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
