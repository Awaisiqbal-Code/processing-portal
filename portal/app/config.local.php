<?php
// Local & Railway SQLite configuration
// PORTAL_SQLITE (optional env var) lets Railway or a test server specify the SQLite path.
return [
    'dsn' => 'sqlite:' . (getenv('PORTAL_SQLITE') ?: __DIR__ . '/portal.sqlite'),
    'debug' => getenv('RAILWAY_ENVIRONMENT') ? false : true,
];

