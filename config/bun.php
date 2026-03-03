<?php

return [
    'socket_path' => env('BUN_BRIDGE_SOCKET', '/tmp/bun-bridge.sock'),
    'functions_dir' => env('BUN_BRIDGE_FUNCTIONS_DIR', resource_path('bun')),
    'workers' => (int) env('BUN_WORKERS', 1),

    'ssr' => [
        'enabled' => env('BUN_SSR_ENABLED', false),
    ],

    'rsc' => [
        'enabled' => env('BUN_RSC_ENABLED', false),
        'bundle' => env('BUN_RSC_BUNDLE', base_path('bootstrap/rsc/entry.rsc.js')),
        'source_dir' => env('BUN_RSC_SOURCE_DIR', resource_path('js/rsc')),
        'client_build_dir' => env('BUN_RSC_CLIENT_BUILD_DIR', public_path('build/rsc')),
        'root_view' => env('BUN_RSC_ROOT_VIEW', 'lara-bun::rsc-app'),
        'callables' => [],
        'callables_dir' => null,
        'callback_timeout' => 5,
        'static_path' => env('BUN_RSC_STATIC_PATH', storage_path('framework/rsc-static')),
    ],

    'entry_points' => array_filter(
        explode(',', env('BUN_BRIDGE_ENTRY_POINTS', '')),
    ),
];
