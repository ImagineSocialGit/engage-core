<?php

namespace App\Modules\Messaging\Models;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentRevocation extends Model
{
    public const REASON_STOP = 'stop';
    public const REASON_UNSUBSCRIBE = 'unsubscribe';
    public const REASON_OPT_OUT = 'opt_out';
    public const REASON_PREFERENCE_UPDATE = 'preference_update';
    public const REASON_MANUAL_REQUEST = 'manual_request';
    public const REASON_PROVIDER_UNSUBSCRIBE = 'provider_unsubscribe';

    protected $fillable = [
        'contact_id',
        'message_consent_id',
        'channel',
        'purpose',
        'scope',
        'reason',
        'revoked_at',
        'source',
        'ip_address',
        'user_agent',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'purpose' => MessagePurpose::class,
            'contact_id' => 'integer',
            'message_consent_id' => 'integer',
            'revoked_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public static function reasons(): array
    {
        return [
            self::REASON_STOP,
            self::REASON_UNSUBSCRIBE,
            self::REASON_OPT_OUT,
            self::REASON_PREFERENCE_UPDATE,
            self::REASON_MANUAL_REQUEST,
            self::REASON_PROVIDER_UNSUBSCRIBE,
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function messageConsent(): BelongsTo
    {
        return $this->belongsTo(MessageConsent::class);
    }
}