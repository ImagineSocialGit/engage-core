<?php

namespace App\Modules\Campaigns\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignStep extends Model
{
    protected $fillable = [
        'campaign_id',
        'step_number',
        'name',
        'dispatch_key',
        'is_active',
        'criteria',
        'payload',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'step_number' => 'integer',
            'is_active' => 'boolean',
            'criteria' => 'array',
            'payload' => 'array',
            'meta' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}