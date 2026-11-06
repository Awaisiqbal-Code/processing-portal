<?php
declare(strict_types=1);

/**
 * Migration 008: payments, meeting recording options and document source.
 * Purely additive — no existing row or column is changed.
 *
 * - student_payments: 1st / 2nd / 3rd (and further) payments with status, amount, date, method, note.
 * - students.meeting_note: why a meeting was not recorded (if it was not).
 * - students.meeting_video_path / meeting_video_name: a recording uploaded directly to the portal.
 * - students.docs_source: 'drive' (documents kept in a Google Drive folder) or 'upload' (uploaded here).
 */
return function (PDO $pdo): void {
    $cols = array_column($pdo->query('PRAGMA table_info(students)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['meeting_note' => 'TEXT NULL', 'meeting_video_path' => 'TEXT NULL', 'meeting_video_name' => 'TEXT NULL', 'docs_source' => 'TEXT NULL'] as $c => $def) {
        if (!in_array($c, $cols, true)) {
            $pdo->exec("ALTER TABLE students ADD COLUMN $c $def");
            echo "  -> students.$c added\n";
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
        installment_no INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'Pending',
        amount TEXT NULL,
        received_date TEXT NULL,
        method TEXT NULL,
        note TEXT NULL,
        updated_by INTEGER NULL REFERENCES users(id),
        updated_at TEXT NOT NULL,
        UNIQUE (student_id, installment_no)
    )");
    echo "  -> student_payments ready\n";
};
