<?php
// AI Teaching Assistant: เครื่องมือของครู + คำสั่ง (prompt) ของแต่ละเครื่องมือ

function ai_teacher_tools(): array
{
    return [
        'lesson'   => ['label' => 'ร่างบทเรียน', 'icon' => 'bi-journal-text', 'color' => 'primary', 'publishable' => true,
            'desc' => 'ร่างเนื้อหาบทเรียนพร้อมจุดประสงค์ เนื้อหา ตัวอย่าง และกิจกรรม'],
        'exercise' => ['label' => 'สร้างแบบฝึกหัด', 'icon' => 'bi-pencil-square', 'color' => 'success', 'publishable' => true,
            'desc' => 'แบบฝึกหัด/ใบงานจากบทเรียนที่เลือก พร้อมแนวคำตอบสำหรับครู'],
        'quiz'     => ['label' => 'สร้างข้อสอบ', 'icon' => 'bi-ui-checks', 'color' => 'danger', 'publishable' => true,
            'desc' => 'ข้อสอบปรนัย/อัตนัยจากเนื้อหาที่กำหนด พร้อมเฉลยและจุดประสงค์ที่วัด'],
        'rubric'   => ['label' => 'สร้างเกณฑ์ Rubric', 'icon' => 'bi-table', 'color' => 'warning', 'publishable' => true,
            'desc' => 'เกณฑ์การให้คะแนนแบบ Rubric สำหรับงาน/ชิ้นงาน/การปฏิบัติ'],
        'summary'  => ['label' => 'สรุปผลการเรียน', 'icon' => 'bi-graph-up', 'color' => 'info', 'publishable' => false,
            'desc' => 'วิเคราะห์ความก้าวหน้าของผู้เรียนในวิชา (ข้อมูลไม่ระบุตัวตน)'],
        'improve'  => ['label' => 'แนวทางปรับปรุงการสอน', 'icon' => 'bi-lightbulb', 'color' => 'secondary', 'publishable' => false,
            'desc' => 'ข้อเสนอแนะปรับปรุงบทเรียนและกิจกรรมจากโครงสร้างวิชาและผลการเรียน'],
    ];
}

/** สถิติการเรียนแบบไม่ระบุตัวตน (ไม่ส่งชื่อ/รหัส/อีเมลผู้เรียนให้ AI) */
function ai_course_stats(int $courseId): string
{
    $lessons = db_all('SELECT l.id, l.title, l.media_type, (SELECT COUNT(*) FROM lesson_progress p WHERE p.lesson_id = l.id) AS done
                       FROM lessons l WHERE l.course_id = ? AND l.is_published = 1 ORDER BY l.sort_order, l.id', [$courseId]);
    $students = db_all(
        "SELECT u.id, (SELECT GROUP_CONCAT(DISTINCT g.name) FROM group_members gm JOIN student_groups g ON g.id = gm.group_id
                        JOIN course_groups cg ON cg.group_id = g.id AND cg.course_id = ? WHERE gm.user_id = u.id) AS grp,
                (SELECT COUNT(*) FROM lesson_progress p JOIN lessons l ON l.id = p.lesson_id WHERE p.user_id = u.id AND l.course_id = ? AND l.is_published = 1) AS done,
                (SELECT MAX(p.completed_at) FROM lesson_progress p JOIN lessons l ON l.id = p.lesson_id WHERE p.user_id = u.id AND l.course_id = ?) AS last_at
         FROM users u WHERE u.role = 'student' AND u.id IN " . student_course_ids_sql_for_course(),
        [$courseId, $courseId, $courseId, $courseId, $courseId]
    );
    $total = count($lessons);
    $n = count($students);
    $out = "ข้อมูลผลการเรียน ณ " . date('Y-m-d') . " (ไม่ระบุตัวตนผู้เรียน)\nจำนวนผู้เรียน: $n คน · บทเรียนที่เผยแพร่: $total บท\n\nอัตราการเรียนจบรายบท:\n";
    foreach ($lessons as $i => $l) {
        $out .= ($i + 1) . ". {$l['title']} [{$l['media_type']}] — เรียนจบ {$l['done']}/$n คน\n";
    }
    $buckets = ['ยังไม่เริ่ม (0%)' => 0, '1-49%' => 0, '50-99%' => 0, 'จบครบ (100%)' => 0];
    $inactive = 0;
    $byGroup = [];
    foreach ($students as $s) {
        $p = $total ? $s['done'] / $total : 0;
        $buckets[$p == 0 ? 'ยังไม่เริ่ม (0%)' : ($p < .5 ? '1-49%' : ($p < 1 ? '50-99%' : 'จบครบ (100%)'))]++;
        if (!$s['last_at'] || strtotime($s['last_at']) < strtotime('-14 days')) $inactive++;
        $g = $s['grp'] ?: 'ลงทะเบียนรายบุคคล';
        $byGroup[$g][] = $p;
    }
    $out .= "\nการกระจายความก้าวหน้า: ";
    foreach ($buckets as $k => $v) $out .= "$k = $v คน; ";
    $out .= "\nไม่มีความเคลื่อนไหวเกิน 14 วัน: $inactive คน\n\nความก้าวหน้าเฉลี่ยรายกลุ่ม:\n";
    foreach ($byGroup as $g => $ps) {
        $out .= "- $g: " . count($ps) . ' คน, เฉลี่ย ' . round(array_sum($ps) / count($ps) * 100) . "%\n";
    }
    return $out;
}

