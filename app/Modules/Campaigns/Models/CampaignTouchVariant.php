<?php

namespace App\Modules\Campaigns\Models;

use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignTouchVariant extends Model
{
    protected $fillable = [
        'campaign_touch_date_id',
        'key',
        'name',
        'sort_order',
        'channel',
        'purpose',
        'scope',
        'message_template_preset_id',
        'is_active',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'campaign_touch_date_id' => 'integer',
            'sort_order' => 'integer',
            'message_template_preset_id' => 'integer',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function touchDate(): BelongsTo
    {
        return $this->belongsTo(
            CampaignTouchDate::class,
            'campaign_touch_date_id',
        );
    }

    public function messageTemplatePreset(): BelongsTo
    {
        return $this->belongsTo(MessageTemplatePreset::class);
    }
}