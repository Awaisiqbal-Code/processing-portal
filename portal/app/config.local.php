<?php
// Local, Railway & Vercel SQLite configuration
$dbFile = getenv('PORTAL_SQLITE') ?: (__DIR__ . '/portal.sqlite');
if (!getenv('PORTAL_SQLITE') && (getenv('VERCEL') || !is_writable(__DIR__) || !is_writable($dbFile))) {
    $tmpDb = rtrim(sys_get_temp_dir(), '/\\') . '/portal_v265.sqlite';
    if (!is_file($tmpDb) || filesize($tmpDb) < 100000) {
        @copy(__DIR__ . '/portal.sqlite', $tmpDb);
        @chmod($tmpDb, 0666);
    }
    $dbFile = $tmpDb;
}

return [
    'dsn' => 'sqlite:' . $dbFile,
    'debug' => (getenv('RAILWAY_ENVIRONMENT') || getenv('VERCEL')) ? false : true,
];
