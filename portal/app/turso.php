<?php
declare(strict_types=1);

/**
 * Turso (online SQLite) connection for hosts without a permanent disk, such as Vercel.
 *
 * When TURSO_DATABASE_URL (and TURSO_AUTH_TOKEN) are set, db() returns a TursoPDO: it behaves like the normal
 * PDO connection the portal already uses, but every query is sent over HTTPS to one permanent Turso database
 * that all server copies share. Nothing is kept in /tmp any more, so restarts and new deploys lose nothing.
 *
 * First start: the portal fills the empty Turso database itself from the bundled starting database
 * (after applying all migrations locally), in chunks, showing a short "setting up" page while it works.
 */

const TURSO_HTTP_TIMEOUT = 25;

function turso_enabled(): bool {
    return (string)getenv('TURSO_DATABASE_URL') !== '';
}

/** https://<db>-<org>.turso.io from libsql://… or https://… */
function turso_base_url(): string {
    $u = trim((string)getenv('TURSO_DATABASE_URL'));
    $u = preg_replace('#^libsql://#i', 'https://', $u);
    if (!preg_match('#^https?://#i', $u)) $u = 'https://' . $u;
    return rtrim($u, '/');
}

final class TursoException extends PDOException {}

/** Low-level client for Turso's HTTP API (POST /v2/pipeline). Keeps one connection ("baton") for the request. */
final class TursoClient {
    private string $base;
    private string $token;
    private ?string $baton = null;
    private ?string $routeUrl = null;
    private $curl = null;
    public int $calls = 0;

    public function __construct(string $base, string $token) {
        $this->base = $base;
        $this->token = $token;
    }

    public static function enc(mixed $v): array {
        if ($v === null) return ['type' => 'null'];
        if (is_bool($v)) return ['type' => 'integer', 'value' => $v ? '1' : '0'];
        if (is_int($v)) return ['type' => 'integer', 'value' => (string)$v];
        if (is_float($v)) return ['type' => 'float', 'value' => $v];
        return ['type' => 'text', 'value' => (string)$v];
    }

    public static function dec(array $v): mixed {
        return match ($v['type'] ?? 'null') {
            'integer' => (int)$v['value'],
            'float'   => (float)$v['value'],
            'text'    => (string)$v['value'],
            'blob'    => base64_decode((string)($v['base64'] ?? '')),
            default   => null,
        };
    }

    /** Runs a list of Hrana requests on the kept connection and returns their results (throws on SQL errors). */
    public function pipeline(array $requests, bool $retry = true): array {
        $body = ['requests' => $requests];
        if ($this->baton !== null) $body['baton'] = $this->baton;
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $url = ($this->routeUrl ?: $this->base) . '/v2/pipeline';
        $attempts = ($retry && $this->baton === null) ? 3 : 1;   // a kept connection is not retried (it may hold a transaction)
        $err = '';
        for ($i = 0; $i < $attempts; $i++) {
            [$status, $raw, $err] = $this->post($url, $json);
            if ($status === 200 && $raw !== '') break;
            if ($status >= 400 && $status < 500 && $status !== 429) break;   // auth / bad request: retrying will not help
            usleep(150000 * ($i + 1));
        }
        if ($status !== 200) {
            $this->baton = null; $this->routeUrl = null;
            $msg = $status === 401 || $status === 403 ? 'the Turso token was refused (check TURSO_AUTH_TOKEN)' : "HTTP $status $err " . substr((string)$raw, 0, 300);
            throw new TursoException('Turso database: ' . $msg);
        }
        $res = json_decode((string)$raw, true);
        if (!is_array($res)) throw new TursoException('Turso database: unreadable reply');
        $this->baton = $res['baton'] ?? null;
        if (!empty($res['base_url'])) $this->routeUrl = rtrim((string)$res['base_url'], '/');
        $out = [];
        foreach ($res['results'] ?? [] as $r) {
            if (($r['type'] ?? '') === 'error') {
                $m = (string)($r['error']['message'] ?? 'unknown error');
                $code = (string)($r['error']['code'] ?? 'HY000');
                $ex = new TursoException('SQLSTATE[HY000]: General error: ' . $m);
                $ex->errorInfo = ['HY000', 1, $m . ' (' . $code . ')'];
                throw $ex;
            }
            $out[] = $r['response'] ?? [];
        }
        return $out;
    }

