<?php
// ตัวอักษรนำหน้าตัวเลือก (สูงสุด 10 ตัวเลือก)
const QUIZ_LETTERS = ['ก', 'ข', 'ค', 'ง', 'จ', 'ฉ', 'ช', 'ซ', 'ฌ', 'ญ'];
const QUIZ_KINDS = ['' => 'แบบทดสอบทั่วไป', 'pre' => 'แบบทดสอบก่อนเรียน', 'post' => 'แบบทดสอบหลังเรียน'];

function quiz_ready(): bool
{
    static $ready;
    return $ready ??= (bool)db_value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'quiz_attempts'");
}

function question_bank_ready(): bool
{
    static $ready;
    return $ready ??= (bool)db_value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'question_bank'");
}

/**
 * quiz_data เป็นรายการข้อ (แบบเดิม) หรือออบเจกต์ตั้งค่า:
 * {"questions":[...],"shuffle":bool,"source":"manual|bank","categories":[...],"count":N,"kind":"|pre|post","pair":"บทเรียน:สื่อ","max_attempts":N,
 *  "opens_at":"YYYY-MM-DDTHH:MM","closes_at":"..."}
 */
function quiz_parse($raw): array
{
    $data = is_string($raw) ? json_decode($raw, true) : $raw;
    $config = ['questions' => $data, 'shuffle' => false, 'source' => 'manual', 'categories' => [], 'count' => 0, 'kind' => '', 'pair' => '', 'max_attempts' => 0, 'opens_at' => '', 'closes_at' => ''];
    if (is_array($data) && !array_is_list($data)) {
        $config = array_merge($config, ['questions' => []], array_intersect_key($data, $config));
        $config['shuffle'] = !empty($config['shuffle']);
    }
    return $config;
}

function quiz_questions($raw): array
{
    $questions = quiz_parse($raw)['questions'];
    return is_array($questions) ? $questions : [];
}

/** คะแนนเต็มของแบบทดสอบ (สุ่มจากคลัง = จำนวนข้อที่สุ่ม) */
function quiz_total($raw): int
{
    $config = quiz_parse($raw);
    return $config['source'] === 'bank' ? (int)$config['count'] : count(quiz_questions($raw));
}

function quiz_validate_questions($questions): void
{
    if (!is_array($questions) || !array_is_list($questions) || count($questions) < 1 || count($questions) > 100) {
        throw new RuntimeException('แบบทดสอบต้องมี 1–100 ข้อ');
    }
    foreach ($questions as $q) {
        if (!is_array($q) || !is_string($q['text'] ?? null) || trim($q['text']) === '' || mb_strlen($q['text']) > 2000
            || !is_array($q['options'] ?? null) || !array_is_list($q['options']) || count($q['options']) < 2 || count($q['options']) > 10
            || !is_int($q['correct'] ?? null) || !isset($q['options'][$q['correct']])) {
            throw new RuntimeException('กรุณากรอกโจทย์ ตัวเลือก 2–10 ตัวเลือก และเลือกเฉลยให้ครบทุกข้อ');
        }
        foreach ($q['options'] as $option) {
            if (!is_string($option) || trim($option) === '' || mb_strlen($option) > 1000) throw new RuntimeException('กรุณากรอกตัวเลือกทุกช่อง (ไม่เกิน 1,000 ตัวอักษร)');
        }
    }
}

