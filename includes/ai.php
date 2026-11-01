<?php
// โมดูลเสริม AI — ผู้ช่วยครู (AI Teaching Assistant) และผู้ช่วยผู้เรียน (AI Learning Assistant)
// ไฟล์นี้รันได้ทุกเวอร์ชัน PHP ส่วนเรียก Claude API อยู่ที่ ai_client.php (ต้องใช้ PHP 8.1+ และ Anthropic PHP SDK)

/** รุ่นโมเดลที่เลือกได้ (ราคา USD ต่อ 1 ล้าน token: input / output) */
function ai_models(): array
{
    return [
        'claude-opus-5-5'   => ['label' => 'Claude Opus 5.5 — คุณภาพสูงสุด (ค่าเริ่มต้น)', 'in' => 4.00, 'out' => 20.00],
        'claude-sonnet-5-5' => ['label' => 'Claude Sonnet 5.5 — สมดุลคุณภาพ/ราคา', 'in' => 2.00, 'out' => 10.00],
        'claude-haiku-5-5'  => ['label' => 'Claude Haiku 5.5 — เร็วและประหยัดที่สุด', 'in' => 0.10, 'out' => 0.50],
    ];
}

function ai_model(): string
{
    $m = setting('ai_model', 'claude-opus-5-5');
    return array_key_exists($m, ai_models()) ? $m : 'claude-opus-5-5';
}

/** เหตุผลที่ยังใช้ AI ไม่ได้ (null = พร้อมใช้งาน) */
function ai_unavailable_reason(): ?string
{
    if (setting('ai_enabled') !== '1') return 'ผู้ดูแลระบบยังไม่ได้เปิดใช้งานโมดูล AI';
    if (PHP_VERSION_ID < 80100) return 'เซิร์ฟเวอร์ใช้ PHP ' . PHP_VERSION . ' — โมดูล AI ต้องใช้ PHP 8.1 ขึ้นไป';
    if (!is_file(ROOT_PATH . '/vendor/autoload.php')) return 'ยังไม่ได้ติดตั้ง Anthropic PHP SDK (รัน composer install ในโฟลเดอร์เว็บ)';
    if (trim(setting('ai_api_key')) === '') return 'ยังไม่ได้ใส่ Claude API key ที่ หลังบ้าน → ตั้งค่า AI';
    return null;
}

function ai_teacher_available(): bool
{
    return ai_unavailable_reason() === null && setting('ai_teacher_enabled', '1') === '1';
}

/** ผู้ช่วยผู้เรียนใช้ได้ในวิชานี้หรือไม่ */
function ai_student_available(array $course): bool
{
    return ai_unavailable_reason() === null && setting('ai_student_enabled', '1') === '1' && !empty($course['ai_student_enabled']);
}

/** จำนวนครั้งที่ใช้ได้อีกวันนี้ */
function ai_quota_left(array $user): int
{
    $limit = (int)setting($user['role'] === 'student' ? 'ai_student_daily_limit' : 'ai_teacher_daily_limit', '30');
    if ($user['role'] === 'admin') $limit = max($limit, 200);
    $used = (int)db_value("SELECT COUNT(*) FROM ai_logs WHERE user_id = ? AND created_at >= CURDATE() AND status <> 'error'", [(int)$user['id']]);
    return max(0, $limit - $used);
}

function ai_log(array $user, string $feature, ?int $courseId, array $r): void
{
    db_exec('INSERT INTO ai_logs (user_id, role, feature, course_id, model, input_tokens, output_tokens, cache_read_tokens, status, error) VALUES (?,?,?,?,?,?,?,?,?,?)', [
        (int)$user['id'], $user['role'], $feature, $courseId, $r['model'] ?? ai_model(),
        (int)($r['input_tokens'] ?? 0), (int)($r['output_tokens'] ?? 0), (int)($r['cache_read_tokens'] ?? 0),
        $r['status'], isset($r['error']) ? mb_substr($r['error'], 0, 255) : null,
    ]);
}

/**
 * เรียก Claude — คืน ['status' => ok|refusal|error, 'text' => HTML ที่กรองแล้ว, 'error' => ข้อความ]
 * ตรวจโควตาและบันทึก log ให้อัตโนมัติ
 */
function ai_run(array $user, string $feature, ?int $courseId, string $system, array $messages, string $effort, int $maxTokens): array
{
    if ($reason = ai_unavailable_reason()) {
        return ['status' => 'error', 'text' => '', 'error' => $reason];
    }
    if (ai_quota_left($user) <= 0) {
        return ['status' => 'error', 'text' => '', 'error' => 'ใช้ผู้ช่วย AI ครบโควตาของวันนี้แล้ว ลองใหม่พรุ่งนี้'];
    }
    @set_time_limit(300);
    require_once __DIR__ . '/ai_client.php';
    $r = ai_client_complete(trim(setting('ai_api_key')), ai_model(), $system, $messages, $effort, $maxTokens);
    ai_log($user, $feature, $courseId, $r);
    $r['text'] = $r['status'] === 'ok' ? clean_html(ai_strip_fences($r['text'] ?? '')) : '';
    if ($r['status'] === 'refusal') {
        $r['error'] = 'AI ปฏิเสธคำขอนี้ ลองปรับคำถามให้เกี่ยวกับเนื้อหารายวิชา';
    }
    return $r;
}