    public function close(): void {
        if ($this->baton === null) return;
        try { $this->pipeline([['type' => 'close']], false); } catch (Throwable) {}
        $this->baton = null;
    }

    public function dropConnection(): void { $this->baton = null; }

    private function post(string $url, string $json): array {
        $this->calls++;
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $this->token];
        if (function_exists('curl_init')) {
            if ($this->curl === null) $this->curl = curl_init();
            curl_setopt_array($this->curl, [
                CURLOPT_URL => $url, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => TURSO_HTTP_TIMEOUT, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_ENCODING => '', CURLOPT_TCP_KEEPALIVE => 1,
            ]);
            $raw = curl_exec($this->curl);
            $status = (int)curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE);
            return [$status, is_string($raw) ? $raw : '', curl_error($this->curl)];
        }
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $json,
            'timeout' => TURSO_HTTP_TIMEOUT, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
        return [$status, is_string($raw) ? $raw : '', $raw === false ? 'network error' : ''];
    }
}

/** A PDO the rest of the portal can use unchanged; queries go to Turso. */
class TursoPDO extends PDO {
    public TursoClient $client;
    private bool $inTx = false;
    public ?int $lastId = null;
    private array $attrs = [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

    public function __construct(TursoClient $client) { $this->client = $client; }

    public static function isMulti(string $sql): bool {
        $s = rtrim(trim($sql), "; \n\r\t");
        return str_contains($s, ';');
    }

    /** Executes one statement; returns the Hrana result (cols, rows, affected_row_count, last_insert_rowid). */
    public function run(string $sql, array $params = []): array {
        $stmt = ['sql' => $sql, 'want_rows' => true];
        if ($params) {
            if (array_is_list($params)) {
                $stmt['args'] = array_map([TursoClient::class, 'enc'], $params);
            } else {
                $stmt['named_args'] = [];
                foreach ($params as $k => $v) {
                    if (is_int($k)) { $stmt['args'][] = TursoClient::enc($v); continue; }
                    $n = (string)$k;
                    if (!in_array($n[0], [':', '@', '$'], true)) $n = ':' . $n;
                    $stmt['named_args'][] = ['name' => $n, 'value' => TursoClient::enc($v)];
                }
            }
        }
        $r = $this->client->pipeline([['type' => 'execute', 'stmt' => $stmt]]);
        $res = $r[0]['result'] ?? [];
        if (isset($res['last_insert_rowid']) && $res['last_insert_rowid'] !== null && (int)($res['affected_row_count'] ?? 0) > 0) {
            $this->lastId = (int)$res['last_insert_rowid'];
        }
        $this->trackTx($sql);
        return $res;
    }

    private function trackTx(string $sql): void {
        $s = strtoupper(ltrim($sql));
        if (str_starts_with($s, 'BEGIN')) $this->inTx = true;
        elseif (str_starts_with($s, 'COMMIT') || str_starts_with($s, 'END') || str_starts_with($s, 'ROLLBACK')) $this->inTx = false;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new TursoStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $st = new TursoStatement($this, $query);
        $st->execute();
        if ($fetchMode !== null) $st->setFetchMode($fetchMode, ...$fetchModeArgs);
        return $st;
    }

    public function exec(string $statement): int|false {
        if (self::isMulti($statement) && !preg_match('/^\s*CREATE\s+TRIGGER/i', $statement)) {
            $this->client->pipeline([['type' => 'sequence', 'sql' => $statement]]);
            $this->trackTx($statement);
            return 0;
        }
        $res = $this->run($statement);
        return (int)($res['affected_row_count'] ?? 0);
    }

    public function lastInsertId(?string $name = null): string|false { return (string)($this->lastId ?? 0); }
    public function beginTransaction(): bool { $this->run('BEGIN'); return true; }
    public function commit(): bool { $this->run('COMMIT'); return true; }
    public function rollBack(): bool { try { $this->run('ROLLBACK'); } finally { $this->inTx = false; } return true; }
    public function inTransaction(): bool { return $this->inTx; }
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false { return "'" . str_replace("'", "''", $string) . "'"; }
    public function setAttribute(int $attribute, mixed $value): bool { $this->attrs[$attribute] = $value; return true; }
    public function getAttribute(int $attribute): mixed {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'sqlite' : ($this->attrs[$attribute] ?? null);
    }
    public function errorCode(): ?string { return null; }
    public function errorInfo(): array { return ['00000', null, null]; }
    public function defaultFetchMode(): int { return (int)($this->attrs[PDO::ATTR_DEFAULT_FETCH_MODE] ?? PDO::FETCH_ASSOC); }
}

/** Statement with the same fetch behaviour as PDO's SQLite statements. */
class TursoStatement extends PDOStatement {
    private TursoPDO $pdo;
    private string $sql;
    private array $cols = [];
    private array $rows = [];
    private int $pos = 0;
    private int $affected = 0;
    private array $bound = [];
    private ?int $mode = null;
    private array $modeArgs = [];

