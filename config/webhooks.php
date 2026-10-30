<?php

return [
    'inbox' => [
        'claim_lease_seconds' => env('WEBHOOK_INBOX_CLAIM_LEASE_SECONDS', 300),
        'recovery' => [
            'enabled' => env('WEBHOOK_INBOX_RECOVERY_ENABLED', true),
            'batch_size' => env('WEBHOOK_INBOX_RECOVERY_BATCH_SIZE', 25),
            'minimum_age_seconds' => env('WEBHOOK_INBOX_RECOVERY_MINIMUM_AGE_SECONDS', 600),
            'retry_delay_seconds' => env('WEBHOOK_INBOX_RECOVERY_RETRY_DELAY_SECONDS', 300),
            'max_attempts' => env('WEBHOOK_INBOX_RECOVERY_MAX_ATTEMPTS', 8),
        ],
    ],
];