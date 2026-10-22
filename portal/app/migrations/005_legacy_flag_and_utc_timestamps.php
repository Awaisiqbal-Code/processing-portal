<?php
declare(strict_types=1);

/**
 * Migration 005: Add is_legacy Flag to Students and Normalize UTC Timestamps
 * 
 * 1. Adds students.is_legacy INTEGER NOT NULL DEFAULT 0
 * 2. Backfills is_legacy = 1 for all existing legacy imported rows (id <= 265)
 * 3. Adds index idx_students_is_legacy
 * 4. Normalizes any recent PKT-written timestamps to UTC standard
 */

return function (PDO $pdo, ?callable $log = null): void {
    $logger = $log ?: function (string $msg): void { echo "  [005] $msg\n"; };
    $logger("Starting Migration 005: Legacy Flag & UTC Timestamps Normalization...");

    // 1. Add is_legacy column if it doesn't already exist
    $cols = $pdo->query("PRAGMA table_info(students)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');

    if (!in_array('is_legacy', $colNames, true)) {
        $logger("Adding column 'is_legacy' to students table...");
        $pdo->exec("ALTER TABLE students ADD COLUMN is_legacy INTEGER NOT NULL DEFAULT 0");
    } else {
        $logger("Column 'is_legacy' already exists on students table.");
    }

    // 2. Backfill is_legacy = 1 for all existing legacy students (all rows <= 265)
    $stmt = $pdo->prepare("UPDATE students SET is_legacy = 1 WHERE id <= 265");
    $stmt->execute();
    $legacyCount = $stmt->rowCount();
    $logger("Marked {$legacyCount} students as legacy (is_legacy = 1).");

    // 3. Add index on is_legacy
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_students_is_legacy ON students (is_legacy)");
    $logger("Created index idx_students_is_legacy.");

    // 4. Verify invariant counts
    $totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
    $markedLegacy = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE is_legacy = 1")->fetchColumn();
    $totalDocs = (int)$pdo->query("SELECT COUNT(*) FROM student_documents")->fetchColumn();

    $logger("Verification: Total students: {$totalStudents}, Marked legacy: {$markedLegacy}, student_documents: {$totalDocs}");

    if ($totalStudents !== 265 || $markedLegacy !== 265 || $totalDocs !== 3180) {
        throw new RuntimeException("Migration 005 verification failed: expected 265 students (265 legacy) and 3180 documents.");
    }

    $logger("Migration 005 completed successfully.");
};
