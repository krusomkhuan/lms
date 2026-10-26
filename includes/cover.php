<?php
// สร้างภาพปกรายวิชาอัตโนมัติ (SVG) — เลือกธีม/ไอคอน/สีจากคำสำคัญในชื่อวิชาและคำอธิบาย

/** ธีมภาพปก: คำสำคัญ (ไทย/อังกฤษ) → ไอคอน + ไล่สี */
function cover_themes(): array
{
    return [
        'ecommerce' => ['label' => 'พาณิชย์อิเล็กทรอนิกส์', 'colors' => ['#c2410c', '#f97316'],
            'words' => ['พาณิชย์อิเล็กทรอนิกส์', 'อีคอมเมิร์ซ', 'e-commerce', 'ecommerce', 'ร้านค้าออนไลน์', 'ขายสินค้า', 'สินค้า', 'ตะกร้าสินค้า', 'ชำระเงิน', 'payment', 'marketplace']],
        'ai'        => ['label' => 'ปัญญาประดิษฐ์ (AI)', 'colors' => ['#4c1d95', '#a855f7'],
            'words' => ['ปัญญาประดิษฐ์', ' ai', '(ai', 'ai ', 'artificial intelligence', 'machine learning', 'แมชชีนเลิร์นนิง', 'chatgpt', 'generative', 'แชตบอต', 'chatbot', 'prompt']],
        'database'  => ['label' => 'ฐานข้อมูล', 'colors' => ['#1e40af', '#06b6d4'],
            'words' => ['ฐานข้อมูล', 'database', 'sql', 'mysql', 'dbms', 'ตาราง', 'ความสัมพันธ์', 'er diagram', 'แบบจำลองข้อมูล', 'big data', 'บิ๊กดาต้า']],
        'media'     => ['label' => 'สื่อมัลติมีเดีย', 'colors' => ['#be123c', '#fb923c'],
            'words' => ['มัลติมีเดีย', 'multimedia', 'วิดีโอ', 'video', 'ตัดต่อ', 'ภาพเคลื่อนไหว', 'แอนิเมชัน', 'animation', 'เสียง', 'ถ่ายทำ', 'youtube', 'คอนเทนต์', 'content']],
        'code'      => ['label' => 'การพัฒนาเว็บ / โปรแกรม', 'colors' => ['#1e3a8a', '#7c3aed'],
            'words' => ['โปรแกรมคอมพิวเตอร์', 'เขียนโปรแกรม', 'โค้ด', 'เว็บไซต์', 'เว็บ', 'html', 'css', 'php', 'javascript', 'python', 'java', 'แอปพลิเคชัน', 'ซอฟต์แวร์', 'software', 'code', 'coding', 'อัลกอริทึม']],
        'project'   => ['label' => 'โครงงาน / นวัตกรรม', 'colors' => ['#0f766e', '#84cc16'],
            'words' => ['โครงงาน', 'โครงการ', 'project', 'นวัตกรรม', 'สิ่งประดิษฐ์', 'วิจัย', 'ฝึกงาน', 'ฝึกประสบการณ์', 'การนำเสนอ']],
        'digital'   => ['label' => 'เทคโนโลยีดิจิทัล', 'colors' => ['#0369a1', '#22c55e'],
            'words' => ['เทคโนโลยีดิจิทัล', 'ทำงานร่วมกัน', 'แบ่งปัน', 'คลาวด์', 'cloud', 'อุปกรณ์ดิจิทัล', 'ออนไลน์', 'โปรแกรมสำนักงาน', 'สมาร์ทโฟน']],
        'electric' => ['label' => 'ไฟฟ้า / อิเล็กทรอนิกส์', 'colors' => ['#b45309', '#f59e0b'],
            'words' => ['ไฟฟ้า', 'วงจร', 'อิเล็กทรอนิกส์', 'electric', 'circuit', 'โอห์ม', 'แรงดัน', 'กระแส', 'มอเตอร์', 'หม้อแปลง', 'ติดตั้งไฟ', 'โซลาร์']],
        'account'  => ['label' => 'การบัญชี / การเงิน', 'colors' => ['#065f46', '#10b981'],
            'words' => ['บัญชี', 'การเงิน', 'ภาษีอากร', 'ภาษี', 'งบการเงิน', 'ต้นทุน', 'accounting', 'finance', 'audit', 'เดบิต', 'เครดิต']],
        'business' => ['label' => 'ธุรกิจ / การตลาด', 'colors' => ['#0f766e', '#22d3ee'],
            'words' => ['ธุรกิจ', 'การตลาด', 'ตลาด', 'การขาย', 'digital', 'business', 'marketing', 'สำนักงาน', 'ผู้ประกอบการ', 'สตาร์ทอัพ', 'โลจิสติกส์', 'การจัดการ']],
        'network'  => ['label' => 'คอมพิวเตอร์ / เครือข่าย', 'colors' => ['#0c4a6e', '#0ea5e9'],
            'words' => ['เครือข่าย', 'network', 'คอมพิวเตอร์', 'computer', 'ฮาร์ดแวร์', 'hardware', 'ระบบปฏิบัติการ', 'เซิร์ฟเวอร์', 'server', 'cloud', 'คลาวด์', 'ไซเบอร์', 'cyber', 'iot', 'สารสนเทศ']],
        'design'   => ['label' => 'ออกแบบ / กราฟิก', 'colors' => ['#9d174d', '#f472b6'],
            'words' => ['ออกแบบ', 'กราฟิก', 'graphic', 'design', 'อินโฟกราฟิก', 'infographic', 'photoshop', 'illustrator', 'canva', 'ศิลป', 'ถ่ายภาพ', 'ตกแต่งภาพ', 'โลโก้', 'ui', 'ux']],
        'mechanic' => ['label' => 'ช่างอุตสาหกรรม', 'colors' => ['#334155', '#64748b'],
            'words' => ['ช่างยนต์', 'ยานยนต์', 'เครื่องกล', 'เครื่องยนต์', 'กลโรงงาน', 'เชื่อม', 'โลหะ', 'ซ่อมบำรุง', 'mechanic', 'engine', 'อุตสาหกรรม', 'cnc', 'แมคคาทรอนิกส์', 'หุ่นยนต์', 'robot']],
        'math'     => ['label' => 'คณิตศาสตร์', 'colors' => ['#3730a3', '#6366f1'],
            'words' => ['คณิต', 'math', 'สถิติ', 'statistic', 'สมการ', 'ตัวเลข', 'เรขาคณิต', 'แคลคูลัส']],
        'science'  => ['label' => 'วิทยาศาสตร์', 'colors' => ['#155e75', '#14b8a6'],
            'words' => ['วิทยาศาสตร์', 'science', 'เคมี', 'ฟิสิกส์', 'ชีววิทยา', 'ทดลอง', 'สิ่งแวดล้อม', 'พลังงาน']],
        'language' => ['label' => 'ภาษา / การสื่อสาร', 'colors' => ['#9a3412', '#fb7185'],
            'words' => ['ภาษา', 'อังกฤษ', 'english', 'จีน', 'ญี่ปุ่น', 'สื่อสาร', 'communication', 'การพูด', 'การเขียน', 'การอ่าน', 'วรรณ']],
        'learning' => ['label' => 'ทักษะการเรียนรู้', 'colors' => ['#0d3b66', '#1b998b'],
            'words' => ['ทักษะ', 'เรียนรู้', 'ออนไลน์', 'ชีวิต', 'พัฒนาตน', 'สังคม', 'จริยธรรม', 'คุณธรรม', 'สุขภาพ', 'พลศึกษา', 'แนะแนว', 'learning', 'skill']],
    ];
}

