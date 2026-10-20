<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';
$u = require_role('admin');          // only admins can preview a student's portal
$sid = (int)get('id');
$preview = true;
log_action('Previewed student portal', $sid);
require APP . '/pages/student_view.php';
