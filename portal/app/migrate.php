<?php
declare(strict_types=1);

/**
 * Safe Database Migration Runner (Phase 1+)
 * - Suppresses all notifications ($GLOBALS['MIGRATION_RUNNING'] = true)
 * - Backs up portal.sqlite to /data/backups/ (or ../backups/ locally) and keeps the latest 10 backups
 * - Executes migrations inside PRAGMA foreign_keys=OFF + BEGIN IMMEDIATE + COMMIT + PRAGMA foreign_key_check
 */

$GLOBALS['MIGRATION_RUNNING'] = true;

if (!defined('APP')) {
    require_once __DIR__ . '/bootstrap.php';
}

function get_table_counts(PDO $pdo): array {
    $tables = ['students', 'users', 'student_documents', 'activity_log'];
    $counts = [];
    foreach ($tables as $t) {
        $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($t))->fetchColumn();
        $counts[$t] = $exists ? (int)$pdo->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn() : 0;
    }
    $roleBreakdown = [];
    if ($counts['users'] > 0) {
        foreach ($pdo->query("SELECT role, COUNT(*) AS cnt FROM users GROUP BY role ORDER BY role") as $r) {
            $roleBreakdown[$r['role']] = (int)$r['cnt'];
        }
    }
    $counts['users_by_role'] = $roleBreakdown;
    return $counts;
}

function create_migration_backup(): string {
    $dbPath = getenv('PORTAL_SQLITE') ?: (APP . '/portal.sqlite');
    if (!is_file($dbPath)) {
        throw new RuntimeException("Database file not found at: $dbPath");
    }

    $volStatus = railway_volume_status();
    $backupDir = $volStatus['backup_dir'];
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0775, true);
    }
    if (!is_dir($backupDir) || !is_writable($backupDir)) {
        // Fallback if parent dir is not writable
        $backupDir = APP . '/backups';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0775, true);
        }
    }

    $ts = date('Ymd_His');
    $backupFile = rtrim($backupDir, '/\\') . '/portal_' . $ts . '.sqlite';
    // Avoid overwriting if multiple runs happen in the same second
    $suffix = 1;
    while (is_file($backupFile)) {
        $backupFile = rtrim($backupDir, '/\\') . '/portal_' . $ts . '_' . $suffix . '.sqlite';
        $suffix++;
    }

    if (!copy($dbPath, $backupFile)) {
        throw new RuntimeException("Failed to create database backup at: $backupFile");
    }

    $origSize = filesize($dbPath);
    $backSize = filesize($backupFile);
    if ($backSize === false || $backSize < $origSize || $backSize === 0) {
        throw new RuntimeException("Backup verification failed: original={$origSize} bytes, backup={$backSize} bytes");
    }

    // Rotate backups: keep the latest 10
    $files = glob(rtrim($backupDir, '/\\') . '/portal_*.sqlite') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    if (count($files) > 10) {
        foreach (array_slice($files, 10) as $oldFile) {
            @unlink($oldFile);
        }
    }

    return $backupFile;
}

function run_migrations(bool $verbose = true): array {
    $GLOBALS['MIGRATION_RUNNING'] = true;
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS schema_migrations (
            version TEXT PRIMARY KEY,
            applied_at TEXT NOT NULL
        )
    ");

    $applied = $pdo->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
    $appliedMap = array_fill_keys($applied, true);

    $migFiles = glob(APP . '/migrations/*.php') ?: [];
    sort($migFiles);

    $results = [];
    foreach ($migFiles as $file) {
        $version = basename($file, '.php');
        if (isset($appliedMap[$version])) {
            if ($verbose && PHP_SAPI === 'cli') {
                echo "[Skip] Migration {$version} already applied.\n";
            }
            continue;
        }

        $backupPath = create_migration_backup();
        $beforeCounts = get_table_counts($pdo);

        if ($verbose && PHP_SAPI === 'cli') {
            echo "=== Running Migration: {$version} ===\n";
            echo "Backup created: {$backupPath} (" . filesize($backupPath) . " bytes)\n";
            echo "Before counts: " . json_encode($beforeCounts) . "\n";
        }

        $migrationFn = require $file;
        if (!is_callable($migrationFn)) {
            throw new RuntimeException("Migration file {$file} must return a callable.");
        }

        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $migrationDetails = $migrationFn($pdo);
            $afterCounts = get_table_counts($pdo);

            // Safety check: existing data rows in core tables must never decrease
            foreach (['students', 'users', 'student_documents', 'activity_log'] as $coreTable) {
                if ($afterCounts[$coreTable] < $beforeCounts[$coreTable]) {
                    throw new RuntimeException(
                        "Row count decreased for {$coreTable}: before={$beforeCounts[$coreTable]}, after={$afterCounts[$coreTable]}"
                    );
                }
            }

            $stmt = $pdo->prepare("INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)");
            $stmt->execute([$version, now()]);

            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            $pdo->exec('PRAGMA foreign_keys = ON');
            throw $e;
        }

        $pdo->exec('PRAGMA foreign_keys = ON');
        $fkErrors = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
        if (!empty($fkErrors)) {
            throw new RuntimeException('PRAGMA foreign_key_check failed: ' . json_encode($fkErrors));
        }

        $afterCounts = get_table_counts($pdo);
        if ($verbose && PHP_SAPI === 'cli') {
            echo "After counts:  " . json_encode($afterCounts) . "\n";
            echo "PRAGMA foreign_key_check: OK (0 errors)\n";
            echo "=== Completed Migration: {$version} ===\n\n";
        }

        $results[$version] = [
            'backup'  => $backupPath,
            'before'  => $beforeCounts,
            'after'   => $afterCounts,
            'details' => $migrationDetails,
        ];
    }

    $GLOBALS['MIGRATION_RUNNING'] = false;
    return $results;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $vol = railway_volume_status();
    echo "=== Environment & Volume Check ===\n";
    echo json_encode($vol, JSON_PRETTY_PRINT) . "\n\n";
    $res = run_migrations(true);
    if (empty($res)) {
        echo "All migrations are up to date.\n";
        echo "Current counts: " . json_encode(get_table_counts(db()), JSON_PRETTY_PRINT) . "\n";
    }
}
