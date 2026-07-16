<?php

namespace App\Modules\Messaging\Models;

use Database\Factories\ScheduledMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ScheduledMessage extends Model
{
    use HasFactory;

    protected static function newFactory(): ScheduledMessageFactory
    {
        return ScheduledMessageFactory::new();
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'context_type',
        'context_id',
        'behavior_owner_type',
        'behavior_owner_id',
        'channel',
        'message_type',
        'purpose',
        'scope',
        'payload_class',
        'queue',
        'dispatch_keys',
        'definition_config_path',
        'payload',
        'send_at',
        'status',
        'sending_at',
        'last_attempted_at',
        'send_attempts',
        'provider',
        'provider_message_id',
        'sent_at',
        'skipped_at',
        'failed_at',
        'dedupe_key',
        'failure_reason',
        'skip_reason',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'recipient_id' => 'integer',
            'context_id' => 'integer',
            'behavior_owner_id' => 'integer',
            'dispatch_keys' => 'array',
            'payload' => 'array',
            'send_at' => 'datetime',
            'sending_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'send_attempts' => 'integer',
            'sent_at' => 'datetime',
            'skipped_at' => 'datetime',
            'failed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    public function context(): MorphTo
    {
        return $this->morphTo();
    }

    public function behaviorOwner(): MorphTo
    {
        return $this->morphTo('behavior_owner');
    }

    /**
     * @return array<int, string>
     */
    public function dispatchKeys(): array
    {
        return array_values(array_filter(
            $this->dispatch_keys ?? [],
            fn (mixed $dispatchKey): bool => is_string($dispatchKey) && trim($dispatchKey) !== '',
        ));
    }
}