    public function __construct(TursoPDO $pdo, string $sql) { $this->pdo = $pdo; $this->sql = $sql; }

    public function bindValue(int|string $param, mixed $value, int $type = PDO::PARAM_STR): bool {
        if ($type === PDO::PARAM_INT && $value !== null) $value = (int)$value;
        if (is_int($param)) $this->bound[$param - 1] = $value; else $this->bound[$param] = $value;
        return true;
    }

    public function execute(?array $params = null): bool {
        $p = $params ?? $this->bound;
        if ($p && !$params) { ksort($p); }
        $res = $this->pdo->run($this->sql, $p ?? []);
        $this->cols = array_map(fn($c) => (string)($c['name'] ?? ''), $res['cols'] ?? []);
        $this->rows = [];
        foreach ($res['rows'] ?? [] as $row) $this->rows[] = array_map([TursoClient::class, 'dec'], $row);
        $this->pos = 0;
        $this->affected = (int)($res['affected_row_count'] ?? 0);
        return true;
    }

    private function shape(array $row, int $mode): mixed {
        $m = $mode & ~(PDO::FETCH_GROUP | PDO::FETCH_UNIQUE);
        return match ($m) {
            PDO::FETCH_NUM    => $row,
            PDO::FETCH_BOTH   => array_merge(array_combine($this->cols, $row), $row),
            PDO::FETCH_COLUMN => $row[(int)($this->modeArgs[0] ?? 0)] ?? null,
            PDO::FETCH_OBJ    => (object)array_combine($this->cols, $row),
            default           => array_combine($this->cols, $row),
        };
    }

    private function resolveMode(int $mode): int {
        if ($mode === PDO::FETCH_DEFAULT) return $this->mode ?? $this->pdo->defaultFetchMode();
        return $mode;
    }

    public function setFetchMode(int $mode, mixed ...$args): bool { $this->mode = $mode; $this->modeArgs = $args; return true; }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        if (!isset($this->rows[$this->pos])) return false;
        return $this->shape($this->rows[$this->pos++], $this->resolveMode($mode));
    }

    public function fetchColumn(int $column = 0): mixed {
        if (!isset($this->rows[$this->pos])) return false;
        return $this->rows[$this->pos++][$column] ?? null;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $mode = $this->resolveMode($mode);
        $rest = array_slice($this->rows, $this->pos);
        $this->pos = count($this->rows);
        $base = $mode & ~(PDO::FETCH_GROUP | PDO::FETCH_UNIQUE);
        if ($base === PDO::FETCH_KEY_PAIR) {
            $out = [];
            foreach ($rest as $r) $out[$r[0]] = $r[1] ?? null;
            return $out;
        }
        if ($base === PDO::FETCH_COLUMN) {
            $i = (int)($args[0] ?? 0);
            if ($mode & PDO::FETCH_GROUP) { $out = []; foreach ($rest as $r) $out[$r[0]][] = $r[1] ?? null; return $out; }
            return array_map(fn($r) => $r[$i] ?? null, $rest);
        }
        if ($mode & PDO::FETCH_GROUP) {
            $out = [];
            foreach ($rest as $r) { $k = $r[0]; $out[$k][] = $this->shape(array_slice($r, 1), $base) ; }
            return $out;
        }
        if ($mode & PDO::FETCH_UNIQUE) {
            $out = [];
            foreach ($rest as $r) { $out[$r[0]] = array_combine(array_slice($this->cols, 1), array_slice($r, 1)); }
            return $out;
        }
        return array_map(fn($r) => $this->shape($r, $base), $rest);
    }

