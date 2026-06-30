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
        'channel',
        'purpose',
        'scope',
        'is_active',
        'criteria',
        'source_version',
        'is_customized',
        'customized_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'step_number' => 'integer',
            'is_active' => 'boolean',
            'criteria' => 'array',
            'is_customized' => 'boolean',
            'customized_at' => 'datetime',
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

    public function scopeCustomized(Builder $query): Builder
    {
        return $query->where('is_customized', true);
    }

    public function scopeNotCustomized(Builder $query): Builder
    {
        return $query->where('is_customized', false);
    }
}