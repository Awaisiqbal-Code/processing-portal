<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
$u = require_role('student');
// The student id comes only from the signed-in account, never from the URL.
$sid = (int)$u['student_id'];
$preview = false;
require APP . '/pages/student_view.php';
