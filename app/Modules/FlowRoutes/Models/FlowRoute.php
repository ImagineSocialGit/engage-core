<?php

namespace App\Modules\FlowRoutes\Models;

use App\Modules\Core\Models\ContactStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowRoute extends Model
{
    use HasFactory;

    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_CONTACT_STATUS = 'contact_status';
    public const TRIGGER_AUTOMATION_EVENT = 'automation_event';

    public const TRIGGERS = [
        self::TRIGGER_MANUAL,
        self::TRIGGER_CONTACT_STATUS,
        self::TRIGGER_AUTOMATION_EVENT,
    ];

    protected $fillable = [
        'key',
        'contact_status_id',
        'name',
        'description',
        'version',
        'trigger_type',
        'trigger_key',
        'is_active',
        'source_version',
        'is_customized',
        'customized_at',
        'meta',
    ];

    protected $casts = [
        'contact_status_id' => 'integer',
        'version' => 'integer',
        'is_active' => 'boolean',
        'is_customized' => 'boolean',
        'customized_at' => 'datetime',
        'meta' => 'array',
    ];

    public function contactStatus(): BelongsTo
    {
        return $this->belongsTo(ContactStatus::class);
    }

    public function flowRoutePoints(): HasMany
    {
        return $this->hasMany(FlowRoutePoint::class)->orderBy('sort_order');
    }

    public function activeFlowRoutePoints(): HasMany
    {
        return $this->flowRoutePoints()->active();
    }

    public function contactFlowRouteProgress(): HasMany
    {
        return $this->hasMany(ContactFlowRouteProgress::class);
    }

    public function activeContactFlowRouteProgress(): HasMany
    {
        return $this->contactFlowRouteProgress()->active();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    public function scopeForKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key);
    }

    public function scopeForContactStatus(Builder $query, ContactStatus|int $contactStatus): Builder
    {
        return $query->where(
            'contact_status_id',
            $contactStatus instanceof ContactStatus ? $contactStatus->getKey() : $contactStatus,
        );
    }

    public function scopeForTrigger(Builder $query, string $triggerType, ?string $triggerKey = null): Builder
    {
        $query->where('trigger_type', $triggerType);

        if ($triggerKey !== null) {
            $query->where('trigger_key', $triggerKey);
        }

        return $query;
    }

    public function scopeForAutomationEvent(Builder $query, string $eventKey): Builder
    {
        return $query->forTrigger(self::TRIGGER_AUTOMATION_EVENT, $eventKey);
    }

    public function scopeCustomized(Builder $query): Builder
    {
        return $query->where('is_customized', true);
    }

    public function scopeNotCustomized(Builder $query): Builder
    {
        return $query->where('is_customized', false);
    }
}