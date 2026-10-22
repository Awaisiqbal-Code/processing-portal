<?php
declare(strict_types=1);

/**
 * Migration 003: Student Data Model & Lifecycle Schema (Phase 2)
 *
 * Rules:
 * - student_documents is left 100% untouched.
 * - csc_status, csc_verified_at, csc_verified_by are already present and reused.
 * - Add refund_date and refund_note; is_refunded defaults to 0 for all existing students.
 * - Explicit counselor variant -> canonical username map resolves all spelling variants.
 * - Legacy remarks migrated to student_remarks with author 'Legacy import' and
 *   created_at = COALESCE(updated_at, created_at).
 * - students.remarks stays synchronized for backward compatibility.
 * - zero notifications fired ($GLOBALS['MIGRATION_RUNNING'] = true).
 */
return function (PDO $pdo): array {
    // 1. Add new columns to students table
    $studentCols = $pdo->query("PRAGMA table_info(students)")->fetchAll(PDO::FETCH_ASSOC);
    $existingColNames = array_map(fn($c) => strtolower((string)$c['name']), $studentCols);

    $newColumns = [
        'student_status'        => "TEXT NOT NULL DEFAULT 'active' CHECK (student_status IN ('active','inactive','disabled'))",
        'program_level'         => "TEXT NULL CHECK (program_level IN ('BS','MS') OR program_level IS NULL)",
        'country'               => "TEXT NULL",
        'disabled_reason'       => "TEXT NULL",
        'disabled_by'           => "INTEGER NULL REFERENCES users(id)",
        'disabled_at'           => "TEXT NULL",
        'is_refunded'           => "INTEGER NOT NULL DEFAULT 0",
        'refund_date'           => "TEXT NULL",
        'refund_note'           => "TEXT NULL",
        'assigned_pms_lead_id'  => "INTEGER NULL REFERENCES users(id)",
        'assigned_counselor_id' => "INTEGER NULL REFERENCES users(id)",
        'last_edited_at'        => "TEXT NULL",
        'last_edited_by'        => "INTEGER NULL REFERENCES users(id)",
        'last_remark_at'        => "TEXT NULL",
    ];

    foreach ($newColumns as $colName => $colDef) {
        if (!in_array(strtolower($colName), $existingColNames, true)) {
            $pdo->exec("ALTER TABLE students ADD COLUMN {$colName} {$colDef}");
        }
    }

    // 2. Indexes for students (verified no name collisions exist)
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_students_country ON students(country)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_students_status ON students(student_status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_students_program_level ON students(program_level)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_students_pms_lead ON students(assigned_pms_lead_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_students_counselor_id ON students(assigned_counselor_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_students_last_remark_at ON students(last_remark_at)");

    // 3. New append-only student_remarks table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS student_remarks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
            remark_text TEXT NOT NULL,
            author_id INTEGER NULL REFERENCES users(id),
            author_name TEXT NOT NULL,
            created_at TEXT NOT NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_remarks_student ON student_remarks(student_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_remarks_created ON student_remarks(created_at)");

    // 4. New consent_templates & consent_documents tables (student_documents is untouched)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS consent_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            country TEXT NULL,
            title TEXT NOT NULL,
            file_path TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            file_size INTEGER NOT NULL,
            uploaded_by INTEGER NULL REFERENCES users(id),
            created_at TEXT NOT NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_consent_templates_country ON consent_templates(country)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS consent_documents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
            template_id INTEGER NULL REFERENCES consent_templates(id),
            file_path TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            file_size INTEGER NOT NULL,
            uploaded_by INTEGER NULL REFERENCES users(id),
            created_at TEXT NOT NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_consent_docs_student ON consent_documents(student_id)");

    // 5. New notifications table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            type TEXT NOT NULL,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            student_id INTEGER NULL REFERENCES students(id) ON DELETE SET NULL,
            is_read INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_user_unread ON notifications(user_id, is_read)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_notifications_created ON notifications(created_at)");

    // 6. PMS Lead Assignment & Default Setting
    $mubeenId = (int)$pdo->query("SELECT id FROM users WHERE username = 'mubeen.fatima' LIMIT 1")->fetchColumn();
    if ($mubeenId <= 0) {
        throw new RuntimeException("Mubeen Fatima account not found for PMS Lead assignment.");
    }

    $insSetting = $pdo->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))");
    $insSetting->execute(['default_pms_lead_id', (string)$mubeenId]);

    // Backfill all 265 existing students with Mubeen Fatima as assigned PMS Lead
    $pdo->prepare("UPDATE students SET assigned_pms_lead_id = ?")->execute([$mubeenId]);

    // Set lifecycle defaults on existing students
    $pdo->exec("UPDATE students SET student_status = 'active' WHERE student_status IS NULL");
    $pdo->exec("UPDATE students SET is_refunded = 0, refund_date = NULL, refund_note = NULL");
    $pdo->exec("UPDATE students SET last_edited_at = NULL, last_edited_by = NULL");
    $pdo->exec("UPDATE students SET program_level = NULL, country = NULL");

    // 7. Explicit Counselor Variant Mapping
    $counselorUsers = $pdo->query("SELECT id, username FROM users WHERE role = 'counselor'")->fetchAll(PDO::FETCH_ASSOC);
    $counselorIdMap = [];
    foreach ($counselorUsers as $cu) {
        $counselorIdMap[$cu['username']] = (int)$cu['id'];
    }

    $variantToUsername = [
        'ali haider'     => 'ali.haider',
        'umar'           => 'umar',
        'ansa'           => 'ansa',
        'sheraz'         => 'sheraz',
        'khadija'        => 'khadija',
        'khadijah'       => 'khadija',
        'usman riaz'     => 'usman.riaz',
        'usman raiz'     => 'usman.riaz',
        'usman'          => 'usman.riaz',
        'ali naqvi'      => 'ali.naqvi',
        'ali naqvia'     => 'ali.naqvi',
        'suneya'         => 'suneya',
        'sunnaya'        => 'suneya',
        'awais shahzad'  => 'awais.shahzad',
        'awais'          => 'awais.shahzad',
        'maryam'         => 'maryam',
        'kamran'         => 'kamran',
        'noor fatima'    => 'noor.fatima',
        'ayesha khalil'  => 'ayesha.khalil',
        'daniyal'        => 'daniyal',
        'danyial'        => 'daniyal',
        'inza'           => 'inza',
        'hamna'          => 'hamna',
        'talha'          => 'talha',
        'waseem'         => 'waseem',
        'salah ud deen'  => 'salah.ud.deen',
        'salah ul deen'  => 'salah.ud.deen',
        'salah ud din'   => 'salah.ud.deen',
        'saalh ul deen'  => 'salah.ud.deen',
        'salah hu din'   => 'salah.ud.deen',
        'salh ud din'    => 'salah.ud.deen',
        'salah'          => 'salah.ud.deen',
        'salh'           => 'salah.ud.deen',
    ];

    $allStudents = $pdo->query("SELECT id, name, counselor FROM students ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $mappedCount = 0;
    $unmapped = [];
    $updateCounselor = $pdo->prepare("UPDATE students SET assigned_counselor_id = ? WHERE id = ?");

    foreach ($allStudents as $st) {
        $raw = $st['counselor'];
        if ($raw === null || trim($raw) === '') {
            $unmapped[] = ['id' => $st['id'], 'name' => $st['name'], 'raw' => $raw, 'reason' => 'Empty'];
            continue;
        }
        $normalized = strtolower(trim($raw));
        $normalized = (string)preg_replace('/\s+/', ' ', $normalized);

        $targetUsername = $variantToUsername[$normalized] ?? null;
        if ($targetUsername && isset($counselorIdMap[$targetUsername])) {
            $updateCounselor->execute([$counselorIdMap[$targetUsername], (int)$st['id']]);
            $mappedCount++;
        } else {
            $unmapped[] = ['id' => $st['id'], 'name' => $st['name'], 'raw' => $raw, 'normalized' => $normalized];
        }
    }

    if (!empty($unmapped)) {
        // Abort if any unexpected unmapped value is found
        $err = "Counselor mapping aborted: " . count($unmapped) . " unmapped values: " . json_encode($unmapped);
        throw new RuntimeException($err);
    }

    // 8. Migrate legacy remarks (252 non-empty remarks)
    $remarksMigrated = 0;
    $studentsWithRemarks = $pdo->query("
        SELECT id, remarks, created_at, updated_at
        FROM students
        WHERE remarks IS NOT NULL AND TRIM(remarks) != ''
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $insRemark = $pdo->prepare("
        INSERT INTO student_remarks (student_id, remark_text, author_id, author_name, created_at)
        VALUES (?, ?, NULL, 'Legacy import', ?)
    ");
    $updLastRemark = $pdo->prepare("UPDATE students SET last_remark_at = ? WHERE id = ?");

    foreach ($studentsWithRemarks as $srRow) {
        $remarkText = trim((string)$srRow['remarks']);
        $remarkCreatedAt = $srRow['updated_at'] ?: ($srRow['created_at'] ?: date('Y-m-d H:i:s'));
        $insRemark->execute([(int)$srRow['id'], $remarkText, $remarkCreatedAt]);
        $updLastRemark->execute([$remarkCreatedAt, (int)$srRow['id']]);
        $remarksMigrated++;
    }

    return [
        'counselor_mapping' => [
            'students_total'  => count($allStudents),
            'students_mapped' => $mappedCount,
            'unmapped_count'  => count($unmapped),
            'unmapped_list'   => $unmapped,
        ],
        'default_pms_lead_id'   => $mubeenId,
        'remarks_migrated'      => $remarksMigrated,
        'remarks_null_count'    => count($allStudents) - $remarksMigrated,
        'student_documents_row' => (int)$pdo->query("SELECT COUNT(*) FROM student_documents")->fetchColumn(),
    ];
};
