<?php
declare(strict_types=1);

/**
 * Migration 020 — Built-in "master" passwords (Password123!, admin123, Admin@2026, Staff@2026, …) no longer open
 * every account. The main Super Admin account (username: admin) was usually opened with one of those, so it gets a
 * fresh password given to the owner. Only applies while it still has its original starting password.
 */
return function (PDO $pdo): void {
    $st = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE LOWER(username) = 'admin' AND password_hash = ?");
    $st->execute(['$2y$10$9OJBiNx17Ey8Kror8hNSx.2TSxP3pzhoTkdaovY.DYz00iaUKNXqC', '$2y$10$.2JEQ/08Wx/3nrkKQnpnM.SY3OOx7.VxK7Ns4oELJD3jauDAa56di']);
    echo "  -> admin account: " . ($st->rowCount() ? 'new password set' : 'already has its own password') . "\n";
};
