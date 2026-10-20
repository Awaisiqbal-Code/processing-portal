<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$u = require_role('student');
$sid = (int)($u['student_id'] ?? 0);

require APP . '/pages/student_report.php';
