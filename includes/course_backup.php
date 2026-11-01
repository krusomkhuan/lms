<?php
// สำรอง/นำเข้ารายวิชา (.zip): ครูสำรองวิชาที่สอนได้เอง และนำเข้าเป็นรายวิชาใหม่ (คัดลอกเนื้อหาไปใช้ภาคเรียนถัดไป)
// โครงสร้างไฟล์: manifest.json, data.json (ข้อมูลรายวิชา), files/<path ใน uploads>, scores.csv (คะแนนรายคน อ่านด้วย Excel)

const COURSE_BACKUP_FORMAT = 1;

// ใช้ PharData (มากับ PHP เสมอ) แทน ZipArchive ซึ่งหลายเซิร์ฟเวอร์ไม่ได้เปิด extension=zip
function course_zip_create(): array
{
    if (!class_exists('PharData')) throw new RuntimeException('เซิร์ฟเวอร์ไม่รองรับการสร้างไฟล์ .zip (Phar)');
    $path = sys_get_temp_dir() . '/course_' . bin2hex(random_bytes(8)) . '.zip';
    return [$path, new PharData($path, 0, null, Phar::ZIP)];
}

/** เปิดไฟล์ zip ที่อัปโหลด (คัดลอกเป็นชื่อ .zip ชั่วคราว เพราะ PharData ต้องรู้นามสกุล) คืน [archive, path ชั่วคราว] */
function course_zip_open(string $uploaded): array
{
    if (!class_exists('PharData')) throw new RuntimeException('เซิร์ฟเวอร์ไม่รองรับการอ่านไฟล์ .zip (Phar)');
    $path = sys_get_temp_dir() . '/course_in_' . bin2hex(random_bytes(8)) . '.zip';
    copy($uploaded, $path);
    try {
        return [new PharData($path, 0, null, Phar::ZIP), $path];
    } catch (Throwable $ex) {
        unlink($path);
        throw new RuntimeException('เปิดไฟล์ .zip ไม่ได้ (ไฟล์เสียหรือไม่ใช่ไฟล์ zip)');
    }
}

function course_zip_get(PharData $zip, string $name)
{
    return isset($zip[$name]) ? $zip[$name]->getContent() : false;
}

/** path ของไฟล์อัปโหลดที่อ้างในเนื้อหา HTML (รูปจากตัวแก้ไขข้อความ) */
function course_backup_content_files(?string $html): array
{
    preg_match_all('~/uploads/(editor/[A-Za-z0-9_\-./]+)~', (string)$html, $m);
    return array_filter(array_unique($m[1]), fn($p) => strpos($p, '..') === false);
}

