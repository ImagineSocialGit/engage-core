<?php

return [
    'disk' => env('DOCUMENTS_DISK', 'spaces'),
    'directory' => env('DOCUMENTS_DIRECTORY', 'documents'),
    'max_upload_kilobytes' => (int) env('DOCUMENTS_MAX_UPLOAD_KILOBYTES', 307200),
    'allowed_mime_types' => [
        'application/pdf',
        'text/plain',
        'text/csv',
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ],
];