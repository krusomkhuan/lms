<?php
// Copy to config.php and set the database credentials for your server.
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'vocational_lms');

// Optional override; otherwise the website URL is detected automatically.
// define('BASE_URL', 'http://localhost/lms');

define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('MAX_UPLOAD_MB', 50);
date_default_timezone_set('Asia/Bangkok');
