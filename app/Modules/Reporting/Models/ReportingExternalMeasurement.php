<?php

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

class ReportingExternalMeasurement extends Model
{
    public const IDENTITY_STABLE_IDS = 'stable_ids';
    public const IDENTITY_NAME_FALLBACK = 'name_fallback';

    protected $fillable = [
        'period_start',
        'period_end',
        'platform',
        'account_id',
        'account_timezone',
        'campaign_id',
        'group_id',
        'creative_id',
        'campaign_name',
        'group_name',
        'creative_name',
        'placement',
        'identity_quality',
        'currency',
        'impressions',
        'reach',
        'link_clicks',
        'outbound_clicks',
        'landing_page_views',
        'spend',
        'result_type',
        'results',
        'source',
        'source_file_hash',
        'meta',
        'identity_hash',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'impressions' => 'integer',
            'reach' => 'integer',
            'link_clicks' => 'integer',
            'outbound_clicks' => 'integer',
            'landing_page_views' => 'integer',
            'spend' => 'decimal:4',
            'results' => 'decimal:6',
            'meta' => 'array',
            'imported_at' => 'immutable_datetime',
        ];
    }
}