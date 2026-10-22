<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    // Production Condition 4: For HTTP calls, require CRON_SECRET in X-Cron-Secret header (never query string)
    if (isset($_GET['cron_secret']) || isset($_GET['secret'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'CRON_SECRET must be passed in the X-Cron-Secret header, not the query string.']);
        exit;
    }

    $configuredSecret = (string)setting('cron_secret', $CONFIG['cron_secret'] ?? '');
    $providedSecret   = (string)($_SERVER['HTTP_X_CRON_SECRET'] ?? '');

    if ($configuredSecret === '' || !hash_equals($configuredSecret, $providedSecret)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid or missing X-Cron-Secret header.']);
        exit;
    }
}

// Acquire non-blocking exclusive file lock so overlapping cron runs cannot collide
$lockDir = is_dir('/data') && is_writable('/data') ? '/data' : sys_get_temp_dir();
$lockFile = rtrim($lockDir, '/\\') . DIRECTORY_SEPARATOR . 'ptp_cron_reminders.lock';
$fp = @fopen($lockFile, 'c+');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'Another reminder run is currently in progress.']);
    exit;
}

ftruncate($fp, 0);
fwrite($fp, (string)getmypid() . ' ' . now());

try {
    $cronRes = process_pending_reminders();
    $out = [
        'ok'               => true,
        'mode'             => $isCli ? 'cli' : 'http',
        'ran_at'           => now(),
        'lock_file'        => $lockFile,
        'pending_count'    => $cronRes['pending_count'],
        'reminders_sent'   => $cronRes['reminders_sent'],
        'escalations_sent' => $cronRes['escalations_sent'],
    ];
    if ($isCli) {
        echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo json_encode($out);
    }
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}
