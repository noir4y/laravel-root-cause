<?php

return [
    'driver' => env('ROOT_CAUSE_DRIVER', 'file'),

    'storage' => [
        'path' => env('ROOT_CAUSE_STORAGE_PATH', storage_path('app/root-cause')),
    ],

    'collectors' => [
        'request' => [
            'enabled' => true,
            'auto_register_middleware' => true,
        ],
        'query' => [
            'enabled' => true,
            'duplicate_threshold' => 5,
            'n_plus_one_threshold' => 3,
        ],
    ],

    'redact' => [
        'request_keys' => ['password', 'token', 'secret', 'credit_card'],
        'headers' => ['authorization', 'cookie'],
        'sql_bindings' => true,
    ],

    'diagnostics' => [
        'top_query_examples' => 3,
        'token_budget_hint' => 'small',
    ],
];
