<?php
declare(strict_types=1);

/**
 * Migration 019 — Indexes so common lists read only the rows they need (the online database counts rows read).
 */
return function (PDO $pdo): void {
    foreach ([
        'CREATE INDEX IF NOT EXISTS idx_users_role_active ON users (role, active)',
        'CREATE INDEX IF NOT EXISTS idx_students_country ON students (country)',
        'CREATE INDEX IF NOT EXISTS idx_students_officer ON students (officer_id)',
        'CREATE INDEX IF NOT EXISTS idx_students_frontend_officer ON students (frontend_officer_id)',
        'CREATE INDEX IF NOT EXISTS idx_student_documents_student ON student_documents (student_id)',
        'CREATE INDEX IF NOT EXISTS idx_activity_log_created ON activity_log (created_at)',
    ] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { if (!str_contains($e->getMessage(), 'no such column')) throw $e; }
    }
    echo "  -> indexes added\n";
};
