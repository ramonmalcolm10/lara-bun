<?php

return [
    'socket_path' => env('BUN_BRIDGE_SOCKET', '/tmp/bun-bridge.sock'),
    'functions_dir' => env('BUN_BRIDGE_FUNCTIONS_DIR', resource_path('bun')),
    'workers' => (int) env('BUN_WORKERS', 1),

    'ssr' => [
        'enabled' => env('BUN_SSR_ENABLED', false),
    ],

    'entry_points' => array_filter(
        explode(',', env('BUN_BRIDGE_ENTRY_POINTS', '')),
    ),
];