    public function rowCount(): int { return $this->affected; }
    public function columnCount(): int { return count($this->cols); }
    public function closeCursor(): bool { return true; }
    public function getIterator(): Iterator {
        while (($r = $this->fetch()) !== false) yield $r;
    }
}

/** PHP sessions stored in the online database (table _portal_sessions), shared by every server copy. */
final class TursoSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface {
    private string $loaded = '';
    private function t(): TursoPDO {
        $t = turso_pdo();
        if (!is_file(turso_marker('sess'))) {
            $t->exec('CREATE TABLE IF NOT EXISTS _portal_sessions (id TEXT PRIMARY KEY, data TEXT NOT NULL, updated INTEGER NOT NULL)');
            @touch(turso_marker('sess'));
        }
        return $t;
    }
    private function life(): int { return max(1440, (int)ini_get('session.gc_maxlifetime')); }
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false {
        try {
            $st = $this->t()->prepare('SELECT data FROM _portal_sessions WHERE id = ? AND updated > ?');
            $st->execute([$id, time() - $this->life()]);
            $d = $st->fetchColumn();
            return $this->loaded = ($d === false ? '' : (string)$d);
        } catch (Throwable $e) { error_log('session read: ' . $e->getMessage()); return ''; }
    }
    public function write(string $id, string $data): bool {
        try {
            $this->t()->prepare('INSERT INTO _portal_sessions (id, data, updated) VALUES (?, ?, ?) ON CONFLICT(id) DO UPDATE SET data = excluded.data, updated = excluded.updated')
                ->execute([$id, $data, time()]);
            return true;
        } catch (Throwable $e) { error_log('session write: ' . $e->getMessage()); return false; }
    }
    public function destroy(string $id): bool {
        try { $this->t()->prepare('DELETE FROM _portal_sessions WHERE id = ?')->execute([$id]); } catch (Throwable) {}
        return true;
    }
    public function gc(int $max_lifetime): int|false {
        try { $st = $this->t()->prepare('DELETE FROM _portal_sessions WHERE updated < ?'); $st->execute([time() - $this->life()]); return $st->rowCount(); } catch (Throwable) { return 0; }
    }
    public function validateId(string $id): bool {
        try { $st = $this->t()->prepare('SELECT 1 FROM _portal_sessions WHERE id = ?'); $st->execute([$id]); return (bool)$st->fetchColumn(); } catch (Throwable) { return false; }
    }
    public function updateTimestamp(string $id, string $data): bool { return $this->write($id, $data); }
}

/**
 * Keeps slow-changing lists (dropdown suggestions) for a few minutes per server copy when the online database is used,
 * so every page does not re-read whole tables (Turso counts every row a query reads). Without Turso it just runs $fn.
 */
function cache_remember(string $key, int $ttl, callable $fn): mixed {
    if (!turso_enabled()) return $fn();
    $f = turso_marker('cache_' . substr(sha1($key), 0, 16));
    if (is_file($f) && filemtime($f) > time() - $ttl) {
        $v = @unserialize((string)file_get_contents($f), ['allowed_classes' => false]);
        if ($v !== false || filesize($f) === 4) return $v;
    }
    $v = $fn();
    @file_put_contents($f, serialize($v), LOCK_EX);
    return $v;
}

/** The shared Turso connection (created once per request). */
function turso_pdo(): TursoPDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new TursoPDO(new TursoClient(turso_base_url(), (string)getenv('TURSO_AUTH_TOKEN')));
        register_shutdown_function(fn() => $pdo->client->close());
    }
    return $pdo;
}

// ---------------------------------------------------------------------------------------------------------------
// First start: fill an empty Turso database from the bundled starting database (with all migrations applied).
// Resumable: works in chunks of ~15 seconds; meanwhile visitors see a short "setting up" page that reloads itself.
// ---------------------------------------------------------------------------------------------------------------

