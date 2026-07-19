<?php

namespace App\Modules\InboundMessaging\Models;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InboundMessage extends Model
{
    public const CLASSIFICATION_CONSENT_REVOCATION = 'consent_revocation';
    public const CLASSIFICATION_HELP = 'help';
    public const CLASSIFICATION_NORMAL_REPLY = 'normal_reply';
    public const CLASSIFICATION_IGNORED = 'ignored';

    protected $fillable = [
        'sender_type',
        'sender_id',
        'client_key',
        'channel',
        'provider',
        'provider_event_id',
        'provider_message_id',
        'provider_context_id',
        'from_type',
        'from_value',
        'to_type',
        'to_value',
        'body',
        'classification',
        'purpose',
        'scope',
        'received_at',
        'processed_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'sender_id' => 'integer',
            'channel' => MessageChannel::class,
            'purpose' => MessagePurpose::class,
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public static function classifications(): array
    {
        return [
            self::CLASSIFICATION_CONSENT_REVOCATION,
            self::CLASSIFICATION_HELP,
            self::CLASSIFICATION_NORMAL_REPLY,
            self::CLASSIFICATION_IGNORED,
        ];
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(InboundMessageReceipt::class);
    }

    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    public function markProcessed(): void
    {
        $this->forceFill([
            'processed_at' => now(),
        ])->save();
    }
}