/** id ผู้เรียนที่มีสิทธิ์ในวิชา (ลงทะเบียนเอง + ผ่านกลุ่ม) ใช้ 2 พารามิเตอร์ course_id */
function student_course_ids_sql_for_course(): string
{
    return '(SELECT user_id FROM enrollments WHERE course_id = ?
             UNION SELECT gm.user_id FROM course_groups cg JOIN group_members gm ON gm.group_id = cg.group_id WHERE cg.course_id = ?)';
}

/** system prompt สำหรับผู้ช่วยครู */
function ai_teacher_system(array $course, string $material): string
{
    return "คุณคือผู้ช่วยครูผู้สอนระดับอาชีวศึกษาของไทย (ปวช./ปวส.) ในระบบ " . setting('site_name') . "\n"
        . "หน้าที่: ช่วยครูร่างสื่อการสอน แบบฝึกหัด ข้อสอบ เกณฑ์การประเมิน และวิเคราะห์ผลการเรียน\n"
        . "หลักการ:\n"
        . "- เขียนภาษาไทยที่ถูกต้อง ชัดเจน เหมาะกับผู้เรียนสายอาชีพ เน้นการนำไปปฏิบัติจริงในงานอาชีพ\n"
        . "- ยึดคำอธิบายรายวิชาและเนื้อหาใน <course_material> เป็นหลัก ถ้าต้องใช้ความรู้นอกเหนือจากนั้น ให้ระบุว่าเป็นเนื้อหาเสริม\n"
        . "- ผลงานทั้งหมดเป็น \"ฉบับร่าง\" ที่ครูจะตรวจแก้ก่อนใช้ ถ้าไม่แน่ใจข้อเท็จจริงใด (เช่น ตัวเลข มาตรฐาน กฎหมาย) ให้ทำเครื่องหมาย [ตรวจสอบ] ไว้\n"
        . "- ไม่ต้องเกริ่นนำหรือปิดท้ายด้วยการสนทนา ให้ส่งเฉพาะเนื้อหาที่ใช้ได้ทันที\n"
        . "- " . ai_html_rules() . "\n\n"
        . "<course_material>\n" . $material . "\n</course_material>";
}

