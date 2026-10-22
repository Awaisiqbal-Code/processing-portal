<?php
// ------------------------------------------------------------------
// Processing Team Portal — settings
// Fill in the database details from cPanel > MySQL Databases.
// ------------------------------------------------------------------

return [
    'db_host' => 'localhost',
    'db_name' => 'CHANGE_ME_database_name',
    'db_user' => 'CHANGE_ME_database_user',
    'db_pass' => 'CHANGE_ME_database_password',

    // Name shown in the header of every portal.
    'app_name' => 'Processing Team Portal',

    // Leave empty to detect automatically. If the portal lives in a
    // sub-folder and links break, set it, e.g. '/portal'.
    'base_url' => '',

    // Sign staff out after this many minutes without activity.
    'idle_warning_minutes' => (int)(getenv('IDLE_WARNING_MINUTES') ?: 15),
    'idle_logout_minutes'  => (int)(getenv('IDLE_LOGOUT_MINUTES') ?: 20),
    'idle_minutes'         => (int)(getenv('IDLE_LOGOUT_MINUTES') ?: 20),

    // Pending logic thresholds (in hours)
    'pending_grace_hours'    => (int)(getenv('PENDING_GRACE_HOURS') !== false ? getenv('PENDING_GRACE_HOURS') : 0),
    'pending_reminder_hours' => (int)(getenv('PENDING_REMINDER_HOURS') ?: 48),

    // Secret header token for cron endpoint (X-Cron-Secret)
    'cron_secret' => getenv('CRON_SECRET') ?: 'ptp_cron_secret_2026_x9a8b7',

    // Failed sign-ins allowed per 15 minutes (per username and per IP).
    'max_attempts' => 8,

    'timezone' => 'Asia/Karachi',

    // Keep false on the live site. True shows PHP errors on screen.
    'debug' => false,
];
