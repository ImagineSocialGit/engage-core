<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ScheduledMessageEdit extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'scheduled_message_id',
        'message_template_version_id',
        'actor_id',
        'actor_email',
        'override_payload',
        'action',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_message_id' => 'integer',
            'message_template_version_id' => 'integer',
            'actor_id' => 'integer',
            'override_payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Scheduled message edits are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Scheduled message edits are immutable.');
        });
    }

    public function scheduledMessage(): BelongsTo
    {
        return $this->belongsTo(ScheduledMessage::class);
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(MessageTemplateVersion::class, 'message_template_version_id');
    }
}