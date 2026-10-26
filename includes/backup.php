<?php
// สำรอง / กู้คืนข้อมูล: ฐานข้อมูล (SQL) + ไฟล์อัปโหลด รวมเป็นไฟล์ .zip เก็บใน /backups

define('BACKUP_PATH', ROOT_PATH . '/backups');

function backup_dir(): string
{
    if (!is_dir(BACKUP_PATH)) {
        mkdir(BACKUP_PATH, 0775, true);
    }
    if (!file_exists(BACKUP_PATH . '/.htaccess')) {
        file_put_contents(BACKUP_PATH . '/.htaccess', "Require all denied\n");
    }
    return BACKUP_PATH;
}

/** ชื่อไฟล์สำรองที่ปลอดภัย (กัน path traversal) หรือ null */
function backup_file(string $name): ?string
{
    if (!preg_match('/^[A-Za-z0-9_.-]+\.(zip|sql)$/', $name) || !is_file(backup_dir() . '/' . $name)) {
        return null;
    }
    return backup_dir() . '/' . $name;
}

/** สร้าง SQL dump ของทุกตาราง (DROP + CREATE + INSERT) */
function backup_dump_sql(): string
{
    global $conn;
    $out = "-- " . setting('site_name') . " backup\n-- เวอร์ชัน " . APP_VERSION . " วันที่ " . date('Y-m-d H:i:s') . "\n\n"
        . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    foreach ($conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetch_all() as $t) {
        $table = $t[0];
        $create = $conn->query("SHOW CREATE TABLE `$table`")->fetch_row()[1];
        $out .= "DROP TABLE IF EXISTS `$table`;\n$create;\n";
        // ไม่ใส่ความลับ (API key ของ AI) ในไฟล์สำรอง
        $where = $table === 'settings' ? " WHERE name NOT IN ('ai_api_key')" : '';
        $res = $conn->query("SELECT * FROM `$table`$where", MYSQLI_USE_RESULT);
        $rows = [];
        while ($r = $res->fetch_row()) {
            // real_escape_string แปลง \n เป็น \\n ทำให้แต่ละคำสั่งจบที่ ";\n" เสมอ
            $rows[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'", $r)) . ')';
            if (count($rows) >= 200) {
                $out .= "INSERT INTO `$table` VALUES\n" . implode(",\n", $rows) . ";\n";
                $rows = [];
            }
        }
        $res->free();
        if ($rows) {
            $out .= "INSERT INTO `$table` VALUES\n" . implode(",\n", $rows) . ";\n";
        }
        $out .= "\n";
    }
    return $out . "SET FOREIGN_KEY_CHECKS = 1;\n";
}

/**
 * สร้างไฟล์สำรอง คืนชื่อไฟล์
 * $type: manual | auto (ก่อนกู้คืน)
 */
