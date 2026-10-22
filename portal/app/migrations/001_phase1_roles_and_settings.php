<?php
declare(strict_types=1);

return function (PDO $pdo): array {
    // 0. Safely rebuild `users` table to expand the role CHECK constraint
    //    following Safety Rule 1: copy -> verify row counts -> swap -> recreate indexes
    $usersSql = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
    if ($usersSql !== '' && !str_contains($usersSql, 'super_admin')) {
        $indexSqls = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'users' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

        $beforeUserCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

        $newTableSql = preg_replace('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["`]?users["`]?/i', 'CREATE TABLE users_new', $usersSql, 1);
        $newTableSql = preg_replace(
            "/role\s+IN\s*\(\s*'admin'\s*,\s*'officer'\s*,\s*'student'\s*\)/i",
            "role IN ('super_admin','admin','pms_lead','counselor','processing_staff','officer','student')",
            (string)$newTableSql
        );

        $colsInfo = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_map(fn($c) => '"' . str_replace('"', '""', (string)$c['name']) . '"', $colsInfo);
        $colList  = implode(', ', $colNames);

        $pdo->exec("DROP TABLE IF EXISTS users_new");
        $pdo->exec((string)$newTableSql);
        $pdo->exec("INSERT INTO users_new ($colList) SELECT $colList FROM users");

        $copiedUserCount = (int)$pdo->query("SELECT COUNT(*) FROM users_new")->fetchColumn();
        if ($copiedUserCount !== $beforeUserCount) {
            throw new RuntimeException("Users table rebuild count mismatch: before={$beforeUserCount}, copied={$copiedUserCount}");
        }

        $pdo->exec("DROP TABLE users");
        $pdo->exec("ALTER TABLE users_new RENAME TO users");

        foreach ($indexSqls as $idxSql) {
            if (is_string($idxSql) && trim($idxSql) !== '') {
                $idxSqlIdempotent = preg_replace('/CREATE\s+(UNIQUE\s+)?INDEX\s+(?!IF\s+NOT\s+EXISTS)/i', 'CREATE $1INDEX IF NOT EXISTS ', $idxSql, 1);
                $pdo->exec((string)$idxSqlIdempotent);
            }
        }
    }

    // 1. Tunable settings table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )
    ");

    $defaultSettings = [
        'IDLE_WARNING_MINUTES'   => '15',
        'IDLE_LOGOUT_MINUTES'    => '20',
        'PENDING_GRACE_HOURS'    => '0',
        'PENDING_REMINDER_HOURS' => '48',
    ];
    $insSetting = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))");
    foreach ($defaultSettings as $k => $v) {
        $insSetting->execute([$k, $v]);
    }

    // 2. Structured activity_logs table (used for role_changed in Phase 1 and student edits in Phase 2+)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS activity_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER NULL,
            action TEXT NOT NULL,
            old_values TEXT NULL,
            new_values TEXT NULL,
            created_at TEXT NOT NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_activity_logs_entity ON activity_logs(entity_type, entity_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_activity_logs_created ON activity_logs(created_at)");

    // 3. Map existing users to Phase 1 roles
    // id = 1 -> super_admin
    $pdo->exec("UPDATE users SET role = 'super_admin', must_change_password = 0, active = 1 WHERE id = 1");

    // Existing officers (Minahil=2, Sehrish=3, Farwa=4, Nimra=510, Mujassam=511) -> pms_lead
    // ('officer' remains a supported alias in auth.php so all /officer/* pages continue working)
    $pdo->exec("UPDATE users SET role = 'pms_lead' WHERE role = 'officer' AND id IN (2, 3, 4, 510, 511)");

    // 4. Create the 19 canonical counselor accounts (with must_change_password = 1)
    $canonicalCounselors = [
        ['name' => 'Ansa',          'username' => 'ansa'],
        ['name' => 'Usman Riaz',    'username' => 'usman.riaz'],
        ['name' => 'Ali Haider',    'username' => 'ali.haider'],
        ['name' => 'Maryam',        'username' => 'maryam'],
        ['name' => 'Ali Naqvi',     'username' => 'ali.naqvi'],
        ['name' => 'Suneya',        'username' => 'suneya'],
        ['name' => 'Salah Ud Deen', 'username' => 'salah.ud.deen'],
        ['name' => 'Kamran',        'username' => 'kamran'],
        ['name' => 'Sheraz',        'username' => 'sheraz'],
        ['name' => 'Umar',          'username' => 'umar'],
        ['name' => 'Noor Fatima',   'username' => 'noor.fatima'],
        ['name' => 'Awais Shahzad', 'username' => 'awais.shahzad'],
        ['name' => 'Talha',         'username' => 'talha'],
        ['name' => 'Inza',          'username' => 'inza'],
        ['name' => 'Ayesha Khalil', 'username' => 'ayesha.khalil'],
        ['name' => 'Khadija',       'username' => 'khadija'],
        ['name' => 'Daniyal',       'username' => 'daniyal'],
        ['name' => 'Waseem',        'username' => 'waseem'],
        ['name' => 'Hamna',         'username' => 'hamna'],
    ];

    $defaultPassHash = password_hash('Password123!', PASSWORD_DEFAULT);
    $checkUser = $pdo->prepare("SELECT id, role FROM users WHERE LOWER(username) = ?");
    $insertCounselor = $pdo->prepare("
        INSERT INTO users (name, username, password_hash, role, student_id, active, must_change_password, created_at, department)
        VALUES (?, ?, ?, 'counselor', NULL, 1, 1, datetime('now'), 'counselor')
    ");

    $counselorsCreated = 0;
    foreach ($canonicalCounselors as $c) {
        $checkUser->execute([$c['username']]);
        $existing = $checkUser->fetch();
        if (!$existing) {
            $insertCounselor->execute([$c['name'], $c['username'], $defaultPassHash]);
            $counselorsCreated++;
        } elseif ($existing['role'] === 'student') {
            // If a student username collided with a canonical counselor username, rename the student login safely
            $sid = (int)$pdo->query("SELECT student_id FROM users WHERE id = " . (int)$existing['id'])->fetchColumn();
            $pdo->prepare("UPDATE users SET username = ? WHERE id = ?")
                ->execute([$c['username'] . '.st' . $sid, (int)$existing['id']]);
            $insertCounselor->execute([$c['name'], $c['username'], $defaultPassHash]);
            $counselorsCreated++;
        }
    }

    // 5. Database-level triggers enforcing at most ONE super_admin and at most ONE admin
    $pdo->exec("DROP TRIGGER IF EXISTS trg_users_single_super_admin_ins");
    $pdo->exec("
        CREATE TRIGGER trg_users_single_super_admin_ins
        BEFORE INSERT ON users
        WHEN NEW.role = 'super_admin' AND (SELECT COUNT(*) FROM users WHERE role = 'super_admin') >= 1
        BEGIN
            SELECT RAISE(ABORT, 'Only one Super Admin is allowed');
        END
    ");

    $pdo->exec("DROP TRIGGER IF EXISTS trg_users_single_super_admin_upd");
    $pdo->exec("
        CREATE TRIGGER trg_users_single_super_admin_upd
        BEFORE UPDATE OF role ON users
        WHEN NEW.role = 'super_admin' AND (SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND id != OLD.id) >= 1
        BEGIN
            SELECT RAISE(ABORT, 'Only one Super Admin is allowed');
        END
    ");

    $pdo->exec("DROP TRIGGER IF EXISTS trg_users_single_admin_ins");
    $pdo->exec("
        CREATE TRIGGER trg_users_single_admin_ins
        BEFORE INSERT ON users
        WHEN NEW.role = 'admin' AND (SELECT COUNT(*) FROM users WHERE role = 'admin') >= 1
        BEGIN
            SELECT RAISE(ABORT, 'Only one Admin is allowed');
        END
    ");

    $pdo->exec("DROP TRIGGER IF EXISTS trg_users_single_admin_upd");
    $pdo->exec("
        CREATE TRIGGER trg_users_single_admin_upd
        BEFORE UPDATE OF role ON users
        WHEN NEW.role = 'admin' AND (SELECT COUNT(*) FROM users WHERE role = 'admin' AND id != OLD.id) >= 1
        BEGIN
            SELECT RAISE(ABORT, 'Only one Admin is allowed');
        END
    ");

    return [
        'counselors_created' => $counselorsCreated,
        'triggers_installed' => [
            'trg_users_single_super_admin_ins',
            'trg_users_single_super_admin_upd',
            'trg_users_single_admin_ins',
            'trg_users_single_admin_upd',
        ],
    ];
};