function turso_marker(string $what): string {
    return rtrim(sys_get_temp_dir(), '/\\') . '/turso_' . $what . '_' . substr(sha1(turso_base_url()), 0, 10);
}

function turso_meta(TursoPDO $t, string $key): ?string {
    try {
        $v = $t->query('SELECT value FROM _portal_meta WHERE key = ' . $t->quote($key))->fetchColumn();
        return $v === false ? null : (string)$v;
    } catch (Throwable) { return null; }
}

function turso_set_meta(TursoPDO $t, string $key, string $value): void {
    $t->prepare('INSERT INTO _portal_meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$key, $value]);
}

/** Local copy of the starting database with every migration applied (built once per server copy). */
function turso_prepare_local_seed(): string {
    $path = turso_marker('seed') . '.sqlite';
    if (is_file($path) && is_file($path . '.ok')) return $path;
    @unlink($path);
    if (!copy(APP . '/portal.sqlite', $path)) throw new RuntimeException('Could not copy the starting database.');
    $local = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $GLOBALS['DB_OVERRIDE'] = $local;
    try {
        require_once APP . '/migrate.php';
        $GLOBALS['MIGRATION_RUNNING'] = true;
        ob_start();
        run_migrations(false);
        ob_end_clean();
    } finally {
        unset($GLOBALS['DB_OVERRIDE']);
        $GLOBALS['MIGRATION_RUNNING'] = false;
    }
    $local->exec('PRAGMA journal_mode = DELETE');
    $local = null;
    touch($path . '.ok');
    return $path;
}

/** Copies one SQLite database (any PDO) into another in chunks. Returns true when done, false when the time ran out. */
function turso_copy_tables(PDO $src, PDO $dst, ?callable $getPos, ?callable $setPos, float $deadline, array $skip = []): bool {
    $tables = $src->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name NOT IN ('_portal_meta', '_portal_sessions') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    [$ti, $off] = $getPos ? $getPos() : [0, 0];
    $ifNot = fn(string $sql, string $kind) => preg_replace('/^\s*CREATE\s+(UNIQUE\s+)?' . $kind . '\s+(?!IF\s+NOT\s+EXISTS)/i', 'CREATE $1' . $kind . ' IF NOT EXISTS ', $sql, 1);
    $isTurso = $dst instanceof TursoPDO;
    for (; $ti < count($tables); $ti++, $off = 0) {
        $t = $tables[$ti];
        if (in_array($t['name'], $skip, true)) continue;
        if ($off === 0) $dst->exec($ifNot((string)$t['sql'], 'TABLE'));
        $cols = array_map(fn($c) => (string)$c['name'], $src->query('PRAGMA table_info("' . str_replace('"', '""', $t['name']) . '")')->fetchAll(PDO::FETCH_ASSOC));
        $colSql = implode(',', array_map(fn($c) => '"' . str_replace('"', '""', $c) . '"', $cols));
        $per = max(1, min(100, intdiv(900, max(1, count($cols)))));    // rows per INSERT (stays under 999 values per statement)
        while (true) {
            $rows = $src->query('SELECT ' . $colSql . ' FROM "' . str_replace('"', '""', $t['name']) . '" ORDER BY rowid LIMIT ' . ($per * 4) . ' OFFSET ' . (int)$off)->fetchAll(PDO::FETCH_NUM);
            if (!$rows) break;
            $reqs = [];
            foreach (array_chunk($rows, $per) as $chunk) {
                $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
                $sql = 'INSERT OR IGNORE INTO "' . str_replace('"', '""', $t['name']) . '" (' . $colSql . ') VALUES ' . implode(',', array_fill(0, count($chunk), $ph));
                $args = array_merge(...array_map('array_values', $chunk));
                if ($isTurso) $reqs[] = ['type' => 'execute', 'stmt' => ['sql' => $sql, 'args' => array_map([TursoClient::class, 'enc'], $args), 'want_rows' => false]];
                else $dst->prepare($sql)->execute($args);
            }
            if ($reqs) $dst->client->pipeline($reqs);
            $off += count($rows);
            if ($setPos) $setPos($ti, $off);
            if (microtime(true) > $deadline) return false;
        }
        if ($setPos) $setPos($ti + 1, 0);
        if (microtime(true) > $deadline && $ti + 1 < count($tables)) return false;
    }
    // indexes and triggers after the data (so triggers do not fire on the copied rows)
    foreach ($src->query("SELECT type, sql FROM sqlite_master WHERE type IN ('index', 'trigger', 'view') AND sql IS NOT NULL ORDER BY CASE type WHEN 'index' THEN 0 WHEN 'view' THEN 1 ELSE 2 END")->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $sql = (string)$o['sql'];
        $sql = match ($o['type']) { 'index' => $ifNot($sql, 'INDEX'), 'view' => $ifNot($sql, 'VIEW'), default => $ifNot($sql, 'TRIGGER') };
        try { $dst->exec($sql); } catch (Throwable $e) { if (!str_contains(strtolower($e->getMessage()), 'already exists')) throw $e; }
    }
    return true;
}

/** Makes sure the Turso database is filled. Returns true when ready; otherwise shows the "setting up" page and stops. */
function turso_ensure_ready(TursoPDO $t): bool {
    if (is_file(turso_marker('ready'))) return true;
    $t->exec('CREATE TABLE IF NOT EXISTS _portal_meta (key TEXT PRIMARY KEY, value TEXT)');
    if (turso_meta($t, 'seed_done') === '1') { @touch(turso_marker('ready')); return true; }

    // one server copy fills the database; the others wait
    $now = time();
    $got = $t->prepare("INSERT OR IGNORE INTO _portal_meta (key, value) VALUES ('seed_lock', ?)");
    $got->execute([(string)$now]);
    $mine = $got->rowCount() === 1;
    if (!$mine) {
        $held = (int)(turso_meta($t, 'seed_lock') ?? 0);
        if ($now - $held > 60) {   // the copy that started stopped (time limit): take over
            $up = $t->prepare("UPDATE _portal_meta SET value = ? WHERE key = 'seed_lock' AND value = ?");
            $up->execute([(string)$now, (string)$held]);
            $mine = $up->rowCount() === 1;
        }
    }
    if ($mine) {
        @set_time_limit(60);
        $deadline = microtime(true) + 15;
        $seed = turso_prepare_local_seed();
        $src = new PDO('sqlite:' . $seed, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $done = turso_copy_tables($src, $t,
            fn() => [(int)(turso_meta($t, 'seed_table') ?? 0), (int)(turso_meta($t, 'seed_offset') ?? 0)],
            function (int $ti, int $off) use ($t) { turso_set_meta($t, 'seed_table', (string)$ti); turso_set_meta($t, 'seed_offset', (string)$off); turso_set_meta($t, 'seed_lock', (string)time()); },
            $deadline);
        if ($done) {
            turso_set_meta($t, 'seed_done', '1');
            $t->exec("DELETE FROM _portal_meta WHERE key IN ('seed_lock', 'seed_table', 'seed_offset')");
            @touch(turso_marker('ready'));
            return true;
        }
        turso_set_meta($t, 'seed_lock', '0');   // let the next visit (any server copy) continue right away
    }
    turso_setup_page($t);
    return false;
}

function turso_setup_page(TursoPDO $t): never {
    $ti = (int)(turso_meta($t, 'seed_table') ?? 0);
    http_response_code(503);
    header('Retry-After: 3');
    header('Cache-Control: no-store');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="3">'
       . '<title>Setting up</title><body style="font-family:system-ui,sans-serif;background:#0b1b2e;color:#e6eef8;display:grid;place-items:center;min-height:100vh;margin:0">'
       . '<div style="text-align:center;max-width:420px;padding:24px"><h1 style="font-size:22px">Setting up the permanent database…</h1>'
       . '<p style="opacity:.8">This happens once. The page refreshes by itself (step ' . ($ti + 1) . ').</p></div></body>';
    exit;
}

/** Local SQLite copy of the whole Turso database (for the backup ZIP). */
function turso_snapshot(string $path): void {
    @unlink($path);
    $dst = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $dst->exec('PRAGMA journal_mode = OFF');
    $dst->exec('BEGIN');
    turso_copy_tables(turso_pdo(), $dst, null, null, microtime(true) + 3600);
    $dst->exec('COMMIT');
}
