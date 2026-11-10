<?php
declare(strict_types=1);

/**
 * Migration 013 — Country-wise PMS Leads.
 * A PMS Lead now sees only the countries ticked for them in Team (users.countries). These are the
 * team leads named by the Super Admin on 10 Nov 2026:
 *   Mubeen Fatima — Italy · Asif — Germany · Haider — Czech Republic · Muhammad Usman — France · Zeeshan — China
 * Existing accounts keep their username, password, team and students; Asif and Haider get new accounts
 * with the standard first password (Password123!) and must set their own at first sign-in.
 */
return function (PDO $pdo): void {
    $now = gmdate('Y-m-d H:i:s');
    $leads = [
        ['mubeen.fatima', 'Mubeen Fatima', ['Italy']],
        ['asif', 'Asif', ['Germany']],
        ['haider', 'Haider', ['Czech Republic']],
        ['muhammad.usman', 'Muhammad Usman', ['France']],
        ['zeeshan', 'Zeeshan', ['China']],
    ];
    $perms = json_encode(['reports.view', 'docs.verify']);
    foreach ($leads as [$username, $name, $countries]) {
        $st = $pdo->prepare('SELECT id, role FROM users WHERE username = ?');
        $st->execute([$username]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($row['role'] === 'student') { echo "  -> skipped $username (is a student account)\n"; continue; }
            $pdo->prepare("UPDATE users SET role = 'pms_lead', countries = ?, active = 1, permissions = COALESCE(NULLIF(permissions, ''), ?) WHERE id = ?")
                ->execute([json_encode($countries), $perms, (int)$row['id']]);
            echo "  -> $username is PMS Lead for " . implode(', ', $countries) . "\n";
        } else {
            $pdo->prepare("INSERT INTO users (name, username, password_hash, role, active, must_change_password, created_at, department, permissions, countries)
                           VALUES (?, ?, ?, 'pms_lead', 1, 1, ?, 'backend', ?, ?)")
                ->execute([$name, $username, password_hash('Password123!', PASSWORD_DEFAULT), $now, $perms, json_encode($countries)]);
            echo "  -> created $username as PMS Lead for " . implode(', ', $countries) . "\n";
        }
    }
};
