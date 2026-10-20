<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$u = require_role('admin');
$sid = (int)get('id');

require APP . '/pages/student_report.php';
