<?php
declare(strict_types=1);

// CLI-only recovery script for Super Admin account
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI access only.\n";
    exit(1);
}

require __DIR__ . '/bootstrap.php';

$superAdmin = q("SELECT id, name, username, role, active FROM users WHERE role = 'super_admin' OR id = 1 ORDER BY CASE WHEN role = 'super_admin' THEN 0 ELSE 1 END LIMIT 1")->fetch();

if (!$superAdmin) {
    fwrite(STDERR, "[ERROR] No super_admin account found in database.\n");
    exit(1);
}

$newPassword = $argv[1] ?? '';
if ($newPassword === '') {
    $newPassword = random_password(16);
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);
$superId = (int)$superAdmin['id'];

q("UPDATE users SET password_hash = ?, role = 'super_admin', active = 1, must_change_password = 0 WHERE id = ?", [$hash, $superId]);

log_activity('user', $superId, 'password_reset_cli', null, ['username' => $superAdmin['username']], $superId);

echo "============================================================\n";
echo " Super Admin Account Recovered Successfully\n";
echo "============================================================\n";
echo "ID:       " . $superId . "\n";
echo "Name:     " . $superAdmin['name'] . "\n";
echo "Username: " . $superAdmin['username'] . "\n";
echo "Password: " . $newPassword . "\n";
echo "Role:     super_admin\n";
echo "Active:   1 (Enabled)\n";
echo "============================================================\n";
echo "Keep these credentials safe. You can sign in immediately.\n";
exit(0);