/** ข้อความคำขอของแต่ละเครื่องมือ */
function ai_teacher_prompt(string $tool, array $in, string $stats = ''): string
{
    $extra = trim($in['extra'] ?? '') !== '' ? "\nคำสั่งเพิ่มเติมจากครู: " . trim($in['extra']) : '';
    $level = trim($in['level'] ?? '') ?: 'ปวช./ปวส.';
    $topic = trim($in['topic'] ?? '');
    switch ($tool) {
        case 'lesson':
            return "ร่างบทเรียนเรื่อง \"$topic\" สำหรับผู้เรียนระดับ $level เวลาเรียนประมาณ " . ((int)($in['periods'] ?? 0) ?: 2) . " คาบ\n"
                . "โครงสร้าง: <h3>จุดประสงค์การเรียนรู้</h3> (เชิงพฤติกรรม 3-5 ข้อ) → <h3>เนื้อหา</h3> (แบ่งหัวข้อย่อย มีตัวอย่างจากงานอาชีพจริง) → "
                . "<h3>กิจกรรมการเรียนรู้</h3> (ขั้นนำ/ขั้นสอน/ขั้นสรุป) → <h3>สรุปสาระสำคัญ</h3> → <h3>คำถามทบทวน</h3> 3-5 ข้อ" . $extra;
        case 'exercise':
            return "สร้างแบบฝึกหัด " . ((int)($in['count'] ?? 0) ?: 10) . " ข้อ สำหรับผู้เรียนระดับ $level จากเนื้อหาใน <course_material>"
                . ($topic ? " เน้นเรื่อง \"$topic\"" : '') . "\nรูปแบบ: " . (trim($in['style'] ?? '') ?: 'ผสม (ตอบสั้น อธิบาย และฝึกปฏิบัติ/สถานการณ์จำลอง)')
                . "\nเรียงจากง่ายไปยาก มีคำชี้แจงสำหรับผู้เรียนก่อนข้อแรก\n"
                . "ท้ายสุดให้ใส่ <hr><h3>แนวคำตอบสำหรับครู</h3> แยกจากส่วนของผู้เรียนชัดเจน" . $extra;
        case 'quiz':
            $type = ['mcq' => 'ปรนัย 4 ตัวเลือก (ก ข ค ง)', 'essay' => 'อัตนัย', 'mixed' => 'ปรนัย 4 ตัวเลือกและอัตนัยผสมกัน'][$in['qtype'] ?? 'mcq'] ?? 'ปรนัย 4 ตัวเลือก';
            $diff = ['easy' => 'ง่าย', 'medium' => 'ปานกลาง', 'hard' => 'ยาก (วิเคราะห์/ประยุกต์)', 'mixed' => 'คละระดับ'][$in['difficulty'] ?? 'mixed'] ?? 'คละระดับ';
            return "สร้างข้อสอบแบบ $type จำนวน " . ((int)($in['count'] ?? 0) ?: 10) . " ข้อ ความยาก: $diff สำหรับผู้เรียนระดับ $level\n"
                . "ออกข้อสอบจากเนื้อหาใน <course_material> เท่านั้น" . ($topic ? " เน้นเรื่อง \"$topic\"" : '') . "\n"
                . "ข้อปรนัยต้องมีคำตอบถูกเพียงข้อเดียว ตัวลวงสมเหตุสมผล หลีกเลี่ยง \"ถูกทุกข้อ/ผิดทุกข้อ\"\n"
                . "ท้ายสุดให้ใส่ <hr><h3>เฉลยสำหรับครู</h3> เป็นตาราง: ข้อ | คำตอบ | เหตุผลสั้น ๆ | จุดประสงค์/ระดับพฤติกรรมที่วัด" . $extra;
        case 'rubric':
            return "สร้างเกณฑ์การให้คะแนนแบบ Rubric สำหรับประเมิน \"$topic\" ของผู้เรียนระดับ $level\n"
                . "ทำเป็นตาราง: แถว = เกณฑ์/ประเด็นการประเมิน " . ((int)($in['count'] ?? 0) ?: 4) . " ประเด็น, คอลัมน์ = ระดับคุณภาพ 4 (ดีมาก) 3 (ดี) 2 (พอใช้) 1 (ปรับปรุง) "
                . "แต่ละช่องอธิบายพฤติกรรมที่สังเกตได้ชัดเจน วัดได้จริง ใส่น้ำหนักคะแนนแต่ละประเด็น และเกณฑ์การตัดสินผ่านใต้ตาราง" . $extra;
        case 'summary':
            return "วิเคราะห์และสรุปผลการเรียนของรายวิชานี้จากข้อมูลด้านล่าง สำหรับครูผู้สอน\n"
                . "หัวข้อ: <h3>ภาพรวม</h3> <h3>จุดที่น่าสังเกต</h3> (บทเรียนที่มีผู้เรียนจบน้อย กลุ่มที่ตามไม่ทัน) "
                . "<h3>ผู้เรียนที่ควรได้รับการติดตาม</h3> (อธิบายเป็นกลุ่ม/ช่วงความก้าวหน้า ห้ามคาดเดาตัวบุคคล) <h3>ข้อเสนอแนะสำหรับครู</h3>\n"
                . "ใช้ตัวเลขจากข้อมูลจริงเท่านั้น\n\n<learning_data>\n$stats\n</learning_data>" . $extra;
        case 'improve':
            return "เสนอแนวทางปรับปรุงการจัดการเรียนการสอนของรายวิชานี้ จากโครงสร้างบทเรียน ชนิดสื่อ และผลการเรียนด้านล่าง\n"
                . (trim($in['topic'] ?? '') ? "ปัญหา/ข้อสังเกตจากครู: $topic\n" : '')
                . "หัวข้อ: <h3>จุดแข็งของรายวิชา</h3> <h3>ประเด็นที่ควรปรับปรุง</h3> <h3>ข้อเสนอแนะกิจกรรม</h3> (เช่น Active Learning, PBL, งานปฏิบัติจริง "
                . "พร้อมตัวอย่างกิจกรรมที่ใช้ได้ทันที) <h3>สื่อที่ควรเพิ่ม</h3> <h3>การวัดและประเมินผล</h3>\n\n<learning_data>\n$stats\n</learning_data>" . $extra;
    }
    return $topic . $extra;
}
