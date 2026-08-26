<?php

namespace App\Modules\InboundMessaging\Models;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Support\Webhooks\Models\WebhookInboxReceipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InboundMessage extends Model
{
    public const CLASSIFICATION_CONSENT_REVOCATION = 'consent_revocation';
    public const CLASSIFICATION_CONSENT_GRANT = 'consent_grant';
    public const CLASSIFICATION_HELP = 'help';
    public const CLASSIFICATION_NORMAL_REPLY = 'normal_reply';
    public const CLASSIFICATION_IGNORED = 'ignored';

    public const INBOX_STATUS_NEW = 'new';
    public const INBOX_STATUS_REVIEWED = 'reviewed';
    public const INBOX_STATUS_DONE = 'done';

    protected $fillable = [
        'webhook_inbox_receipt_id',
        'sender_type',
        'sender_id',
        'related_contact_id',
        'client_key',
        'channel',
        'provider',
        'provider_event_id',
        'provider_event_key',
        'provider_message_id',
        'provider_message_key',
        'provider_context_id',
        'message_id',
        'from_type',
        'from_value',
        'to_type',
        'to_value',
        'subject',
        'body',
        'classification',
        'purpose',
        'scope',
        'correlated_scheduled_message_id',
        'reply_intent_key',
        'reply_correlation_method',
        'inbound_email_route_key',
        'inbound_email_route_source',
        'inbound_email_route_context',
        'received_at',
        'processed_at',
        'inbox_status',
        'reviewed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'webhook_inbox_receipt_id' => 'integer',
            'sender_id' => 'integer',
            'related_contact_id' => 'integer',
            'correlated_scheduled_message_id' => 'integer',
            'channel' => MessageChannel::class,
            'purpose' => MessagePurpose::class,
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public static function classifications(): array
    {
        return [
            self::CLASSIFICATION_CONSENT_REVOCATION,
            self::CLASSIFICATION_CONSENT_GRANT,
            self::CLASSIFICATION_HELP,
            self::CLASSIFICATION_NORMAL_REPLY,
            self::CLASSIFICATION_IGNORED,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function inboxStatuses(): array
    {
        return [
            self::INBOX_STATUS_NEW,
            self::INBOX_STATUS_REVIEWED,
            self::INBOX_STATUS_DONE,
        ];
    }

    public function webhookInboxReceipt(): BelongsTo
    {
        return $this->belongsTo(
            WebhookInboxReceipt::class,
            'webhook_inbox_receipt_id',
        );
    }

    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    public function relatedContact(): BelongsTo
    {
        return $this->belongsTo(
            Contact::class,
            'related_contact_id',
        );
    }

    public function correlatedScheduledMessage(): BelongsTo
    {
        return $this->belongsTo(
            ScheduledMessage::class,
            'correlated_scheduled_message_id',
        );
    }

    public function markProcessed(): void
    {
        $this->forceFill([
            'processed_at' => now(),
        ])->save();
    }
}