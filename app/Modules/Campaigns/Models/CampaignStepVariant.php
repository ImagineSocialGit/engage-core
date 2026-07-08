<?php

namespace App\Modules\Campaigns\Models;

use Database\Factories\CampaignStepVariantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignStepVariant extends Model
{
    use HasFactory;

    protected static function newFactory(): CampaignStepVariantFactory
    {
        return CampaignStepVariantFactory::new();
    }

    protected $fillable = [
        'campaign_step_id',
        'key',
        'name',
        'sort_order',
        'dispatch_key',
        'channel',
        'purpose',
        'scope',
        'is_active',
        'criteria',
        'dependency_rules',
        'source_config_path',
        'source_version',
        'is_customized',
        'customized_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'campaign_step_id' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'criteria' => 'array',
            'dependency_rules' => 'array',
            'is_customized' => 'boolean',
            'customized_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function campaignStep(): BelongsTo
    {
        return $this->belongsTo(CampaignStep::class);
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
