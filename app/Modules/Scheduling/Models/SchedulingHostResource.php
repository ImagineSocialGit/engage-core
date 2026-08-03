<?php

namespace App\Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchedulingHostResource extends Model
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_PROVIDER = 'provider';

    protected $attributes = [
        'is_active' => true,
        'source' => self::SOURCE_MANUAL,
        'sort_order' => 0,
    ];

    protected $fillable = [
        'scheduling_host_id',
        'scheduling_resource_id',
        'capacity',
        'is_active',
        'source',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'scheduling_host_id' => 'integer',
            'scheduling_resource_id' => 'integer',
            'capacity' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function schedulingHost(): BelongsTo
    {
        return $this->belongsTo(SchedulingHost::class);
    }

    public function schedulingResource(): BelongsTo
    {
        return $this->belongsTo(SchedulingResource::class);
    }
}