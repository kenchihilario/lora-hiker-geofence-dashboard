<?php
// Copy to config.php. Keep passwords and tokens local; config.php is git-ignored.
return [
    'db_profiles' => [
        'demo' => ['db_host'=>'127.0.0.1','db_name'=>'hiker_dashboard_test_codex','db_user'=>'root','db_pass'=>''],
        'live' => ['db_host'=>'127.0.0.1','db_name'=>'hiker_dashboard_live','db_user'=>'root','db_pass'=>''],
    ],
    // Use independent secrets. The LIVE bridge must present the LIVE token in X-Bridge-Token.
    'bridge_tokens' => ['demo'=>'replace-with-private-demo-token','live'=>'replace-with-different-private-live-token'],
    'ingest_token' => 'replace-with-private-legacy-token',
    'local_only' => true,
];
