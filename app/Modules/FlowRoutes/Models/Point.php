<?php

namespace App\Modules\FlowRoutes\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Point extends Model
{
    use HasFactory;

    public const TYPE_WAIT = 'wait';
    public const TYPE_CONDITION = 'condition';
    public const TYPE_CHANGE_STATUS = 'change_status';
    public const TYPE_CREATE_TASK = 'create_task';
    public const TYPE_SEND_MESSAGE = 'send_message';
    public const TYPE_ENROLL_CAMPAIGN = 'enroll_campaign';
    public const TYPE_CANCEL_CAMPAIGN = 'cancel_campaign';
    public const TYPE_WEBHOOK_ACTION = 'webhook_action';
    public const TYPE_BRANCH_EVALUATE = 'branch_evaluate';

    public const TYPES = [
        self::TYPE_WAIT,
        self::TYPE_CONDITION,
        self::TYPE_CHANGE_STATUS,
        self::TYPE_CREATE_TASK,
        self::TYPE_SEND_MESSAGE,
        self::TYPE_ENROLL_CAMPAIGN,
        self::TYPE_CANCEL_CAMPAIGN,
        self::TYPE_WEBHOOK_ACTION,
        self::TYPE_BRANCH_EVALUATE,
    ];

    protected $fillable = [
        'key',
        'type',
        'name',
        'description',
        'default_definition',
        'default_settings',
        'is_active',
        'preset_key',
        'source_version',
        'is_customized',
        'customized_at',
        'meta',
    ];

    protected $casts = [
        'default_definition' => 'array',
        'default_settings' => 'array',
        'is_active' => 'boolean',
        'is_customized' => 'boolean',
        'customized_at' => 'datetime',
        'meta' => 'array',
    ];

    public function flowRoutePoints(): HasMany
    {
        return $this->hasMany(FlowRoutePoint::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    public function scopeType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
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

    public function isType(string $type): bool
    {
        return $this->type === $type;
    }
}