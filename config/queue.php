<?php

return [
    'default' => env('QUEUE_CONNECTION', 'database'),
    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CONNECTION'),
            'table' => env('QUEUE_DATABASE_TABLE', 'jobs'),
            'queue' => env('QUEUE_DATABASE_QUEUE', 'default'),
            'retry_after' => (int) env('QUEUE_DATABASE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],
    ],
    'batching' => [
        'database' => env('DB_CONNECTION'),
        'table' => 'job_batches',
    ],
    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION'),
        'table' => 'failed_jobs',
    ],
];
