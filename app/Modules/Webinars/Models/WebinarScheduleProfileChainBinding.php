<?php

namespace App\Modules\Webinars\Models;

use App\Modules\Messaging\Models\MessageChain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebinarScheduleProfileChainBinding extends Model
{
    protected $fillable = [
        'webinar_schedule_profile_id',
        'key',
        'message_area_key',
        'message_chain_id',
        'dispatch_key',
        'surface',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'webinar_schedule_profile_id' => 'integer',
            'message_chain_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function webinarScheduleProfile(): BelongsTo
    {
        return $this->belongsTo(WebinarScheduleProfile::class);
    }

    public function messageChain(): BelongsTo
    {
        return $this->belongsTo(MessageChain::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}