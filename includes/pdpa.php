<?php
// PDPA (พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562): นโยบาย ความยินยอม คำร้องใช้สิทธิ

function privacy_version(): string
{
    return setting('privacy_version', '1');
}

function pdpa_org_name(): string
{
    return setting('pdpa_org_name') ?: setting('site_name', 'Vocational Teacher');
}

function pdpa_contact(): string
{
    return setting('pdpa_contact') ?: 'ผู้ดูแลระบบ ผ่านเมนู "ข้อมูลส่วนตัว → คำร้องเกี่ยวกับข้อมูลส่วนบุคคล" ในระบบ';
}

function pdpa_request_types(): array
{
    return [
        'delete'  => 'ขอลบบัญชีและข้อมูลส่วนบุคคล / ถอนความยินยอม',
        'correct' => 'ขอแก้ไขข้อมูลให้ถูกต้อง',
        'object'  => 'คัดค้าน / ขอระงับการใช้ข้อมูล',
        'other'   => 'อื่น ๆ',
    ];
}

/** เนื้อหานโยบายที่ใช้แสดง (ค่าที่ผู้ดูแลแก้ไข หรือแม่แบบเริ่มต้น) */
function privacy_policy_html(): string
{
    $html = setting('privacy_policy');
    return clean_html($html !== '' ? $html : default_privacy_policy());
}

/** แม่แบบนโยบายเริ่มต้น — สถานศึกษาควรตรวจทานกับฝ่ายกฎหมาย/DPO ก่อนใช้งานจริง */
function default_privacy_policy(): string
{
    $org = e(pdpa_org_name());
    $site = e(setting('site_name', 'Vocational Teacher'));
    $contact = e(pdpa_contact());
    return <<<HTML
<p>{$org} ("ผู้ควบคุมข้อมูล") ให้ความสำคัญกับการคุ้มครองข้อมูลส่วนบุคคลของผู้ใช้งานระบบ {$site} ตามพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562 (PDPA) นโยบายนี้อธิบายว่าเราเก็บ ใช้ และเปิดเผยข้อมูลของท่านอย่างไร</p>

<h5>1. ข้อมูลส่วนบุคคลที่เก็บรวบรวม</h5>
<ul>
<li><b>ข้อมูลบัญชี:</b> ชื่อ-นามสกุล ชื่อผู้ใช้ รหัสผ่าน (เก็บแบบเข้ารหัสทางเดียว) อีเมล เบอร์โทรศัพท์ รหัสนักศึกษา/รหัสครู รูปโปรไฟล์</li>
<li><b>ข้อมูลการเรียน:</b> รายวิชาที่ลงทะเบียน กลุ่ม/ห้องเรียน บทเรียนที่เรียนจบและเวลาที่เรียน</li>
<li><b>ข้อมูลทางเทคนิค:</b> หมายเลข IP และข้อมูลเบราว์เซอร์ ณ เวลาที่ให้ความยินยอม และคุกกี้ที่จำเป็นต่อการเข้าสู่ระบบ</li>
</ul>

<h5>2. วัตถุประสงค์และฐานทางกฎหมาย</h5>
<ul>
<li>สร้างและจัดการบัญชีผู้ใช้ จัดการเรียนการสอน ติดตามความก้าวหน้าการเรียน — ฐาน<b>ภารกิจเพื่อประโยชน์สาธารณะด้านการศึกษา</b>และ<b>การปฏิบัติตามสัญญา/ข้อตกลงการใช้บริการ</b></li>
<li>ดูแลความปลอดภัยของระบบ ป้องกันการเข้าถึงโดยไม่ได้รับอนุญาต — ฐาน<b>ประโยชน์โดยชอบด้วยกฎหมาย</b></li>
<li>กรณีที่ต้องอาศัยความยินยอม เราจะขอความยินยอมจากท่านก่อน และท่านถอนความยินยอมได้ตลอดเวลา</li>
</ul>

<h5>3. การเปิดเผยข้อมูล</h5>
<p>ข้อมูลของท่านจะเปิดเผยเฉพาะต่อครูผู้สอนในรายวิชา/กลุ่มที่เกี่ยวข้อง และผู้ดูแลระบบของสถานศึกษา เราไม่ขายหรือให้ข้อมูลแก่บุคคลภายนอกเพื่อการตลาด
ระบบมีการโหลดบริการภายนอกบางส่วน ได้แก่ วิดีโอ YouTube (โหมดความเป็นส่วนตัวขั้นสูง youtube-nocookie.com), แบบอักษร Google Fonts และไฟล์ระบบจาก jsDelivr ซึ่งผู้ให้บริการเหล่านี้อาจได้รับหมายเลข IP ของท่านตามนโยบายของผู้ให้บริการนั้น</p>
<p><b>ผู้ช่วย AI:</b> หากสถานศึกษาเปิดใช้ผู้ช่วย AI ข้อความที่ท่านพิมพ์ถามและเนื้อหาบทเรียนที่เกี่ยวข้องจะถูกส่งไปประมวลผลที่บริการ Claude ของ Anthropic, PBC (สหรัฐอเมริกา) เพื่อสร้างคำตอบ
ระบบไม่ส่งชื่อ รหัสนักศึกษา หรืออีเมลของท่านไปกับคำถาม ไม่บันทึกข้อความคำถาม/คำตอบไว้ในระบบ (เก็บเฉพาะสถิติจำนวนครั้งการใช้งาน) และการวิเคราะห์ผลการเรียนสำหรับครูใช้เฉพาะข้อมูลสถิติที่ไม่ระบุตัวตน
กรุณาอย่าพิมพ์ข้อมูลส่วนบุคคลลงในช่องคำถาม</p>

<h5>4. ระยะเวลาการเก็บรักษา</h5>
<p>เราเก็บข้อมูลตลอดระยะเวลาที่ท่านเป็นผู้ใช้งาน และไม่เกินระยะเวลาที่จำเป็นตามวัตถุประสงค์หรือที่กฎหมาย/ระเบียบของสถานศึกษากำหนด เมื่อพ้นระยะเวลาดังกล่าวหรือได้รับคำขอลบที่ชอบด้วยกฎหมาย เราจะลบหรือทำให้ข้อมูลไม่สามารถระบุตัวตนได้</p>

<h5>5. สิทธิของเจ้าของข้อมูล</h5>
<p>ท่านมีสิทธิ (1) ขอเข้าถึงและขอรับสำเนาข้อมูล (2) ขอให้โอนย้ายข้อมูล (3) ขอแก้ไขข้อมูลให้ถูกต้อง (4) ขอลบหรือทำลายข้อมูล (5) ขอระงับการใช้ข้อมูล (6) คัดค้านการประมวลผล (7) ถอนความยินยอม และ (8) ร้องเรียนต่อสำนักงานคณะกรรมการคุ้มครองข้อมูลส่วนบุคคล (สคส.)</p>
<p>ท่านดาวน์โหลดข้อมูลของตนเองและยื่นคำร้องได้ที่เมนู <b>ข้อมูลส่วนตัว</b> เราจะพิจารณาคำร้องภายใน 30 วัน</p>

<h5>6. ผู้เยาว์</h5>
<p>กรณีผู้เรียนเป็นผู้เยาว์ สถานศึกษาจะดำเนินการเกี่ยวกับความยินยอมของผู้ใช้อำนาจปกครองตามที่กฎหมายกำหนด</p>

<h5>7. ความมั่นคงปลอดภัย</h5>
<p>เราใช้มาตรการที่เหมาะสม เช่น การเข้ารหัสรหัสผ่าน การจำกัดสิทธิ์ตามบทบาท และการสำรองข้อมูล เพื่อป้องกันการสูญหาย เข้าถึง ใช้ เปลี่ยนแปลง หรือเปิดเผยข้อมูลโดยไม่ได้รับอนุญาต</p>

<h5>8. คุกกี้</h5>
<p>ระบบใช้เฉพาะคุกกี้ที่จำเป็น (session) เพื่อให้ท่านเข้าสู่ระบบและใช้งานได้ ไม่ใช้คุกกี้เพื่อการโฆษณาหรือวิเคราะห์พฤติกรรม</p>

<h5>9. ติดต่อเรา</h5>
<p>{$contact}</p>
HTML;
}

