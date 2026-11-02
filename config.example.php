<?php
// Copy to config.php and edit. config.php is git-ignored.
return [
    'db_host' => '127.0.0.1',
    'db_name' => 'hiker_dashboard',
    'db_user' => 'root',
    'db_pass' => '',
    // Shared secret for local bridge API routes. Replace before enabling a bridge.
    'bridge_token' => 'change-this-local-token',
    // Legacy ingest endpoint token retained for the earlier prototype workflow.
    'ingest_token' => 'change-me',
];
