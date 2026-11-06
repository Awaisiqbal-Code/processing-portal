<?php
declare(strict_types=1);

/**
 * Migration 009: staff profiles, per-person access and a change history for staff.
 * Purely additive — no existing row or column is changed.
 *
 * - users.email / phone (WhatsApp) / branch / job_title: contact details for staff.
 * - users.permissions: JSON list of extra access for Admin / PMS Lead (NULL = role defaults).
 * - staff_changes: every role, team, access, detail and status change with who did it,
 *   and how many students were moved.
 */
return function (PDO $pdo): void {
    $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['email' => 'TEXT NULL', 'phone' => 'TEXT NULL', 'branch' => 'TEXT NULL', 'job_title' => 'TEXT NULL', 'permissions' => 'TEXT NULL'] as $c => $def) {
        if (!in_array($c, $cols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN $c $def");
            echo "  -> users.$c added\n";
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_changes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        kind TEXT NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        students_moved INTEGER NOT NULL DEFAULT 0,
        note TEXT NULL,
        changed_by INTEGER NULL REFERENCES users(id),
        changed_at TEXT NOT NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_staff_changes_user ON staff_changes(user_id, changed_at)');
    echo "  -> staff_changes ready\n";
};
