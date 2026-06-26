<?php

namespace App\Modules\Campaigns\Models;

use App\Modules\Messaging\Models\ScheduledMessage;
use Database\Factories\BroadcastFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Broadcast extends Model
{
    use HasFactory;

    protected static function newFactory(): BroadcastFactory
    {
        return BroadcastFactory::new();
    }

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING = 'sending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'name',
        'channel',
        'purpose',
        'scope',
        'status',
        'send_at',
        'payload',
        'audience',
        'recipient_count',
        'scheduled_count',
        'cancelled_at',
        'completed_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'send_at' => 'datetime',
            'payload' => 'array',
            'audience' => 'array',
            'recipient_count' => 'integer',
            'scheduled_count' => 'integer',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    public function scheduledMessages(): MorphMany
    {
        return $this->morphMany(ScheduledMessage::class, 'context');
    }
}