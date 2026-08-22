<?php

namespace App\Modules\Campaigns\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignTouchDate extends Model
{
    public const SOURCE_CONTACT_FIELD = 'contact_field';
    public const SOURCE_FIXED_DATE = 'fixed_date';
    public const SOURCE_REGISTERED_DATE = 'registered_date_source';

    protected $fillable = [
        'campaign_touch_program_id',
        'key',
        'name',
        'source_type',
        'source_key',
        'month',
        'day',
        'offset_days',
        'send_time',
        'sort_order',
        'is_active',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'campaign_touch_program_id' => 'integer',
            'month' => 'integer',
            'day' => 'integer',
            'offset_days' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(
            CampaignTouchProgram::class,
            'campaign_touch_program_id',
        );
    }

    public function variants(): HasMany
    {
        return $this->hasMany(CampaignTouchVariant::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}