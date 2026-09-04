<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Reusable Media Library
    |--------------------------------------------------------------------------
    |
    | Media stores reusable public assets through Laravel Filesystem. The disk
    | defaults to the process-wide filesystem selection; live deployments that
    | enable Media currently require the configured DigitalOcean Spaces disk.
    |
    */

    'disk' => null,

    'directory' => 'media',

    'max_upload_kilobytes' => 262144,

    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'video/mp4',
        'video/quicktime',
        'video/webm',
        'audio/mpeg',
        'audio/mp4',
        'audio/wav',
        'audio/x-wav',
        'audio/ogg',
        'application/pdf',
        'application/zip',
        'text/plain',
    ],

    /*
    |--------------------------------------------------------------------------
    | Advisory Image Similarity
    |--------------------------------------------------------------------------
    |
    | SHA-256 remains the only hard duplicate identity. This second layer uses
    | a compact image fingerprint only to suggest existing assets before a new
    | image is stored. Operators may always choose to upload a legitimate visual
    | variant. Values are product policy, not deployment environment secrets.
    |
    */

    'near_duplicate_images' => [
        'enabled' => true,
        'max_hamming_distance' => 8,
        'aspect_ratio_tolerance' => 0.08,
        'max_candidates' => 3,
    ],
];