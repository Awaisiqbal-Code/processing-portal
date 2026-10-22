<?php
declare(strict_types=1);

/**
 * Migration 004: Add pending_since Column to students Table
 *
 * Adds students.pending_since to fix the pending timer reset bug where
 * repeated staff edits could postpone 48h/72h alerts indefinitely.
 * - pending_since is set to now() when a qualifying edit occurs and pending_since IS NULL
 * - further edits while pending leave pending_since unchanged
 * - clearing remarks set pending_since = NULL
 */
return function(PDO $pdo): void {
    // 1. Check if pending_since column exists
    $cols = $pdo->query("PRAGMA table_info(students)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');

    if (!in_array('pending_since', $colNames, true)) {
        $pdo->exec("ALTER TABLE students ADD COLUMN pending_since TEXT NULL");
        echo "  -> Added pending_since column to students\n";
    } else {
        echo "  -> pending_since column already present\n";
    }

    // 2. Create non-colliding index on pending_since
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_students_pending_since ON students(pending_since)");
    echo "  -> Ensured index idx_students_pending_since exists\n";

    // 3. Backfill pending_since for any students currently pending
    $updated = $pdo->exec("
        UPDATE students
        SET pending_since = last_edited_at
        WHERE last_edited_at IS NOT NULL
          AND (last_remark_at IS NULL OR last_remark_at < last_edited_at)
          AND pending_since IS NULL
    ");
    echo "  -> Backfilled pending_since for $updated pending student(s)\n";
};
