<?php

namespace App\Modules\Scheduling\Models;

use Database\Factories\BookableServiceHostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookableServiceHost extends Model
{
    use HasFactory;

    protected $fillable = [
        'bookable_service_id',
        'scheduling_host_id',
        'is_active',
        'capacity_override',
        'sort_order',
        'meta',
    ];

    protected static function newFactory(): BookableServiceHostFactory
    {
        return BookableServiceHostFactory::new();
    }

    protected function casts(): array
    {
        return [
            'bookable_service_id' => 'integer',
            'scheduling_host_id' => 'integer',
            'is_active' => 'boolean',
            'capacity_override' => 'integer',
            'sort_order' => 'integer',
            'meta' => 'array',
        ];
    }

    public function bookableService(): BelongsTo
    {
        return $this->belongsTo(BookableService::class);
    }

    public function schedulingHost(): BelongsTo
    {
        return $this->belongsTo(SchedulingHost::class);
    }
}