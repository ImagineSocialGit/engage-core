<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ScheduledMessageOperationalEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'scheduled_message_id',
        'actor_id',
        'actor_email',
        'action',
        'reason',
        'previous_send_at',
        'current_send_at',
        'previous_operational_state',
        'current_operational_state',
        'occurred_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Scheduled message operational history is immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Scheduled message operational history is immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'scheduled_message_id' => 'integer',
            'actor_id' => 'integer',
            'previous_send_at' => 'datetime',
            'current_send_at' => 'datetime',
            'occurred_at' => 'datetime',
        ];
    }

    public function scheduledMessage(): BelongsTo
    {
        return $this->belongsTo(ScheduledMessage::class);
    }
}