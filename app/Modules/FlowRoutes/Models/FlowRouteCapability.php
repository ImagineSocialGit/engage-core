<?php

namespace App\Modules\FlowRoutes\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowRouteCapability extends Model
{
    use HasFactory;

    public const TYPE_ACTION = 'action';
    public const TYPE_EVENT = 'event';
    public const TYPE_CONDITION = 'condition';
    public const TYPE_WAIT = 'wait';

    public const TYPES = [
        self::TYPE_ACTION,
        self::TYPE_EVENT,
        self::TYPE_CONDITION,
        self::TYPE_WAIT,
    ];

    public const VISIBILITY_CLIENT = 'client';
    public const VISIBILITY_OPERATOR = 'operator';
    public const VISIBILITY_DEVELOPER = 'developer';

    protected $fillable = [
        'key',
        'module_key',
        'capability_type',
        'point_type',
        'handler_key',
        'event_key',
        'action_key',
        'name',
        'description',
        'category',
        'surface',
        'supported_subjects',
        'required_modules',
        'input_schema',
        'output_schema',
        'available_fields',
        'defaults',
        'is_active',
        'source',
        'source_version',
        'is_customized',
        'customized_at',
        'meta',
    ];

    protected $casts = [
        'supported_subjects' => 'array',
        'required_modules' => 'array',
        'input_schema' => 'array',
        'output_schema' => 'array',
        'available_fields' => 'array',
        'defaults' => 'array',
        'is_active' => 'boolean',
        'is_customized' => 'boolean',
        'customized_at' => 'datetime',
        'meta' => 'array',
    ];

    public function bindings(): HasMany
    {
        return $this->hasMany(FlowRouteCapabilityBinding::class);
    }

    public function enabledBindings(): HasMany
    {
        return $this->bindings()->enabled();
    }

    public function flowRoutePoints(): HasMany
    {
        return $this->hasMany(FlowRoutePoint::class);
    }

    public function planItems(): HasMany
    {
        return $this->hasMany(ContactFlowRoutePlanItem::class);
    }

    public function progressItems(): HasMany
    {
        return $this->hasMany(ContactFlowRouteProgressItem::class);
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

    public function scopeForModule(Builder $query, string $moduleKey): Builder
    {
        return $query->where('module_key', $moduleKey);
    }

    public function scopeForPointType(Builder $query, string $pointType): Builder
    {
        return $query->where('point_type', $pointType);
    }

    public function scopeForCapabilityType(Builder $query, string $capabilityType): Builder
    {
        return $query->where('capability_type', $capabilityType);
    }

    public function scopeForEventKey(Builder $query, string $eventKey): Builder
    {
        return $query->where('event_key', $eventKey);
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
