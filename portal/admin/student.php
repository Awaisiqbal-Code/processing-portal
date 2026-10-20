<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
$u = require_role('admin');
require APP . '/pages/student_edit.php';
