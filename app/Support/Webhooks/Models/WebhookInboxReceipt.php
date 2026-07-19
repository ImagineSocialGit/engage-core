<?php

namespace App\Support\Webhooks\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookInboxReceipt extends Model
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_RETRYABLE_FAILED = 'retryable_failed';

    protected $fillable = [
        'client_key',
        'provider',
        'provider_event_id',
        'signature_fingerprint',
        'receipt_key',
        'event_type',
        'payload_fingerprint',
        'payload',
        'status',
        'attempts',
        'claim_token',
        'claim_expires_at',
        'last_attempted_at',
        'completed_at',
        'failed_at',
        'outcome',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'claim_expires_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'outcome' => 'array',
        ];
    }
}
