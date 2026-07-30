<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledMessageComponent extends Model
{
    public const ROLE_CONSENT_ACKNOWLEDGEMENT = 'consent_acknowledgement';

    protected $fillable = [
        'scheduled_message_id',
        'message_template_version_id',
        'role',
        'intent_key',
        'message_consent_id',
        'sort_order',
        'placement_key',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_message_id' => 'integer',
            'message_template_version_id' => 'integer',
            'message_consent_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function scheduledMessage(): BelongsTo
    {
        return $this->belongsTo(ScheduledMessage::class);
    }

    public function messageTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(MessageTemplateVersion::class);
    }

    public function messageConsent(): BelongsTo
    {
        return $this->belongsTo(MessageConsent::class);
    }
}