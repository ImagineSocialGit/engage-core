<?php

namespace App\Models;

use Database\Factories\TeamMemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class TeamMember extends Model
{
    /** @use HasFactory<TeamMemberFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'phone',
        'role',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(TeamMemberNotificationPreference::class);
    }

    public function assignedTasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'assigned_to');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function canReceiveEmailNotifications(?string $notificationType = null): bool
    {
        if (! $this->active || ! $this->email) {
            return false;
        }

        return $this->notificationEnabled(
            channel: 'email',
            notificationType: $notificationType,
            default: true,
        );
    }

    public function canReceiveSmsNotifications(?string $notificationType = null): bool
    {
        if (! $this->active || ! $this->phone) {
            return false;
        }

        return $this->notificationEnabled(
            channel: 'sms',
            notificationType: $notificationType,
            default: false,
        );
    }

    private function notificationEnabled(
        string $channel,
        ?string $notificationType,
        bool $default,
    ): bool {
        if ($notificationType === null) {
            return $default;
        }

        $preference = $this->notificationPreferences
            ->first(fn (TeamMemberNotificationPreference $preference): bool => $preference->matches(
                channel: $channel,
                notificationType: $notificationType,
            ));

        return $preference?->enabled ?? $default;
    }
}