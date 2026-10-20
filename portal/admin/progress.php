<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require APP . '/layout.php';

$u = require_role('admin');
$sid = (int)get('id');

if ($sid <= 0) {
    flash('Select a student to view progress.', 'bad');
    redirect('admin/students.php');
}

$preview = true;
log_action('Viewed student progress', $sid);

require APP . '/pages/student_view.php';
