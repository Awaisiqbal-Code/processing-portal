<?php
// Local, Railway & Vercel SQLite configuration
$dbFile = getenv('PORTAL_SQLITE') ?: (__DIR__ . '/portal.sqlite');
if (!getenv('PORTAL_SQLITE') && !getenv('TURSO_DATABASE_URL') && (getenv('VERCEL') || !is_writable(__DIR__) || !is_writable($dbFile))) {
    $tmpDb = rtrim(sys_get_temp_dir(), '/\\') . '/portal_v397.sqlite';
    $srcDb = __DIR__ . '/portal.sqlite';
    if (!is_file($tmpDb) || filesize($tmpDb) < 500000 || (is_file($srcDb) && filemtime($srcDb) > @filemtime($tmpDb))) {
        @copy($srcDb, $tmpDb);
        @chmod($tmpDb, 0666);
    }
    $dbFile = $tmpDb;
}

return [
    'dsn' => 'sqlite:' . $dbFile,
    'debug' => (getenv('RAILWAY_ENVIRONMENT') || getenv('VERCEL')) ? false : true,
];
