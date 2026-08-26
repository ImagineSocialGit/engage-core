<?php

namespace App\Modules\Campaigns\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignTouchProgram extends Model
{
    public const AUDIENCE_CONTACT_STATUS = 'contact_status';
    public const RECURRENCE_ANNUAL = 'annual';
    public const MESSAGE_PURPOSE = 'marketing';
    public const MESSAGE_SCOPE = 'annual_touch';

    protected $fillable = [
        'key',
        'name',
        'audience_type',
        'audience_key',
        'recurrence',
        'repeat_years',
        'starts_on',
        'is_active',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'repeat_years' => 'integer',
            'starts_on' => 'date',
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function touchDates(): HasMany
    {
        return $this->hasMany(CampaignTouchDate::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}