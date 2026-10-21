<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

if (is_post()) {
    csrf_check();
    $role = $_POST['role'] ?? $_POST['portal'] ?? null;
    if (!is_string($role) || !in_array($role, ['admin', 'officer', 'student'], true)) {
        $role = null;
    }
    $u = current_user($role);
    if ($u) log_action('Signed out');
    logout_now(null);
    flash('You are signed out.');
    redirect('index.php');
}
redirect('index.php');
