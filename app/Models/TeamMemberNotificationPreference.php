<?php

namespace App\Models;

use Database\Factories\TeamMemberNotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMemberNotificationPreference extends Model
{
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';

    public const TYPE_INBOUND_REPLIES = 'inbound_replies';
    public const TYPE_TASK_ASSIGNED = 'task_assigned';
    public const TYPE_TASK_DUE = 'task_due';
    public const TYPE_DAILY_DIGEST = 'daily_digest';
    public const TYPE_WEEKLY_DIGEST = 'weekly_digest';

    /** @use HasFactory<TeamMemberNotificationPreferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'team_member_id',
        'channel',
        'notification_type',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'team_member_id' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class);
    }

    public function matches(string $channel, string $notificationType): bool
    {
        return $this->channel === $channel
            && $this->notification_type === $notificationType;
    }
}