/** บันทึกการให้/ปฏิเสธ/ถอนความยินยอม */
function record_consent(array $user, string $action): void
{
    db_exec('INSERT INTO consent_logs (user_id, username, policy_version, action, ip, user_agent) VALUES (?,?,?,?,?,?)', [
        (int)$user['id'], $user['username'], privacy_version(), $action,
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
    if ($action === 'accept') {
        db_exec('UPDATE users SET consent_version = ?, consent_at = NOW() WHERE id = ?', [privacy_version(), (int)$user['id']]);
    } else {
        db_exec('UPDATE users SET consent_version = NULL, consent_at = NULL WHERE id = ?', [(int)$user['id']]);
    }
}

/** ผู้ใช้ที่ล็อกอินแล้วแต่ยังไม่ยอมรับนโยบายเวอร์ชันปัจจุบัน → ส่งไปหน้ายอมรับ */
function pdpa_enforce_consent(): void
{
    $u = current_user();
    if (!$u || (string)$u['consent_version'] === privacy_version()) {
        return;
    }
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (in_array($page, ['consent.php', 'logout.php', 'privacy.php', 'my-data.php'], true)) {
        return;
    }
    redirect('consent.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
}

/** ข้อมูลส่วนบุคคลทั้งหมดของผู้ใช้ (สิทธิขอเข้าถึง/โอนย้ายข้อมูล) */
function pdpa_export_user(int $uid): array
{
    $user = db_one('SELECT id, username, fullname, email, phone, student_code, role, status, avatar, consent_version, consent_at, created_at FROM users WHERE id = ?', [$uid]);
    return [
        'exported_at' => date('c'),
        'controller' => pdpa_org_name(),
        'system' => setting('site_name') . ' v' . APP_VERSION,
        'profile' => $user,
        'groups' => db_all('SELECT g.name, g.level FROM group_members gm JOIN student_groups g ON g.id = gm.group_id WHERE gm.user_id = ?', [$uid]),
        'enrollments' => db_all('SELECT c.code, c.title, e.enrolled_at FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE e.user_id = ?', [$uid]),
        'teaching_courses' => db_all('SELECT c.code, c.title FROM course_teachers ct JOIN courses c ON c.id = ct.course_id WHERE ct.teacher_id = ?', [$uid]),
        'lesson_progress' => db_all('SELECT c.code AS course, l.title AS lesson, p.completed_at FROM lesson_progress p
                                     JOIN lessons l ON l.id = p.lesson_id JOIN courses c ON c.id = l.course_id WHERE p.user_id = ? ORDER BY p.completed_at', [$uid]),
        'consent_history' => db_all('SELECT policy_version, action, ip, created_at FROM consent_logs WHERE user_id = ? ORDER BY id', [$uid]),
        'pdpa_requests' => db_all('SELECT type, detail, status, admin_note, created_at, handled_at FROM pdpa_requests WHERE user_id = ? ORDER BY id', [$uid]),
    ];
}
