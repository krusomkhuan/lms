<?php
// ความก้าวหน้าผู้เรียน: ใช้ร่วมกันระหว่างหน้า "ความก้าวหน้าของฉัน" (นักเรียน) และแดชบอร์ดคะแนน (ครู)

/** แบบทดสอบ/งานที่เผยแพร่ของรายวิชา เรียงตามลำดับบทเรียน [key => ['kind','title','lesson_title','lesson_id','media_id','max','quiz_kind']] */
function progress_items(int $courseId): array
{
    $lessons = [];
    foreach (db_all('SELECT id, title, sort_order FROM lessons WHERE course_id = ? AND is_published = 1 ORDER BY sort_order, id', [$courseId]) as $n => $l) $lessons[(int)$l['id']] = $l + ['n' => $n];
    $items = [];
    foreach (grade_sources($courseId) as $key => $s) {
        if (!isset($lessons[(int)$s['lesson_id']]) || !$s['is_published']) continue;
        $isQuiz = $s['media_type'] === 'quiz';
        $items[$key] = ['kind' => $s['media_type'], 'title' => $s['title'], 'lesson_title' => $lessons[(int)$s['lesson_id']]['title'], 'lesson_id' => (int)$s['lesson_id'],
            'media_id' => (int)$s['source_media_id'], 'max' => $isQuiz ? quiz_total((string)$s['quiz_data']) : (float)$s['assignment_points'],
            'quiz_kind' => $isQuiz ? quiz_parse((string)$s['quiz_data'])['kind'] : '', 'order' => $lessons[(int)$s['lesson_id']]['n'] * 1000 + (int)$s['source_media_id']];
    }
    uasort($items, fn($a, $b) => $a['order'] <=> $b['order']);
    return $items;
}

/**
 * ผลของผู้เรียนแต่ละรายการ [user_id => [key => ['attempts','best','latest','status','late']]] (best/latest เป็นร้อยละ)
 * $userId = 0 → ทุกคนในรายวิชา
 */
function progress_results(int $courseId, int $userId = 0): array
{
    $results = [];
    $filter = $userId ? ' AND x.user_id = ?' : '';
    $params = $userId ? [$courseId, $userId] : [$courseId];
    if (quiz_ready()) foreach (db_all("SELECT x.lesson_id, x.source_media_id, x.user_id, x.score, x.total FROM quiz_attempts x JOIN lessons l ON l.id = x.lesson_id WHERE l.course_id = ?$filter ORDER BY x.id", $params) as $a) {
        $r = &$results[$a['user_id']]['quiz:' . $a['lesson_id'] . ':' . $a['source_media_id']];
        $percent = $a['total'] ? round($a['score'] * 100 / $a['total'], 2) : 0.0;
        $r = ['attempts' => ($r['attempts'] ?? 0) + 1, 'best' => max($r['best'] ?? 0, $percent), 'latest' => $percent, 'first' => $r['first'] ?? $percent, 'status' => 'graded', 'late' => false];
        unset($r);
    }
    if (assignments_ready()) foreach (db_all("SELECT x.lesson_id, x.source_media_id, x.user_id, x.score, x.max_score, x.status, x.is_late FROM assignment_submissions x JOIN lessons l ON l.id = x.lesson_id WHERE l.course_id = ?$filter ORDER BY x.id", $params) as $s) {
        $r = &$results[$s['user_id']]['assignment:' . $s['lesson_id'] . ':' . $s['source_media_id']];
        $percent = $s['score'] !== null && (float)$s['max_score'] > 0 ? round($s['score'] * 100 / $s['max_score'], 2) : null;
        $r = ['attempts' => ($r['attempts'] ?? 0) + 1, 'best' => $percent === null ? ($r['best'] ?? null) : max($r['best'] ?? 0, $percent), 'latest' => $percent, 'first' => $r['first'] ?? $percent, 'status' => $s['status'], 'late' => (bool)$s['is_late']];
        unset($r);
    }
    return $results;
}

/** สรุปภาพรวมของผู้เรียนหนึ่งคน */
function progress_summary(array $items, array $results): array
{
    $quizzes = array_filter($items, fn($i) => $i['kind'] === 'quiz');
    $assignments = array_filter($items, fn($i) => $i['kind'] === 'assignment');
    $quizDone = array_intersect_key($results, $quizzes);
    $scored = array_filter(array_column(array_intersect_key($results, $items), 'best'), fn($v) => $v !== null);
    return [
        'quiz_total' => count($quizzes), 'quiz_done' => count($quizDone),
        'assignment_total' => count($assignments), 'assignment_done' => count(array_intersect_key($results, $assignments)),
        'assignment_pending' => count(array_filter(array_intersect_key($results, $assignments), fn($r) => $r['status'] !== 'graded')),
        'average' => $scored ? round(array_sum($scored) / count($scored), 1) : null,
    ];
}

/** ระดับสีตามร้อยละ */
function progress_color(?float $percent): string
{
    if ($percent === null) return 'secondary';
    return $percent >= 80 ? 'success' : ($percent >= 50 ? 'warning' : 'danger');
}

/** รายวิชาที่ผู้ใช้หลังบ้านจัดการได้ (admin ทุกวิชา, ครูเฉพาะวิชาที่สอน) พร้อมจำนวนข้อสอบในคลัง */
function staff_courses(array $me): array
{
    $bankCount = question_bank_ready() ? '(SELECT COUNT(*) FROM question_bank b WHERE b.course_id = c.id)' : '0';
    return $me['role'] === 'admin'
        ? db_all("SELECT c.id, c.code, c.title, c.status, $bankCount AS bank_count FROM courses c ORDER BY c.code")
        : db_all("SELECT c.id, c.code, c.title, c.status, $bankCount AS bank_count FROM courses c JOIN course_teachers ct ON ct.course_id = c.id AND ct.teacher_id = ? ORDER BY c.code", [(int)$me['id']]);
}