/**
 * เลือกธีมจากคำสำคัญ: พบในชื่อวิชาได้ 3 เท่าของคำอธิบาย
 * คำยาว (เจาะจงกว่า) ได้คะแนนมากกว่า เช่น "พาณิชย์อิเล็กทรอนิกส์" ชนะ "อิเล็กทรอนิกส์"
 */
function cover_theme(string $title, string $desc): string
{
    $best = 'learning';
    $bestScore = 0;
    $title = ' ' . strtolower($title) . ' ';
    $desc = ' ' . strtolower($desc) . ' ';
    foreach (cover_themes() as $key => $t) {
        $score = 0;
        foreach ($t['words'] as $w) {
            $w = strtolower($w);
            $score += (substr_count($title, $w) * 3 + substr_count($desc, $w)) * mb_strlen(trim($w));
        }
        if ($score > $bestScore) {
            $best = $key;
            $bestScore = $score;
        }
    }
    return $best;
}

/** ไอคอนลายเส้นสีขาว ในกรอบ 100x100 */
function cover_icon(string $theme): string
{
    $s = 'fill="none" stroke="#fff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"';
    switch ($theme) {
        case 'ecommerce':
            return "<g $s><path d='M6 14h12l10 48h52l10-34H24'/><path d='M30 62l-4 12h58'/></g><g fill='#fff'><circle cx='36' cy='86' r='6'/><circle cx='76' cy='86' r='6'/></g>"
                . "<path d='M46 32h28l-6 20H50z' fill='#fff' fill-opacity='.45'/>";
        case 'ai':
            $pins = '';
            foreach ([30, 44, 58, 72] as $p) {
                $pins .= "<path d='M{$p} 6v12M{$p} 82v12M6 {$p}h12M82 {$p}h12'/>";
            }
            return "<g $s>$pins<rect x='18' y='18' width='64' height='64' rx='10'/></g>"
                . "<text x='50' y='61' text-anchor='middle' font-family='Arial,sans-serif' font-size='30' font-weight='700' fill='#fff'>AI</text>";
        case 'database':
            return "<g $s><ellipse cx='50' cy='18' rx='34' ry='11'/><path d='M16 18v64c0 6 15 11 34 11s34-5 34-11V18'/><path d='M16 40c0 6 15 11 34 11s34-5 34-11M16 61c0 6 15 11 34 11s34-5 34-11'/></g>";
        case 'media':
            return "<g $s><rect x='6' y='16' width='88' height='60' rx='8'/><path d='M6 30h88M6 62h88M20 16v14M36 16v14M64 62v14M80 62v14'/></g>"
                . "<path d='M42 36v20l18-10z' fill='#fff'/><g $s><path d='M30 90h40'/></g>";
        case 'project':
            return "<g $s><rect x='18' y='12' width='64' height='80' rx='7'/><path d='M38 12v-2a4 4 0 0 1 4-4h16a4 4 0 0 1 4 4v2'/>"
                . "<path d='M30 36l5 5 9-10M30 56l5 5 9-10M30 76l5 5 9-10' stroke-width='4.5'/><path d='M52 38h18M52 58h18M52 78h12' stroke-width='4.5'/></g>";
        case 'digital':
            return "<g $s><rect x='6' y='16' width='64' height='44' rx='5'/><path d='M0 70h76'/><rect x='66' y='34' width='30' height='54' rx='6'/><path d='M77 80h8'/></g>"
                . "<g fill='#fff' fill-opacity='.5'><circle cx='26' cy='38' r='6'/><circle cx='46' cy='38' r='6'/></g>";
        case 'code':
            return "<g $s><rect x='8' y='14' width='84' height='62' rx='6'/><path d='M8 28h84'/><path d='M38 40l-12 12 12 12M62 40l12 12-12 12M54 36l-8 32'/><path d='M36 88h28M50 76v12'/></g>";
        case 'electric':
            return "<path d='M57 6 22 56h24l-6 38 38-54H54z' fill='#fff' fill-opacity='.95'/>";
        case 'account':
            return "<g $s><rect x='22' y='8' width='56' height='84' rx='8'/><rect x='31' y='18' width='38' height='16' rx='3'/></g>"
                . "<g fill='#fff'><circle cx='36' cy='48' r='4'/><circle cx='50' cy='48' r='4'/><circle cx='64' cy='48' r='4'/><circle cx='36' cy='62' r='4'/><circle cx='50' cy='62' r='4'/><circle cx='64' cy='62' r='4'/><circle cx='36' cy='76' r='4'/><circle cx='50' cy='76' r='4'/><rect x='60' y='58' width='8' height='22' rx='4'/></g>";
        case 'business':
            return "<g $s><path d='M10 90h82M14 90V12'/><path d='M22 70l18-18 14 12 26-30'/><path d='M66 34h14v14'/></g>"
                . "<g fill='#fff' fill-opacity='.45'><rect x='24' y='74' width='10' height='14'/><rect x='42' y='66' width='10' height='22'/><rect x='60' y='58' width='10' height='30'/><rect x='78' y='46' width='10' height='42'/></g>";
        case 'network':
            return "<g $s><path d='M50 22v20M50 58v14M28 72l22-14 22 14M22 30l28 12 28-12'/></g>"
                . "<g fill='#fff'><circle cx='50' cy='14' r='9'/><circle cx='50' cy='50' r='11'/><circle cx='18' cy='28' r='8'/><circle cx='82' cy='28' r='8'/><circle cx='24' cy='80' r='9'/><circle cx='76' cy='80' r='9'/><circle cx='50' cy='86' r='7'/></g>";
        case 'design':
            return "<path d='M50 8a42 42 0 1 0 0 84c7 0 9-6 6-11-4-7 1-12 9-12h9c11 0 18-9 18-19C92 26 73 8 50 8z' fill='none' stroke='#fff' stroke-width='5'/>"
                . "<g fill='#fff'><circle cx='30' cy='40' r='7'/><circle cx='46' cy='24' r='7'/><circle cx='68' cy='30' r='7'/><circle cx='26' cy='62' r='7'/></g>";
        case 'mechanic':
            $teeth = '';
            for ($i = 0; $i < 8; $i++) {
                $teeth .= "<rect x='44' y='4' width='12' height='18' rx='3' transform='rotate(" . ($i * 45) . " 50 50)'/>";
            }
            return "<g fill='#fff'>$teeth</g><circle cx='50' cy='50' r='30' fill='#fff'/><circle cx='50' cy='50' r='12' fill='#000' fill-opacity='.35'/>";
        case 'math':
            return "<g $s><path d='M14 28h26M27 15v26M60 28h26M62 64l22 22M84 64 62 86M14 75h26'/></g><g fill='#fff'><circle cx='27' cy='64' r='4'/><circle cx='27' cy='86' r='4'/></g>";
        case 'science':
            return "<g $s><path d='M38 8h24M43 8v30L18 82a6 6 0 0 0 5 9h54a6 6 0 0 0 5-9L57 38V8'/></g><path d='M30 66h40l9 16a4 4 0 0 1-3 6H24a4 4 0 0 1-3-6z' fill='#fff' fill-opacity='.6'/><g fill='#fff'><circle cx='44' cy='76' r='3'/><circle cx='56' cy='72' r='4'/></g>";
        case 'language':
            return "<g $s><path d='M10 14h52a8 8 0 0 1 8 8v24a8 8 0 0 1-8 8H32L18 66V54h-8a8 8 0 0 1-8-8V22a8 8 0 0 1 8-8z'/><path d='M76 40h12a8 8 0 0 1 8 8v22a8 8 0 0 1-8 8h-4v12L70 78H50a8 8 0 0 1-8-8v-6'/></g><g fill='#fff'><circle cx='22' cy='34' r='4'/><circle cx='36' cy='34' r='4'/><circle cx='50' cy='34' r='4'/></g>";
        case 'learning':
        default:
            return "<g $s><path d='M50 10a28 28 0 0 0-17 50c4 4 6 8 6 13h22c0-5 2-9 6-13a28 28 0 0 0-17-50z'/><path d='M40 82h20M43 92h14'/><path d='M50 26v14M43 33h14' stroke-width='4'/></g>";
    }
}

