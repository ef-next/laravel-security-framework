<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Service name
    |--------------------------------------------------------------------------
    |
    | First segment of every Global Object Id produced by this application
    | ("<service>:<resource-type>:<public-id>"). Lowercase letters, digits and
    | hyphens only. Must be unique across your services.
    |
    */

    'service_name' => env('SECURITY_SERVICE_NAME', 'app'),

    'public_ids' => [

        // Default column for models using HasPublicId. Override per model via publicIdColumn().
        'column' => env('SECURITY_PUBLIC_ID_COLUMN', 'public_id'),

        // "base32" (Crockford alphabet, 16 chars for 10 bytes) or "hex" (2 chars per byte).
        'encoding' => env('SECURITY_PUBLIC_ID_ENCODING', 'base32'),

        // Random bytes per id. Minimum 8 (64 bit); default 10 (80 bit).
        'entropy_bytes' => (int) env('SECURITY_PUBLIC_ID_ENTROPY_BYTES', 10),

        // Retries when the generated id already exists. Only meaningful with a UNIQUE index.
        'max_attempts' => (int) env('SECURITY_PUBLIC_ID_MAX_ATTEMPTS', 5),
    ],

];
