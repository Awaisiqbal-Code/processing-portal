<?php
declare(strict_types=1);

/**
 * Migration 007: Single active session, processing journey, assignment history,
 * communication log and small schema fixes.
 *
 * - users.session_token / session_started_at / session_ip / session_agent
 *     One account = one active browser. A new sign-in replaces the token and the
 *     older browser is signed out on its next request (banking-style).
 * - user_session_events: audit of sign-ins, replaced sessions and sign-outs.
 * - students.processing_status: optional manual override of the order-style status
 *     (New / Pending / In Progress / Completed / On Hold / Rejected). NULL = automatic.
 * - student_stages + student_stage_history: 8-step processing journey.
 * - assignment_history: counselor / front-end / back-end / PMS lead changes.
 * - student_communications: calls, WhatsApp, emails and meetings with the student.
 * - student_applications.updated_at (was referenced by code but missing → crash on save).
 *
 * Purely additive: no existing row or column is modified or removed.
 */
return function (PDO $pdo): void {
    $cols = fn(string $t) => array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');

    $userCols = $cols('users');
    foreach ([
        'session_token'      => 'TEXT NULL',
        'session_started_at' => 'TEXT NULL',
        'session_ip'         => 'TEXT NULL',
        'session_agent'      => 'TEXT NULL',
    ] as $c => $def) {
        if (!in_array($c, $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN $c $def");
            echo "  -> users.$c added\n";
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_session_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        event TEXT NOT NULL,
        ip TEXT NULL,
        agent TEXT NULL,
        created_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_session_events_user ON user_session_events(user_id, created_at)");

    $stCols = $cols('students');
    if (!in_array('processing_status', $stCols, true)) {
        $pdo->exec("ALTER TABLE students ADD COLUMN processing_status TEXT NULL");
        echo "  -> students.processing_status added\n";
    }

    $appCols = $cols('student_applications');
    if (!in_array('updated_at', $appCols, true)) {
        $pdo->exec("ALTER TABLE student_applications ADD COLUMN updated_at TEXT NULL");
        echo "  -> student_applications.updated_at added\n";
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS student_stages (
        student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
        stage_key TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'Not Started',
        stage_date TEXT NULL,
        assigned_to INTEGER NULL REFERENCES users(id),
        notes TEXT NULL,
        updated_by INTEGER NULL REFERENCES users(id),
        updated_at TEXT NOT NULL,
        PRIMARY KEY (student_id, stage_key)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS student_stage_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
        stage_key TEXT NOT NULL,
        old_status TEXT NULL,
        new_status TEXT NOT NULL,
        stage_date TEXT NULL,
        assigned_to INTEGER NULL,
        notes TEXT NULL,
        changed_by INTEGER NULL,
        changed_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_stage_history_student ON student_stage_history(student_id, changed_at)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS assignment_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
        field TEXT NOT NULL,
        old_user_id INTEGER NULL,
        old_name TEXT NULL,
        new_user_id INTEGER NULL,
        new_name TEXT NULL,
        note TEXT NULL,
        changed_by INTEGER NULL,
        changed_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_assignment_history_student ON assignment_history(student_id, changed_at)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS student_communications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
        channel TEXT NOT NULL,
        direction TEXT NULL,
        summary TEXT NOT NULL,
        follow_up_date TEXT NULL,
        contact_date TEXT NOT NULL,
        created_by INTEGER NULL REFERENCES users(id),
        created_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comms_student ON student_communications(student_id, contact_date)");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_remarks_author ON student_remarks(author_id, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_docs_updated ON student_documents(updated_by, updated_at)");
    echo "  -> journey, assignment history and communication tables ready\n";
};
