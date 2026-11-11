<?php
declare(strict_types=1);

/**
 * Migration 018 — The owner's developer account (awais.khan) no longer has to choose a new password at first sign-in.
 * On hosts that run several server copies (Vercel), a new password is saved in one copy only, so the forced change
 * kept coming back. Only applies while the account still has its original password from migration 016.
 */
return function (PDO $pdo): void {
    $st = $pdo->prepare("UPDATE users SET must_change_password = 0 WHERE LOWER(username) = 'awais.khan' AND password_hash = ?");
    $st->execute(['$2y$10$Q7z9i4.9rk7BxweTN3//9ekRjHkVunJG5hA.14I7/r4OE4cl0e/6m']);
    echo "  -> developer account: " . ($st->rowCount() ? 'no forced password change' : 'already has its own password') . "\n";
};