/** ตรวจและจัดรูป quiz_data ก่อนบันทึก ($courseId > 0 = ตรวจว่าคลังข้อสอบมีข้อพอให้สุ่ม) */
function quiz_validate($raw, int $courseId = 0): string
{
    $config = quiz_parse($raw);
    if (!isset(QUIZ_KINDS[$config['kind']])) throw new RuntimeException('ประเภทแบบทดสอบไม่ถูกต้อง');
    if (!is_int($config['max_attempts']) || $config['max_attempts'] < 0 || $config['max_attempts'] > 100) throw new RuntimeException('จำนวนครั้งที่ทำได้ต้องอยู่ระหว่าง 0–100 (0 = ไม่จำกัด)');
    schedule_range($config['opens_at'], $config['closes_at']);
    if ($config['kind'] !== 'post' || !is_string($config['pair']) || !preg_match('/^\d+:\d+$/D', $config['pair'])) $config['pair'] = '';
    if ($config['source'] === 'bank') {
        if (!is_int($config['count']) || $config['count'] < 1 || $config['count'] > 100) throw new RuntimeException('จำนวนข้อที่สุ่มจากคลังต้องอยู่ระหว่าง 1–100');
        $categories = $config['categories'];
        if (!is_array($categories) || !array_is_list($categories) || count($categories) > 100) throw new RuntimeException('หมวดข้อสอบไม่ถูกต้อง');
        foreach ($categories as $category) if (!is_string($category) || $category === '' || mb_strlen($category) > 100) throw new RuntimeException('หมวดข้อสอบไม่ถูกต้อง');
        if ($courseId) {
            if (!question_bank_ready()) throw new RuntimeException('กรุณารัน database/migrations/1.15.0.sql ก่อนใช้คลังข้อสอบ');
            $available = question_bank_count($courseId, $categories);
            if ($available < $config['count']) throw new RuntimeException('คลังข้อสอบในหมวดที่เลือกมี ' . $available . ' ข้อ น้อยกว่าจำนวนที่ต้องการสุ่ม (' . $config['count'] . ' ข้อ)');
        }
        $config['questions'] = [];
        $config['shuffle'] = false;
    } else {
        $config['source'] = 'manual';
        quiz_validate_questions($config['questions']);
        $config['categories'] = [];
        $config['count'] = 0;
    }
    if ($config['source'] === 'manual' && !$config['shuffle'] && $config['kind'] === '' && !$config['max_attempts'] && !$config['opens_at'] && !$config['closes_at']) {
        return json_encode($config['questions'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    return json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/** สถานะเวลาเปิด–ปิดแบบทดสอบ: open | upcoming | closed */
function quiz_schedule_state(array $config, ?string $now = null): string
{
    $now ??= date('Y-m-d H:i:s');
    if ($config['opens_at'] && $now < schedule_datetime($config['opens_at'])) return 'upcoming';
    if ($config['closes_at'] && $now >= schedule_datetime($config['closes_at'])) return 'closed';
    return 'open';
}

function quiz_schedule_label(array $config): string
{
    $parts = [];
    if ($config['opens_at']) $parts[] = 'เปิดสอบ ' . thai_date(schedule_datetime($config['opens_at']), true);
    if ($config['closes_at']) $parts[] = 'ปิดสอบ ' . thai_date(schedule_datetime($config['closes_at']), true);
    return implode(' · ', $parts);
}

function question_bank_count(int $courseId, array $categories = []): int
{
    $sql = 'SELECT COUNT(*) FROM question_bank WHERE course_id = ?';
    if ($categories) $sql .= ' AND category IN (' . implode(',', array_fill(0, count($categories), '?')) . ')';
    return (int)db_value($sql, array_merge([$courseId], $categories));
}

/** หมวดในคลังข้อสอบของรายวิชา [หมวด => จำนวนข้อ] */
function question_bank_categories(int $courseId): array
{
    if (!question_bank_ready()) return [];
    return array_column(db_all('SELECT category, COUNT(*) AS n FROM question_bank WHERE course_id = ? GROUP BY category ORDER BY category', [$courseId]), 'n', 'category');
}

function question_bank_row(array $row): array
{
    return ['text' => $row['question_text'], 'options' => json_decode($row['options_json'], true), 'correct' => (int)$row['correct'], 'bank_id' => (int)$row['id']];
}

/** ชุดข้อสอบที่นักเรียนจะได้ในครั้งนี้ (สุ่มจากคลัง หรือข้อที่ครูสร้าง สลับลำดับถ้าเลือกไว้) */
function quiz_draw($raw, int $courseId): array
{
    $config = quiz_parse($raw);
    if ($config['source'] === 'bank') {
        if (!question_bank_ready() || $config['count'] < 1) return [];
        $categories = is_array($config['categories']) ? $config['categories'] : [];
        $sql = 'SELECT * FROM question_bank WHERE course_id = ?';
        if ($categories) $sql .= ' AND category IN (' . implode(',', array_fill(0, count($categories), '?')) . ')';
        return array_map('question_bank_row', db_all($sql . ' ORDER BY RAND() LIMIT ' . (int)$config['count'], array_merge([$courseId], $categories)));
    }
    $questions = quiz_questions($raw);
    if ($config['shuffle']) shuffle($questions);
    return $questions;
}

function quiz_grade(array $questions, $answers): int
{
    if (!is_array($answers) || count($answers) !== count($questions)) throw new RuntimeException('กรุณาตอบให้ครบทุกข้อ');
    $score = 0;
    foreach ($questions as $i => $q) {
        $answer = $answers[$i] ?? null;
        if (!is_scalar($answer) || !ctype_digit((string)$answer) || !isset($q['options'][(int)$answer])) throw new RuntimeException('คำตอบไม่ถูกต้อง กรุณาตอบให้ครบทุกข้อ');
        if ((int)$answer === $q['correct']) $score++;
    }
    return $score;
}

/** ตัวอักษร/เลขตัวเลือก (ก–ญ, a–j, 1–10) → ลำดับ 0–9 หรือ null */
function quiz_choice_index(string $value): ?int
{
    $value = mb_strtolower(trim($value, " \t.)"));
    $index = array_search($value, QUIZ_LETTERS, true);
    if ($index === false) $index = array_search($value, range('a', 'j'), true);
    if ($index === false && ctype_digit($value) && $value >= 1 && $value <= 10) $index = (int)$value - 1;
    return $index === false ? null : (int)$index;
}

/**
 * นำเข้าข้อสอบจากข้อความหรือไฟล์ คืน [['text','options','correct','category'], ...]
 * รองรับ 2 รูปแบบ (ดู admin/quiz_import.php): ข้อความ (เลขข้อ + ก. ข. ค. + * หรือบรรทัด "เฉลย ข")
 * และตาราง CSV/คัดลอกจาก Excel (หมวด | โจทย์ | เฉลย | ตัวเลือก ก | ข | …)
 */
function question_import(string $content, bool $table = false): array
{
    if (!mb_check_encoding($content, 'UTF-8')) $content = (string)iconv('WINDOWS-874', 'UTF-8//IGNORE', $content); // CSV จาก Excel ภาษาไทย (ANSI)
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $firstLine = strtok(ltrim($content), "\r\n") ?: '';
    $questions = $table || str_contains($firstLine, "\t") || preg_match('/^"?\s*หมวด\s*"?\s*,/u', $firstLine)
        ? question_import_table($content, str_contains($firstLine, "\t") ? "\t" : ',')
        : question_import_text($content);
    if (!$questions) throw new RuntimeException('ไม่พบข้อสอบในข้อความหรือไฟล์ที่นำเข้า');
    if (count($questions) > 500) throw new RuntimeException('นำเข้าได้ครั้งละไม่เกิน 500 ข้อ');
    foreach ($questions as $i => $q) {
        try {
            quiz_validate_questions([$q]);
        } catch (RuntimeException $ex) {
            throw new RuntimeException('ข้อที่ ' . ($i + 1) . ': ' . $ex->getMessage());
        }
    }
    return $questions;
}

function question_import_table(string $content, string $delimiter): array
{
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $content);
    rewind($handle);
    $questions = [];
    for ($line = 1; ($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false; $line++) {
        $row = array_map(fn($cell) => trim((string)$cell), $row);
        if (!array_filter($row, 'strlen')) continue;
        if ($line === 1 && ($row[1] ?? '') === 'โจทย์') continue;
        $options = array_values(array_filter(array_slice($row, 3, 10), 'strlen'));
        $correct = quiz_choice_index($row[2] ?? '');
        if (($row[1] ?? '') === '' || count($options) < 2) throw new RuntimeException('แถวที่ ' . $line . ': ต้องมีโจทย์ (คอลัมน์ B) และตัวเลือกอย่างน้อย 2 ตัวเลือก (คอลัมน์ D เป็นต้นไป)');
        if ($correct === null || !isset($options[$correct])) throw new RuntimeException('แถวที่ ' . $line . ': เฉลย (คอลัมน์ C) ต้องเป็น ก ข ค … หรือ 1 2 3 … ตามตัวเลือกที่มี');
        $questions[] = ['text' => $row[1], 'options' => $options, 'correct' => $correct, 'category' => mb_substr($row[0], 0, 100)];
    }
    fclose($handle);
    return $questions;
}

function question_import_text(string $text): array
{
    $questions = [];
    $current = null;
    $category = '';
    $optionPattern = '/^\s*(?:[ก-ญ]|[a-jA-J])\s*[\.\)]\s*(.+)$/u';
    $finish = function () use (&$current, &$questions) {
        if (!$current) return;
        if ($current['correct'] === null) throw new RuntimeException('ข้อที่ ' . (count($questions) + 1) . ' (บรรทัด ' . $current['line'] . ') ยังไม่ได้ระบุคำตอบที่ถูก — ใส่ * ท้ายตัวเลือก หรือบรรทัด "เฉลย ข"');
        unset($current['line']);
        $questions[] = $current;
        $current = null;
    };
    foreach (preg_split('/\R/u', $text) as $n => $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/^หมวด\s*[:：]\s*(.+)$/u', $line, $m)) {
            $finish();
            $category = mb_substr(trim($m[1]), 0, 100);
        } elseif (preg_match('/^(?:ข้อ(?:ที่)?\s*)?\d+\s*[\.\)]?\s+(.+)$/u', $line, $m) && !preg_match($optionPattern, $line)) {
            $finish();
            $current = ['text' => trim($m[1]), 'options' => [], 'correct' => null, 'category' => $category, 'line' => $n + 1];
        } elseif ($current && preg_match('/^(?:เฉลย|คำตอบ|ตอบ)\s*[:：]?\s*(\S+)/u', $line, $m) && $current['options']) {
            $current['correct'] = quiz_choice_index($m[1]);
            if ($current['correct'] === null || !isset($current['options'][$current['correct']])) throw new RuntimeException('บรรทัดที่ ' . ($n + 1) . ': เฉลยต้องเป็นตัวเลือกที่มีอยู่ เช่น "เฉลย ข"');
        } elseif ($current && preg_match($optionPattern, $line, $m)) {
            $option = trim($m[1]);
            if (str_ends_with($option, '*') || str_starts_with($option, '*')) {
                $current['correct'] = count($current['options']);
                $option = trim($option, " *\t");
            }
            $current['options'][] = $option;
        } elseif ($current && !$current['options']) {
            $current['text'] .= "\n" . $line;
        } else {
            throw new RuntimeException('อ่านบรรทัดที่ ' . ($n + 1) . ' ไม่ได้: "' . mb_substr($line, 0, 60) . '" — โจทย์ต้องขึ้นต้นด้วยเลขข้อ ตัวเลือกขึ้นต้นด้วย ก. ข. ค. …');
        }
    }
    $finish();
    return $questions;
}

/** คู่แบบทดสอบก่อน–หลังเรียนของรายวิชา: [['pre' => แหล่ง, 'post' => แหล่ง, 'pre_key' => 'บทเรียน:สื่อ', 'post_key' => ...], ...] */
function prepost_pairs(int $courseId): array
{
    $quizzes = [];
    foreach (grade_sources($courseId) as $key => $source) if ($source['media_type'] === 'quiz') $quizzes[substr($key, 5)] = $source;
    $pairs = [];
    foreach ($quizzes as $key => $source) {
        $config = quiz_parse((string)$source['quiz_data']);
        if ($config['kind'] === 'post' && isset($quizzes[$config['pair']])) {
            $pairs[] = ['pre' => $quizzes[$config['pair']], 'post' => $source, 'pre_key' => $config['pair'], 'post_key' => $key];
        }
    }
    return $pairs;
}

/** คะแนนรายคน [user_id => ['score','total','percent','attempts']] ของแบบทดสอบ 'บทเรียน:สื่อ' (policy: first | highest | latest) */
function quiz_scores_by_user(string $key, string $policy = 'first', int $userId = 0): array
{
    [$lessonId, $mediaId] = array_map('intval', explode(':', $key));
    $sql = 'SELECT user_id, score, total FROM quiz_attempts WHERE lesson_id = ? AND source_media_id = ?' . ($userId ? ' AND user_id = ?' : '') . ' ORDER BY id';
    $scores = [];
    foreach (db_all($sql, array_merge([$lessonId, $mediaId], $userId ? [$userId] : [])) as $a) {
        $a['percent'] = $a['total'] ? round($a['score'] * 100 / $a['total'], 2) : 0.0;
        $current = $scores[$a['user_id']] ?? null;
        $a['attempts'] = ($current['attempts'] ?? 0) + 1;
        if (!$current || $policy === 'latest' || ($policy === 'highest' && $a['percent'] > $current['percent'])) $scores[$a['user_id']] = $a;
        else $scores[$a['user_id']]['attempts'] = $a['attempts'];
    }
    return $scores;
}

/** สถิติผลต่างคะแนน (ร้อยละ) ของนักเรียนที่ทำครบทั้งก่อนและหลังเรียน */
function prepost_stats(array $pre, array $post): array
{
    $diffs = []; $preSum = 0; $postSum = 0;
    foreach ($post as $userId => $p) {
        if (!isset($pre[$userId])) continue;
        $diffs[] = $p['percent'] - $pre[$userId]['percent'];
        $preSum += $pre[$userId]['percent']; $postSum += $p['percent'];
    }
    $n = count($diffs);
    if (!$n) return ['n' => 0];
    $mean = array_sum($diffs) / $n;
    $sd = $n > 1 ? sqrt(array_sum(array_map(fn($d) => ($d - $mean) ** 2, $diffs)) / ($n - 1)) : 0.0;
    return ['n' => $n, 'pre' => round($preSum / $n, 2), 'post' => round($postSum / $n, 2), 'diff' => round($mean, 2), 'sd' => round($sd, 2),
        't' => $n > 1 && $sd > 0 ? round($mean / ($sd / sqrt($n)), 3) : null, 'improved' => count(array_filter($diffs, fn($d) => $d > 0))];
}
