<?php

use Illuminate\Support\Str;

return [
    'default' => env('CACHE_STORE', 'database'),
    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CONNECTION'),
            'table' => env('CACHE_DATABASE_TABLE', 'cache'),
            'lock_connection' => env('CACHE_DATABASE_LOCK_CONNECTION'),
            'lock_table' => env('CACHE_DATABASE_LOCK_TABLE'),
        ],
        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],
    ],
    'prefix' => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_cache_'),
];
