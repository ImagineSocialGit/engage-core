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

    protected $fillable = [
        'contact_status_id',
        'name',
        'version',
        'is_active',
        'preset_key',
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

    public function scopeForContactStatus(Builder $query, ContactStatus|int $contactStatus): Builder
    {
        return $query->where(
            'contact_status_id',
            $contactStatus instanceof ContactStatus ? $contactStatus->getKey() : $contactStatus,
        );
    }

    public function scopePreset(Builder $query, string $presetKey): Builder
    {
        return $query->where('preset_key', $presetKey);
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