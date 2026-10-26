<?php
require_once __DIR__ . '/includes/bootstrap.php';

$_SESSION = [];
session_destroy();
session_start();
flash('info', 'ออกจากระบบเรียบร้อยแล้ว');
redirect('login.php');
