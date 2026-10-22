<?php
declare(strict_types=1);

/**
 * Production Credential Generator CLI Script
 *
 * Usage:
 *   php portal/app/cli_generate_production_credentials.php
 *   php portal/app/cli_generate_production_credentials.php --all
 *   php portal/app/cli_generate_production_credentials.php --file=/custom/path/credentials.txt
 *
 * Security:
 * - CLI only (refuses execution from web SAPI)
 * - Generates cryptographically secure, random 12-character passwords per account
 * - Updates bcrypt password hashes directly in database
 * - Writes credentials to a protected file outside web root (/data/credentials_<date>.txt or ../credentials_<date>.txt)
 * - Never prints raw passwords to stdout, logs, or chat
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI access only.\n";
    exit(1);
}

require_once __DIR__ . '/bootstrap.php';

function generate_secure_password(int $length = 12): string {
    // Unambiguous character set (no 0/O, 1/l/I)
    $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz!@#$%&*';
    $max = strlen($chars) - 1;
    $pw = '';
    for ($i = 0; $i < $length; $i++) {
        $pw .= $chars[random_int(0, $max)];
    }
    return $pw;
}

// Parse CLI options
$options = getopt('', ['all', 'file::', 'role::']);
$includeAll = isset($options['all']);
$targetRole = $options['role'] ?? ($includeAll ? null : 'counselor');

$targetFile = $options['file'] ?? null;
if (!$targetFile) {
    $dateStr = date('Ymd_His');
    if (is_dir('/data') && is_writable('/data')) {
        $targetFile = "/data/credentials_{$dateStr}.txt";
    } else {
        $targetFile = dirname(ROOT) . "/credentials_counselors_{$dateStr}.txt";
    }
}

$pdo = db();
$pdo->beginTransaction();

try {
    $query = "SELECT id, username, name, role, active FROM users";
    $params = [];
    if ($targetRole) {
        $query .= " WHERE role = ? AND active = 1";
        $params[] = $targetRole;
    } else {
        $query .= " WHERE role != 'student' AND active = 1";
    }
    $query .= " ORDER BY role, username";

    $users = q($query, $params)->fetchAll();

    if (empty($users)) {
        echo "No matching active accounts found to generate credentials for.\n";
        $pdo->rollBack();
        exit(0);
    }

    $lines = [];
    $lines[] = "================================================================================";
    $lines[] = " CONFIDENTIAL PRODUCTION STAFF CREDENTIALS";
    $lines[] = " Generated: " . gmdate('Y-m-d H:i:s') . " UTC";
    $lines[] = " Database:  " . (getenv('PORTAL_SQLITE') ?: (APP . '/portal.sqlite'));
    $lines[] = " Notice:    Distribute securely to staff. DELETE this file immediately after!";
    $lines[] = "================================================================================";
    $lines[] = sprintf("%-18s | %-15s | %-22s | %-16s", "USERNAME", "ROLE", "NAME", "TEMPORARY PASSWORD");
    $lines[] = str_repeat("-", 80);

    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $updatedCount = 0;

    foreach ($users as $u) {
        $plainPassword = generate_secure_password(12);
        $hash = password_hash($plainPassword, PASSWORD_BCRYPT);

        $stmt->execute([$hash, (int)$u['id']]);

        $lines[] = sprintf(
            "%-18s | %-15s | %-22s | %-16s",
            $u['username'],
            $u['role'],
            mb_substr((string)$u['name'], 0, 22),
            $plainPassword
        );
        $updatedCount++;
    }

    $lines[] = str_repeat("=", 80);
    $lines[] = "Total Accounts Updated: {$updatedCount}";
    $lines[] = "================================================================================";

    $content = implode("\n", $lines) . "\n";

    // Write file outside web root
    $dir = dirname($targetFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $written = file_put_contents($targetFile, $content, LOCK_EX);
    if ($written === false) {
        throw new RuntimeException("Failed to write credentials file at: {$targetFile}");
    }
    @chmod($targetFile, 0600);

    $pdo->commit();

    echo "=== Production Credential Generation Complete ===\n";
    echo "Accounts updated: {$updatedCount}\n";
    echo "Output file:      {$targetFile}\n";
    echo "File size:        {$written} bytes\n";
    echo "File permissions: 0600 (read/write by owner only)\n";
    echo "\nIMPORTANT: The passwords have NOT been printed to this console or logged.\n";
    echo "Read the output file directly and delete it once distributed.\n";

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "[ERROR] Credential generation failed: " . $e->getMessage() . "\n";
    exit(1);
}
