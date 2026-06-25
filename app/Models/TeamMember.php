<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'phone',
        'role',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(TeamMemberNotificationPreference::class);
    }

    public function assignedWorkflowProfiles(): MorphMany
    {
        return $this->morphMany(ContactWorkflowProfile::class, 'assigned_to');
    }

    public function assignedTasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'assigned_to');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function canReceiveEmailNotifications(?string $notificationType = null): bool
    {
        return app(\App\Services\Messaging\InternalNotificationGate::class)->allows(
            teamMember: $this,
            channel: 'email',
            notificationType: $notificationType,
        );
    }

    public function canReceiveSmsNotifications(?string $notificationType = null): bool
    {
        return app(\App\Services\Messaging\InternalNotificationGate::class)->allows(
            teamMember: $this,
            channel: 'sms',
            notificationType: $notificationType,
        );
    }
}