<?php

/**
 * -------------------------------------------
 * Fleetbase Core API Configuration
 * -------------------------------------------
 */
return [
    'api' => [
        'version' => '0.0.1',
        'routing' => [
            'prefix' => null,
            'internal_prefix' => 'int'
        ],
        'db' => env('STOREFRONT_DB_CONNECTION', null)
    ]
];
