<?php
declare(strict_types=1);

/**
 * Migration 010
 * 1. The "2nd processing meeting" IS the tax consultant meeting: one item from now on.
 *    Where only the 2nd-meeting column was ticked, the tax-meeting column is ticked too, and its
 *    follow-up date is kept. (The old column stays in the database, untouched, for history.)
 * 2. Password reset by request: password_reset_requests (request -> approval -> emailed code -> link).
 * 3. Countries a staff member works on (consent forms and country material are shown per country).
 * 4. Email (SMTP) settings rows.
 */
return function (PDO $pdo): void {
    $n = $pdo->exec("UPDATE students SET tax_meeting = 'Yes' WHERE second_meeting = 'Yes' AND COALESCE(tax_meeting, '') != 'Yes'");
    echo "  -> tax meeting ticked from the 2nd-meeting column on $n students\n";
    $pdo->exec("UPDATE students SET follow_up_3 = follow_up_2 WHERE COALESCE(follow_up_3, '') = '' AND COALESCE(follow_up_2, '') != '' AND COALESCE(tax_meeting, '') != 'Yes'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        status TEXT NOT NULL DEFAULT 'pending',          -- pending, approved, code_verified, completed, rejected, expired
        requested_at TEXT NOT NULL,
        request_ip TEXT NULL,
        request_note TEXT NULL,
        decided_by INTEGER NULL REFERENCES users(id),
        decided_at TEXT NULL,
        decision_note TEXT NULL,
        code_hash TEXT NULL,
        code_expires_at TEXT NULL,
        code_attempts INTEGER NOT NULL DEFAULT 0,
        link_hash TEXT NULL,
        link_expires_at TEXT NULL,
        email_sent_to TEXT NULL,
        email_status TEXT NULL,
        completed_at TEXT NULL,
        completed_ip TEXT NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_prr_user ON password_reset_requests(user_id, requested_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_prr_status ON password_reset_requests(status)');
    echo "  -> password_reset_requests ready\n";

    $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['countries' => 'TEXT NULL', 'password_changed_at' => 'TEXT NULL', 'password_history' => 'TEXT NULL'] as $c => $def) {
        if (!in_array($c, $cols, true)) { $pdo->exec("ALTER TABLE users ADD COLUMN $c $def"); echo "  -> users.$c added\n"; }
    }
};
