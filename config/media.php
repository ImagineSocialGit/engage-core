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
];