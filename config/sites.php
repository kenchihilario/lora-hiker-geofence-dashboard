<?php
// Keep real coordinates only when supplied/surveyed. Null means the operator must configure it.
return [
    'pup' => [
        'id' => 'pup', 'display_name' => 'PUP Sta. Mesa',
        'center' => null, 'zoom' => 16, 'center_status' => 'PLACEHOLDER — confirm campus center',
        'reference_points' => [],
        'network_nodes' => ['BASE' => null, 'CHK' => null],
    ],
    'pup_mountain_prep' => [
        'id' => 'pup_mountain_prep', 'display_name' => 'PUP Mountain Prep (Demo)',
        'center' => null, 'zoom' => 16,
        'center_status' => 'Mountain-prep test profile — PUP coordinates are not surveyed; configure a center or import a GPX track.',
        'terrain_profile' => 'mountain',
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
        ]],
        'network_nodes' => ['BASE' => null, 'CHK' => null],
    ],
];