function backup_create(bool $withFiles, string $type = 'manual'): string
{
    @set_time_limit(600);
    $dir = backup_dir();
    $base = ($type === 'auto' ? 'auto' : 'backup') . '_' . date('Ymd_His') . '_v' . APP_VERSION;
    $sql = backup_dump_sql();

    if (!class_exists('ZipArchive')) {
        file_put_contents("$dir/$base.sql", $sql);
        return "$base.sql";
    }

    $zip = new ZipArchive();
    if ($zip->open("$dir/$base.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('สร้างไฟล์ zip ไม่สำเร็จ');
    }
    $zip->addFromString('database.sql', $sql);
    $fileCount = 0;
    if ($withFiles && is_dir(UPLOAD_PATH)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(UPLOAD_PATH, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getFilename() !== '.htaccess') {
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(UPLOAD_PATH) + 1));
                $zip->addFile($f->getPathname(), 'uploads/' . $rel);
                $fileCount++;
            }
        }
    }
    $zip->addFromString('meta.json', json_encode([
        'app' => 'vocational-teacher',
        'app_version' => APP_VERSION,
        'db_version' => setting('db_version'),
        'created_at' => date('Y-m-d H:i:s'),
        'created_by' => current_user()['username'] ?? '',
        'type' => $type,
        'with_files' => $withFiles,
        'file_count' => $fileCount,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $zip->close();
    return "$base.zip";
}

function backup_meta(string $path): array
{
    if (substr($path, -4) === '.zip' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            $meta = json_decode((string)$zip->getFromName('meta.json'), true) ?: [];
            $meta['has_sql'] = $zip->locateName('database.sql') !== false;
            $zip->close();
            return $meta;
        }
        return ['has_sql' => false];
    }
    return ['has_sql' => true, 'with_files' => false];
}

function backup_list(): array
{
    $list = [];
    foreach (glob(backup_dir() . '/*.{zip,sql}', GLOB_BRACE) ?: [] as $p) {
        $list[] = ['name' => basename($p), 'size' => filesize($p), 'time' => filemtime($p), 'meta' => backup_meta($p)];
    }
    usort($list, fn($a, $b) => $b['time'] <=> $a['time']);
    return $list;
}

function format_bytes(int $b): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($b >= 1024 && $i < 3) { $b /= 1024; $i++; }
    return round($b, $i ? 1 : 0) . ' ' . $u[$i];
}

/** รันไฟล์ SQL ทั้งไฟล์ */
function backup_run_sql(string $sql): void
{
    global $conn;
    if (trim($sql) === '') {
        throw new RuntimeException('ไฟล์ SQL ว่างเปล่า');
    }
    // API key ไม่อยู่ในไฟล์สำรอง → เก็บค่าปัจจุบันไว้แล้วใส่คืนหลังกู้คืน
    $apiKey = (string)db_value("SELECT value FROM settings WHERE name = 'ai_api_key'");
    $conn->query('SET FOREIGN_KEY_CHECKS = 0');
    try {
        $conn->multi_query($sql);
        do {
            if ($r = $conn->store_result()) $r->free();
        } while ($conn->more_results() && $conn->next_result());
    } finally {
        $conn->query('SET FOREIGN_KEY_CHECKS = 1');
    }
    if ($apiKey !== '' && db_value("SHOW TABLES LIKE 'settings'")) {
        db_exec("INSERT INTO settings (name, value) VALUES ('ai_api_key', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)", [$apiKey]);
    }
}

/**
 * กู้คืนจากไฟล์สำรอง คืนข้อความสรุป
 */
function backup_restore(string $path, bool $restoreFiles): string
{
    @set_time_limit(600);
    if (substr($path, -4) === '.sql') {
        backup_run_sql((string)file_get_contents($path));
        return 'กู้คืนฐานข้อมูลเรียบร้อย';
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('เปิดไฟล์ zip ไม่ได้ หรือไฟล์เสียหาย');
    }
    $sql = $zip->getFromName('database.sql');
    if ($sql === false) {
        $zip->close();
        throw new RuntimeException('ไม่พบ database.sql ในไฟล์สำรอง (ไม่ใช่ไฟล์สำรองของระบบนี้)');
    }
    backup_run_sql($sql);

    $restored = 0;
    if ($restoreFiles) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', $zip->getNameIndex($i));
            // รับเฉพาะ uploads/<ไฟล์ปกติ> — กัน zip slip และไฟล์สคริปต์
            if (!preg_match('#^uploads/((?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+)$#', $name, $m)
                || strpos($m[1], '..') !== false
                || preg_match('/\.(php\d?|phtml|phar|htaccess|cgi|pl|py|sh)$/i', $m[1])) {
                continue;
            }
            $dest = UPLOAD_PATH . '/' . $m[1];
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0775, true);
            }
            file_put_contents($dest, $zip->getFromIndex($i));
            $restored++;
        }
    }
    $zip->close();
    return 'กู้คืนฐานข้อมูลเรียบร้อย' . ($restoreFiles ? " และไฟล์อัปโหลด $restored ไฟล์" : '');
}
