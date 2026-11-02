<?php
function related_site_url($value): string
{
    if (!is_string($value)) throw new RuntimeException('ลิงก์เว็บไซต์ไม่ถูกต้อง');
    $value = trim($value);
    $parts = parse_url($value);
    if (strlen($value) > 2048 || !filter_var($value, FILTER_VALIDATE_URL)
        || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true)
        || isset($parts['user']) || isset($parts['pass'])) throw new RuntimeException('กรุณากรอกลิงก์ http:// หรือ https:// ที่ถูกต้อง และไม่มีชื่อผู้ใช้หรือรหัสผ่านในลิงก์');
    return $value;
}

function related_sites(): array
{
    $sites = [
        'docshub'=>['https://krusomkhuan.com/docshub/', 'docshub.svg', 'Docs Hub', 'ศูนย์รวมงานจัดเก็บเอกสาร'],
        'prompthub'=>['https://krusomkhuan.com/prompthub', 'prompthub.svg', 'Prompt Hub', 'เปลี่ยนไอเดียให้เป็นจริงด้วย AI'],
        'teachhub'=>['https://krusomkhuan.com/teachhub', 'teachhub.svg', 'Teach Hub', 'ระบบงานพัฒนาหลักสูตรและการจัดการเรียนรู้'],
        'imagehub'=>['https://krusomkhuan.com/docshub/photo-albums.php', 'imagehub.svg', 'Image Hub', 'คลังรูปภาพและสื่อกราฟิก'],
    ];
    $saved = json_decode(setting('related_site_urls', '{}'), true);
    foreach ($sites as $key => &$site) {
        if (is_array($saved) && isset($saved[$key])) {
            try { $site[0] = related_site_url($saved[$key]); }
            catch (RuntimeException $ex) { /* Invalid stored values use the original URL. */ }
        }
    }
    unset($site);
    return $sites;
}
