<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    global $CONFIG;
    if (!empty($GLOBALS['DB_OVERRIDE'])) return $GLOBALS['DB_OVERRIDE'];   // first-start preparation of the Turso data
    if ($pdo) return $pdo;
    if (function_exists('turso_enabled') && turso_enabled()) {
        // Permanent online database shared by every server copy (Vercel). See app/turso.php.
        try {
            $t = turso_pdo();
            if (!turso_ensure_ready($t)) exit;
        } catch (Throwable $e) {
            // Show the reason (it never contains the token) so the setup can be fixed without digging in the host logs.
            $why = preg_replace('/^SQLSTATE\[\w+\]: General error: /', '', $e->getMessage());
            $where = basename($e->getFile()) . ':' . $e->getLine();
            error_log('Turso connection failed: ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $where);
            http_response_code(503);
            header('Cache-Control: no-store');
            echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Database</title>'
               . '<body style="font-family:system-ui,sans-serif;max-width:720px;margin:40px auto;padding:0 16px;line-height:1.5">'
               . '<h1 style="font-size:20px">The portal cannot reach its online database</h1>'
               . '<p>Reason: <code style="background:#f1f5f9;padding:2px 6px;border-radius:6px;word-break:break-word">' . htmlspecialchars($why, ENT_QUOTES) . '</code></p>'
               . '<p style="color:#64748b;font-size:14px">' . htmlspecialchars(get_class($e) . ' · ' . $where, ENT_QUOTES) . ' · Refresh in a minute; if it stays, send this page to your developer.</p></body>';
            exit;
        }
        return $pdo = $t;
    }
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        if (!empty($CONFIG['dsn'])) {               // used for local testing only
            $pdo = new PDO($CONFIG['dsn'], null, null, $opts);
            if (str_starts_with((string)$CONFIG['dsn'], 'sqlite:')) {
                // Many staff saving at once: wait for a lock instead of failing, and let reads run during writes
                $pdo->exec('PRAGMA busy_timeout = 8000');
                try { $pdo->exec('PRAGMA journal_mode = WAL'); } catch (Throwable $e) { /* read-only copy: keep default */ }
                $pdo->exec('PRAGMA synchronous = NORMAL');
            }
        } else {
            $dsn = 'mysql:host=' . $CONFIG['db_host'] . ';dbname=' . $CONFIG['db_name'] . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $CONFIG['db_user'], $CONFIG['db_pass'], $opts);
        }
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('The portal cannot reach its database. Check app/config.php.');
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
