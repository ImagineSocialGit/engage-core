<?php

namespace App\Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookableServicePrerequisite extends Model
{
    protected $attributes = [
        'required_completions' => 1,
        'is_active' => true,
        'sort_order' => 0,
        'source' => 'manual',
    ];

    protected $fillable = [
        'bookable_service_id',
        'prerequisite_bookable_service_id',
        'required_completions',
        'valid_for_days',
        'is_active',
        'sort_order',
        'source',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'bookable_service_id' => 'integer',
            'prerequisite_bookable_service_id' => 'integer',
            'required_completions' => 'integer',
            'valid_for_days' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'meta' => 'array',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BookableService::class, 'bookable_service_id')
            ->withTrashed();
    }

    public function prerequisiteService(): BelongsTo
    {
        return $this->belongsTo(
            BookableService::class,
            'prerequisite_bookable_service_id',
        )->withTrashed();
    }
}