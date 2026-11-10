<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    global $CONFIG;
    if ($pdo) return $pdo;
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
