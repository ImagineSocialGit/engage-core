<?php

namespace App\Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookableServiceResourceRequirement extends Model
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
        'bookable_service_id',
        'scheduling_resource_id',
        'quantity',
        'is_active',
        'source',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'bookable_service_id' => 'integer',
            'scheduling_resource_id' => 'integer',
            'quantity' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function bookableService(): BelongsTo
    {
        return $this->belongsTo(BookableService::class);
    }

    public function schedulingResource(): BelongsTo
    {
        return $this->belongsTo(SchedulingResource::class);
    }
}