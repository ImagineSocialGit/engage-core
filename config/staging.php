<?php

return [
    // Root/runtime-owned values: never set them in a selected-client .env.
    // STAGING_PASSWORD (plaintext legacy configuration) is intentionally ignored.
    'user' => env('STAGING_USER'),
    'password_hash' => env('STAGING_PASSWORD_HASH'),
];