/** ตัด ```html ... ``` ที่บางครั้งโมเดลครอบมา */
function ai_strip_fences(string $s): string
{
    return preg_replace('/^\s*```[a-z]*\s*|\s*```\s*$/i', '', $s);
}

/** ข้อความล้วนจาก HTML สำหรับส่งเป็นบริบท */
function ai_plain(?string $html): string
{
    $t = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>', '</h3>', '</h4>', '</tr>'], "\n", (string)$html)), ENT_QUOTES, 'UTF-8');
    return trim(preg_replace("/\n{3,}/", "\n\n", $t));
}

/**
 * เนื้อหารายวิชาเป็นบริบทให้ AI (เฉพาะบทเรียนที่เผยแพร่ หรือทั้งหมดสำหรับครู)
 * $focusLessonId: บทเรียนที่ใส่เต็ม ส่วนบทอื่นใส่ได้ไม่เกินงบตัวอักษรที่เหลือ
 */
function ai_course_material(array $course, bool $publishedOnly, ?int $focusLessonId = null, int $budget = 60000, array $onlyLessonIds = []): string
{
    $types = media_types();
    $sql = 'SELECT * FROM lessons WHERE course_id = ?' . ($publishedOnly ? ' AND is_published = 1' . lesson_schedule_sql() : '') . ' ORDER BY sort_order, id';
    $lessons = db_all($sql, [(int)$course['id']]);
    if ($onlyLessonIds) {
        $lessons = array_values(array_filter($lessons, fn($l) => in_array((int)$l['id'], $onlyLessonIds, true)));
    }
    $out = "รายวิชา: {$course['code']} {$course['title']}\n" . ($course['credits'] ? "หน่วยกิต: {$course['credits']}\n" : '')
        . "คำอธิบายรายวิชา: " . trim((string)$course['description']) . "\n\nบทเรียนในรายวิชา:\n";
    foreach ($lessons as $i => $l) {
        $out .= ($i + 1) . '. ' . $l['title'] . ' [' . $types[$l['media_type']]['label'] . ']' . ((int)$l['id'] === $focusLessonId ? '  ← บทเรียนปัจจุบัน' : '') . "\n";
    }
    // บทเรียนปัจจุบันก่อน แล้วบทอื่นตามลำดับ
    usort($lessons, fn($a, $b) => ((int)$b['id'] === $focusLessonId) <=> ((int)$a['id'] === $focusLessonId));
    $used = mb_strlen($out);
    foreach ($lessons as $l) {
        $body = ai_plain($l['content']);
        if ($l['media_type'] === 'youtube' || $l['media_type'] === 'link') {
            $body .= ($body ? "\n" : '') . '(สื่อประกอบ: ' . $l['media_url'] . ' — AI ไม่เห็นเนื้อหาภายในวิดีโอ/ลิงก์)';
        } elseif ($l['file_path'] || $l['media_url']) {
            $body .= ($body ? "\n" : '') . '(มีไฟล์' . $types[$l['media_type']]['label'] . 'ประกอบ — AI ไม่เห็นเนื้อหาในไฟล์)';
        }
        $block = "\n### " . $l['title'] . "\n" . ($body ?: '(ไม่มีเนื้อหาข้อความ)') . "\n";
        if ($used + mb_strlen($block) > $budget && (int)$l['id'] !== $focusLessonId) {
            $out .= "\n(บทเรียนที่เหลือไม่ได้ใส่ เนื่องจากเนื้อหายาวเกินขอบเขตที่ตั้งไว้)\n";
            break;
        }
        $out .= $block;
        $used += mb_strlen($block);
    }
    return $out;
}

/** รูปแบบ HTML ที่ขอให้ AI ใช้ (ตรงกับตัวกรอง clean_html และตัวแก้ไข WYSIWYG) */
function ai_html_rules(): string
{
    return 'ตอบเป็น HTML ล้วน ใช้ได้เฉพาะแท็ก <h3> <h4> <p> <ul> <ol> <li> <b> <i> <u> <table> <thead> <tbody> <tr> <th> <td> <blockquote> <hr> <code> <pre> '
        . 'ห้ามใช้ Markdown ห้ามครอบด้วย ``` ห้ามใส่ <html> <head> <body> <style> <script> หรือ style';
}
