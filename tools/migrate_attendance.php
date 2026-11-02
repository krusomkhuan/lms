<?php
// Additive migration only. Never run the fresh-install schema on an existing database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');
foreach (['1.16.0.sql','1.16.1.sql'] as $migration) {
    $conn->multi_query(file_get_contents(__DIR__ . '/../database/migrations/' . $migration));
    do { if ($result = $conn->store_result()) $result->free(); }
    while ($conn->more_results() && $conn->next_result());
}
echo "Attendance tables ready. Existing data preserved.\n";
