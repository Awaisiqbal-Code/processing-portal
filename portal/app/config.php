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

    // Sign everyone out after this many minutes without activity.
    'idle_minutes' => 30,

    // Failed sign-ins allowed per 15 minutes (per username and per IP).
    'max_attempts' => 8,

    'timezone' => 'Asia/Karachi',

    // Keep false on the live site. True shows PHP errors on screen.
    'debug' => false,
];
