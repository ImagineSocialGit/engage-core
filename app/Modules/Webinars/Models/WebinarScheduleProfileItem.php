<?php

namespace App\Modules\Webinars\Models;

use Database\Factories\WebinarScheduleProfileItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebinarScheduleProfileItem extends Model
{
    use HasFactory;

    protected static function newFactory(): WebinarScheduleProfileItemFactory
    {
        return WebinarScheduleProfileItemFactory::new();
    }

    protected $fillable = [
        'webinar_schedule_profile_id',
        'key',
        'label',
        'context_key',
        'channel',
        'purpose',
        'scope',
        'surface',
        'message_type',
        'dispatch_key',
        'message_template_key',
        'source_config_path',
        'is_enabled',
        'is_active',
        'is_customized',
        'customized_at',
        'sort_order',
        'timing',
        'schedule',
        'conditions',
        'meta',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_active' => 'boolean',
        'is_customized' => 'boolean',
        'customized_at' => 'datetime',
        'sort_order' => 'integer',
        'schedule' => 'array',
        'conditions' => 'array',
        'meta' => 'array',
    ];

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

    public function webinarScheduleProfile(): BelongsTo
    {
        return $this->belongsTo(WebinarScheduleProfile::class);
    }
}
