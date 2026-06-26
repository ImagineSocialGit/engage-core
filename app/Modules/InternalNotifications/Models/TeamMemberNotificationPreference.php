<?php

namespace App\Modules\InternalNotifications\Models;

use Database\Factories\TeamMemberNotificationPreferenceFactory;
use App\Modules\Messaging\Enums\MessageChannel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMemberNotificationPreference extends Model
{
    use HasFactory;

    protected static function newFactory(): TeamMemberNotificationPreferenceFactory
    {
        return TeamMemberNotificationPreferenceFactory::new();
    }

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';

    public const TYPE_INBOUND_REPLIES = 'inbound_replies';
    public const TYPE_TASK_ASSIGNED = 'task_assigned';
    public const TYPE_TASK_DUE = 'task_due';
    public const TYPE_DAILY_DIGEST = 'daily_digest';
    public const TYPE_WEEKLY_DIGEST = 'weekly_digest';

    protected $fillable = [
        'team_member_id',
        'channel',
        'purpose',
        'scope',
        'is_enabled',
        'meta',
    ];

    protected $casts = [
        'team_member_id' => 'integer',
        'is_enabled' => 'boolean',
        'meta' => 'array',
    ];

    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class);
    }

    public function matches(MessageChannel|string $channel, ?string $notificationType = null): bool
    {
        $channel = $channel instanceof MessageChannel
            ? $channel->value
            : strtolower(trim($channel));

        if ($this->channel !== $channel) {
            return false;
        }

        if ($notificationType === null || trim($notificationType) === '') {
            return true;
        }

        return $this->purpose === $notificationType;
    }
}