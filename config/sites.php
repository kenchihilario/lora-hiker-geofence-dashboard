<?php
// Keep real coordinates only when supplied/surveyed. Null means the operator must configure it.
return [
    'pup' => [
        'id' => 'pup', 'display_name' => 'PUP Sta. Mesa',
        'center' => null, 'zoom' => 16, 'center_status' => 'PLACEHOLDER — confirm campus center',
        'reference_points' => [[
            'code' => 'PUP_OVAL_PIN', 'label' => 'PUP Oval Google Maps pin (user-provided, unverified)', 'point_type' => 'OTHER',
            'latitude' => 14.598126, 'longitude' => 121.0120373, 'unsurveyed' => true,
        ]],
        'network_nodes' => ['BASE' => null, 'CHK' => null],
    ],
    'pup_mountain_prep' => [
        'id' => 'pup_mountain_prep', 'display_name' => 'PUP Mountain Prep (Demo)',
        'center' => null, 'zoom' => 16,
        'center_status' => 'UNSCORED REHEARSAL — PUP coordinates are not surveyed; configure a center or import a GPX track.',
        'terrain_profile' => 'mountain',
        'is_demo' => true, 'is_rehearsal' => true,
        'reference_points' => [],
        'network_nodes' => ['BASE' => null, 'CHK' => null],
    ],
    'balagbag' => [
        'id' => 'balagbag', 'display_name' => 'Mt. Balagbag',
        'center' => ['latitude' => 14.8243087, 'longitude' => 121.1804131], 'zoom' => 16,
        'center_status' => 'Peak reference supplied by operator',
        'reference_points' => [[
            'code' => 'PEAK', 'label' => 'Peak · 770 m', 'point_type' => 'PEAK',
            'latitude' => 14.8243087, 'longitude' => 121.1804131,
        ],[
            'code' => 'USER_PIN', 'label' => 'Google Maps place pin (user-provided, unsurveyed)', 'point_type' => 'OTHER',
            'latitude' => 14.8239931, 'longitude' => 121.1795801, 'unsurveyed' => true,
        ]],
        'network_nodes' => ['BASE' => null, 'CHK' => null],
    ],
    'mapalad_backup' => [
        'id' => 'mapalad_backup', 'display_name' => 'Mt. Mapalad (backup)',
        'center' => null, 'zoom' => 16,
        'center_status' => 'BACKUP SITE — coordinates and route are not configured.',
        'reference_points' => [],
        'network_nodes' => ['BASE' => null, 'CHK' => null],
    ],
];
