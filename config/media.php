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
    | Progressive Image Derivatives
    |--------------------------------------------------------------------------
    |
    | Original Media objects remain authoritative. Supported still images also
    | receive deterministic WebP children for fast browser presentation. These
    | files are derived/regenerable state and never participate in SHA-256 or
    | perceptual duplicate identity. Animated GIFs remain original-only.
    |
    */

    'image_variants' => [
        'enabled' => true,
        'medium_width' => 500,
        'default_max_width' => 1920,
        'webp_quality' => 82,
        'max_source_pixels' => 40000000,
        'supported_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
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