/** สร้างไฟล์ .zip สำรองรายวิชา คืน path ไฟล์ชั่วคราว ($withStudents = รวมคะแนน/งานส่งของนักเรียน) */
function course_backup_export(int $courseId, bool $withStudents): string
{
    $course = db_one('SELECT * FROM courses WHERE id = ?', [$courseId]);
    $data = [
        'course' => $course,
        'lessons' => db_all('SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order, id', [$courseId]),
        'lesson_media' => lesson_media_ready() ? db_all('SELECT m.* FROM lesson_media m JOIN lessons l ON l.id = m.lesson_id WHERE l.course_id = ? ORDER BY m.lesson_id, m.sort_order, m.id', [$courseId]) : [],
        'question_bank' => question_bank_ready() ? db_all('SELECT * FROM question_bank WHERE course_id = ? ORDER BY id', [$courseId]) : [],
        'grade_config' => gradebook_ready() ? db_one('SELECT scale_json FROM course_grade_config WHERE course_id = ?', [$courseId]) : null,
        'grade_items' => gradebook_ready() ? db_all('SELECT * FROM grade_items WHERE course_id = ? ORDER BY id', [$courseId]) : [],
        'teachers' => db_all('SELECT u.username, u.fullname FROM course_teachers ct JOIN users u ON u.id = ct.teacher_id WHERE ct.course_id = ?', [$courseId]),
    ];
    $files = array_filter([$course['cover']]);
    foreach (array_merge($data['lessons'], $data['lesson_media']) as $row) {
        if ($row['file_path']) $files[] = $row['file_path'];
        $files = array_merge($files, course_backup_content_files($row['content']));
    }
    $files = array_merge($files, course_backup_content_files($course['description']));
    if ($withStudents) {
        $roster = grade_roster($courseId);
        $data['students'] = array_map(fn($s) => array_intersect_key($s, array_flip(['id', 'username', 'fullname', 'student_code', 'group_names'])), $roster);
        $data['lesson_progress'] = db_all('SELECT lp.* FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id WHERE l.course_id = ?', [$courseId]);
        $data['quiz_attempts'] = quiz_ready() ? db_all('SELECT a.* FROM quiz_attempts a JOIN lessons l ON l.id = a.lesson_id WHERE l.course_id = ? ORDER BY a.id', [$courseId]) : [];
        $data['assignment_submissions'] = assignments_ready() ? db_all('SELECT s.* FROM assignment_submissions s JOIN lessons l ON l.id = s.lesson_id WHERE l.course_id = ? ORDER BY s.id', [$courseId]) : [];
        $data['grade_manual_scores'] = gradebook_ready() ? db_all('SELECT m.* FROM grade_manual_scores m JOIN grade_items i ON i.id = m.item_id WHERE i.course_id = ?', [$courseId]) : [];
        $data['grade_results'] = gradebook_ready() ? db_all('SELECT r.*, g.created_at AS run_created_at FROM grade_results r JOIN grade_runs g ON g.id = r.run_id WHERE g.course_id = ? ORDER BY r.run_id, r.user_id', [$courseId]) : [];
        foreach ($data['assignment_submissions'] as $s) if ($s['file_path']) $files[] = $s['file_path'];
    }

    [$path, $zip] = course_zip_create();
    $zip->addFromString('manifest.json', json_encode([
        'type' => 'lms-course-backup', 'format' => COURSE_BACKUP_FORMAT, 'app_version' => APP_VERSION, 'exported_at' => date('Y-m-d H:i:s'),
        'code' => $course['code'], 'title' => $course['title'], 'with_students' => $withStudents,
        'counts' => array_map(fn($v) => is_array($v) ? count($v) : null, $data),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $zip->addFromString('data.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    foreach (array_unique($files) as $file) {
        if (strpos($file, '..') === false && is_file(UPLOAD_PATH . '/' . $file)) $zip->addFile(UPLOAD_PATH . '/' . $file, 'files/' . $file);
    }
    if ($withStudents) $zip->addFromString('scores.csv', course_backup_scores_csv($courseId));
    unset($zip);
    return $path;
}

/** ตารางคะแนนรายคน (คะแนนดีที่สุด %) สำหรับเปิดดูใน Excel */
function course_backup_scores_csv(int $courseId): string
{
    $items = progress_items($courseId);
    $results = progress_results($courseId);
    $out = fopen('php://memory', 'r+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_merge(['รหัสนักเรียน', 'ชื่อ-นามสกุล', 'กลุ่มเรียน', 'เรียนจบบทเรียน (%)'], array_map(fn($i) => $i['title'] . ' (%)', array_values($items))), ',', '"', '');
    foreach (grade_roster($courseId) as $s) {
        $row = [$s['student_code'], $s['fullname'], $s['group_names'], course_progress($courseId, (int)$s['id'])];
        foreach ($items as $key => $item) $row[] = $results[$s['id']][$key]['best'] ?? '';
        fputcsv($out, $row, ',', '"', '');
    }
    rewind($out);
    return stream_get_contents($out);
}

/** คอลัมน์ของตารางในฐานข้อมูลปัจจุบัน (ไฟล์สำรองจากเวอร์ชันอื่นอาจมีคอลัมน์ไม่ตรงกัน) */
function course_backup_columns(string $table): array
{
    static $cache = [];
    return $cache[$table] ??= array_column(db_all('SELECT column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?', [$table]), 'c');
}

function course_backup_insert(string $table, array $row): int
{
    $row = array_intersect_key($row, array_flip(course_backup_columns($table)));
    unset($row['id']);
    $cols = array_keys($row);
    return db_exec("INSERT INTO $table (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($row));
}

/**
 * นำเข้าไฟล์สำรองเป็นรายวิชาใหม่ (สถานะร่าง ผู้นำเข้าเป็นครูผู้สอน) — นำเข้าเฉพาะเนื้อหา ไม่นำเข้าข้อมูลนักเรียน
 * คืน id รายวิชาใหม่
 */
function course_backup_import(string $zipPath, array $me, string $code, string $title): int
{
    [$zip, $tmpZip] = course_zip_open($zipPath);
    $manifest = json_decode((string)course_zip_get($zip, 'manifest.json'), true);
    $data = json_decode((string)course_zip_get($zip, 'data.json'), true);
    $invalid = !is_array($manifest) || !is_array($data) || ($manifest['type'] ?? '') !== 'lms-course-backup' || !is_array($data['course'] ?? null);
    $newer = !$invalid && (int)($manifest['format'] ?? 0) > COURSE_BACKUP_FORMAT;
    if ($invalid || $newer) {
        unset($zip);
        @unlink($tmpZip);
        throw new RuntimeException($invalid ? 'ไฟล์นี้ไม่ใช่ไฟล์สำรองรายวิชา' : 'ไฟล์สำรองมาจากระบบเวอร์ชันใหม่กว่า กรุณาอัปเดตระบบก่อนนำเข้า');
    }

    // คัดลอกไฟล์จาก zip ไปไว้ใน uploads ด้วยชื่อใหม่ (ไม่ทับไฟล์เดิม)
    $copied = [];
    $copy = function (?string $path) use ($zip, &$copied): ?string {
        if (!$path || strpos($path, '..') !== false) return null;
        if (isset($copied[$path])) return $copied[$path];
        $content = course_zip_get($zip, 'files/' . $path);
        if ($content === false) return null;
        $dir = dirname($path);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!preg_match('/^[a-z0-9]{1,10}$/', $ext) || in_array($ext, ['php', 'phtml', 'phar', 'htaccess'], true)) return null;
        $new = ($dir === '.' ? '' : $dir . '/') . 'import_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!is_dir(UPLOAD_PATH . '/' . dirname($new))) mkdir(UPLOAD_PATH . '/' . dirname($new), 0775, true);
        file_put_contents(UPLOAD_PATH . '/' . $new, $content);
        return $copied[$path] = $new;
    };
    $content = function (?string $html) use ($copy): ?string {
        if ($html === null) return null;
        return preg_replace_callback('~(?:https?://[^"\'\s<>]+)?/uploads/(editor/[A-Za-z0-9_\-./]+)~', function ($m) use ($copy) {
            $new = $copy($m[1]);
            return $new ? UPLOAD_URL . '/' . $new : $m[0];
        }, $html);
    };

    $conn = $GLOBALS['conn'];
    $conn->begin_transaction();
    try {
        $c = $data['course'];
        $courseId = course_backup_insert('courses', array_merge($c, ['code' => $code, 'title' => $title, 'status' => 'draft', 'created_by' => (int)$me['id'],
            'cover' => $copy($c['cover'] ?? null), 'description' => $content($c['description'] ?? null), 'created_at' => date('Y-m-d H:i:s')]));
        db_exec('INSERT IGNORE INTO course_teachers (course_id, teacher_id) VALUES (?, ?)', [$courseId, (int)$me['id']]);
        $lessonMap = [];
        foreach ($data['lessons'] ?? [] as $l) {
            $lessonMap[(int)$l['id']] = course_backup_insert('lessons', array_merge($l, ['course_id' => $courseId, 'file_path' => $copy($l['file_path'] ?? null), 'content' => $content($l['content'] ?? null), 'created_at' => date('Y-m-d H:i:s')]));
        }
        $mediaMap = [0 => 0];
        foreach ($data['lesson_media'] ?? [] as $m) {
            if (!isset($lessonMap[(int)$m['lesson_id']])) continue;
            $mediaMap[(int)$m['id']] = course_backup_insert('lesson_media', array_merge($m, ['lesson_id' => $lessonMap[(int)$m['lesson_id']], 'file_path' => $copy($m['file_path'] ?? null), 'content' => $content($m['content'] ?? null), 'created_at' => date('Y-m-d H:i:s')]));
        }
        // แบบทดสอบหลังเรียนอ้างคู่ก่อนเรียนด้วย "บทเรียน:สื่อ" → เปลี่ยนเป็น id ใหม่
        $remapKey = function (string $key) use ($lessonMap, $mediaMap): string {
            [$l, $m] = array_map('intval', explode(':', $key) + [0, 0]);
            return isset($lessonMap[$l], $mediaMap[$m]) ? $lessonMap[$l] . ':' . $mediaMap[$m] : '';
        };
        foreach ([['lessons', $lessonMap], ['lesson_media', $mediaMap]] as [$table, $map]) {
            foreach ($map as $newId) {
                if (!$newId || !in_array('quiz_data', course_backup_columns($table), true)) continue;
                $raw = db_value("SELECT quiz_data FROM $table WHERE id = ?", [$newId]);
                $config = $raw ? json_decode($raw, true) : null;
                if (is_array($config) && !array_is_list($config) && !empty($config['pair'])) {
                    $config['pair'] = $remapKey($config['pair']);
                    db_exec("UPDATE $table SET quiz_data = ? WHERE id = ?", [json_encode($config, JSON_UNESCAPED_UNICODE), $newId]);
                }
            }
        }
        if (question_bank_ready()) foreach ($data['question_bank'] ?? [] as $q) course_backup_insert('question_bank', array_merge($q, ['course_id' => $courseId, 'created_by' => (int)$me['id']]));
        if (gradebook_ready()) {
            if (!empty($data['grade_config']['scale_json'])) db_exec('INSERT INTO course_grade_config (course_id, scale_json) VALUES (?, ?)', [$courseId, $data['grade_config']['scale_json']]);
            foreach ($data['grade_items'] ?? [] as $item) {
                if ($item['kind'] !== 'manual') {
                    [$kind, $l, $m] = explode(':', $item['source_key']) + ['', 0, 0];
                    $key = $remapKey($l . ':' . $m);
                    if ($key === '') continue;
                    [$item['lesson_id'], $item['source_media_id']] = explode(':', $key);
                    $item['source_key'] = $kind . ':' . $key;
                } else {
                    $item['lesson_id'] = null;
                    $item['source_key'] = 'manual:' . bin2hex(random_bytes(16));
                }
                course_backup_insert('grade_items', array_merge($item, ['course_id' => $courseId]));
            }
        }
        $conn->commit();
    } catch (Throwable $ex) {
        $conn->rollback();
        foreach ($copied as $file) delete_upload($file);
        throw $ex;
    } finally {
        unset($zip);
        @unlink($tmpZip);
    }
    return $courseId;
}
