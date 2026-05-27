<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mode de connexion HFSQL
    |--------------------------------------------------------------------------
    |
    | 'rest' : appel HTTP vers l'agent hfsql-agent.php déployé sur la machine
    |          Windows qui héberge HFSQL.
    | 'odbc' : connexion directe via PDO ODBC (driver HFSQL PC SOFT requis).
    |
    */
    'mode' => env('HFSQL_MODE', 'rest'),

    'rest' => [
        'url'     => env('HFSQL_API_URL'),
        'api_key' => env('HFSQL_API_KEY'),
        'timeout' => (int) env('HFSQL_HTTP_TIMEOUT', 120),
    ],

    'odbc' => [
        'driver'   => env('HFSQL_DRIVER', 'HFSQL'),
        'host'     => env('HFSQL_HOST', 'localhost'),
        'port'     => env('HFSQL_PORT', '4900'),
        'database' => env('HFSQL_DATABASE'),
        'username' => env('HFSQL_USERNAME'),
        'password' => env('HFSQL_PASSWORD'),
        'dsn'      => env('HFSQL_DSN'),
    ],

    'sync' => [
        'batch_size'   => (int) env('HFSQL_SYNC_BATCH', 100),
        'cron'         => env('HFSQL_SYNC_CRON', '*/15 * * * *'),
        // Liste explicite des tables synchronisées par défaut.
        // Voir config/hfsql-tables.php pour l'édition.
        'tables'       => require __DIR__ . '/hfsql-tables.php',
    ],
];