/** สร้าง SVG ภาพปก 640x360 */
function cover_svg(string $code, string $title, string $desc): string
{
    $themeKey = cover_theme($title, $desc);
    $theme = cover_themes()[$themeKey];
    [$c1, $c2] = $theme['colors'];
    $h = crc32($code . '|' . $title);                     // ตำแหน่งลวดลายต่างกันในแต่ละวิชา แต่คงที่เมื่อสร้างซ้ำ
    $x = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $titleLen = mb_strlen($title);
    $fontSize = $titleLen > 40 ? 26 : ($titleLen > 24 ? 30 : 36);
    $deco = '';
    for ($i = 0; $i < 4; $i++) {
        $cx = 40 + (($h >> ($i * 5)) % 560);
        $cy = 20 + (($h >> ($i * 7 + 3)) % 320);
        $r = 30 + (($h >> ($i * 3 + 1)) % 90);
        $deco .= "<circle cx='$cx' cy='$cy' r='$r' fill='#fff' fill-opacity='" . (0.04 + $i * 0.015) . "'/>";
    }
    $font = "'Prompt','Leelawadee UI','Tahoma','Noto Sans Thai',sans-serif";

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 360" width="640" height="360">'
        . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $c1 . '"/><stop offset="1" stop-color="' . $c2 . '"/></linearGradient>'
        . '<pattern id="dots" width="22" height="22" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.6" fill="#fff" fill-opacity=".12"/></pattern></defs>'
        . '<rect width="640" height="360" fill="url(#g)"/><rect width="640" height="360" fill="url(#dots)"/>' . $deco
        . '<circle cx="505" cy="170" r="112" fill="#fff" fill-opacity=".12"/><circle cx="505" cy="170" r="88" fill="#000" fill-opacity=".08"/>'
        . '<g transform="translate(435 100) scale(1.4)">' . cover_icon($themeKey) . '</g>'
        . '<rect x="36" y="38" rx="16" ry="16" width="' . (24 + mb_strlen($code) * 13) . '" height="34" fill="#fff" fill-opacity=".92"/>'
        . '<text x="' . (48) . '" y="61" font-family="' . $x($font) . '" font-size="20" font-weight="700" fill="' . $c1 . '">' . $x($code) . '</text>'
        // ใช้ foreignObject เพื่อให้เบราว์เซอร์ตัดบรรทัดภาษาไทยได้ถูกต้อง
        . '<foreignObject x="36" y="92" width="350" height="190"><div xmlns="http://www.w3.org/1999/xhtml" style="font-family:' . $x($font) . ';font-size:' . $fontSize . 'px;font-weight:700;line-height:1.3;color:#fff;'
        . 'display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word;text-shadow:0 2px 8px rgba(0,0,0,.25)">' . $x($title) . '</div></foreignObject>'
        . '<text x="36" y="322" font-family="' . $x($font) . '" font-size="17" fill="#fff" fill-opacity=".85">' . $x($theme['label']) . '  ·  ' . $x(setting('site_name', 'Vocational Teacher')) . '</text>'
        . '</svg>';
}

/** บันทึกภาพปกอัตโนมัติลงไฟล์ คืน path แบบ relative (covers/auto_...) */
function cover_generate(int $courseId, string $code, string $title, string $desc): string
{
    $dir = UPLOAD_PATH . '/covers';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = 'covers/auto_' . $courseId . '_' . substr(md5($code . $title . $desc . microtime()), 0, 8) . '.svg';
    file_put_contents(UPLOAD_PATH . '/' . $name, cover_svg($code, $title, $desc));
    return $name;
}

function is_auto_cover(?string $path): bool
{
    return $path !== null && strpos($path, 'covers/auto_') === 0;
}
