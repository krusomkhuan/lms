<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/schedule.php';
require __DIR__ . '/../includes/quiz.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$raw = json_encode([
    ['text' => '2 + 2', 'options' => ['3', '4'], 'correct' => 1],
    ['text' => '3 + 3', 'options' => ['6', '7', '8'], 'correct' => 0],
]);
$questions = json_decode(quiz_validate($raw), true);
check(quiz_grade($questions, ['1', '0']) === 2, 'Full score');
check(quiz_grade($questions, ['0', '0']) === 1, 'Partial score');
check(quiz_grade($questions, ['0', '2']) === 0, 'Zero score');
foreach ([[], ['1'], ['1', '99'], [['1'], '0'], ['1x', '0']] as $bad) {
    try { quiz_grade($questions, $bad); throw new LogicException('Invalid answer accepted'); } catch (RuntimeException $expected) {}
}
foreach (['[]', '{}', 'invalid', json_encode([['text' => '', 'options' => ['a', 'b'], 'correct' => 0]]), json_encode([['text' => 'q', 'options' => ['a', ''], 'correct' => 0]]), json_encode([['text' => 'q', 'options' => ['a', 'b'], 'correct' => 9]])] as $bad) {
    try { quiz_validate($bad); throw new LogicException('Invalid quiz accepted'); } catch (RuntimeException $expected) {}
}
echo "Validation and grading passed\n";
if (!in_array('--database', $argv, true)) exit;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');
check(quiz_ready(), 'Migration required');
$conn->begin_transaction();
try {
    $user = db_exec("INSERT INTO users (username,password,fullname,role) VALUES (?,?,?,'student')", ['quiz_test_' . bin2hex(random_bytes(5)), 'disabled', 'Quiz test']);
    $course = db_exec("INSERT INTO courses (code,title,status) VALUES ('TEST','Quiz test','published')");
    $lesson = db_exec("INSERT INTO lessons (course_id,title,media_type,quiz_data) VALUES (?,'Test','quiz',?)", [$course, $raw]);
    $media = db_exec("INSERT INTO lesson_media (lesson_id,title,media_type,quiz_data) VALUES (?,'Extra','quiz',?)", [$lesson, $raw]);
    $token = bin2hex(random_bytes(32));
    $params = [$lesson, $media, $media, $user, 'Extra', 1, 2, '[0,0]', $raw, $token];
    $sql = 'INSERT INTO quiz_attempts (lesson_id,media_id,source_media_id,user_id,quiz_title,score,total,answers,quiz_snapshot,submission_token) VALUES (?,?,?,?,?,?,?,?,?,?)';
    $attempt = db_exec($sql, $params);
    try { db_exec($sql, $params); throw new LogicException('Duplicate accepted'); } catch (mysqli_sql_exception $ex) { check($ex->getCode() === 1062, 'Expected duplicate error'); }
    db_exec('UPDATE lesson_media SET quiz_data = ? WHERE id = ?', ['[]', $media]);
    check(db_value('SELECT quiz_snapshot FROM quiz_attempts WHERE id = ?', [$attempt]) === $raw, 'Snapshot changed');
    db_exec('DELETE FROM lesson_media WHERE id = ?', [$media]);
    $saved = db_one('SELECT * FROM quiz_attempts WHERE id = ?', [$attempt]);
    check($saved && $saved['media_id'] === null && (int)$saved['source_media_id'] === $media && (int)$saved['score'] === 1, 'Score lost on media deletion');
    echo "Database score persistence, snapshots, duplicate prevention and deletion passed (rolled back)\n";
} finally { $conn->rollback(); }
