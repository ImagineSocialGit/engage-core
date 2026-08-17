<?php

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

class ReportingExternalMeasurement extends Model
{
    protected $fillable = [
        'measurement_date',
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
        'identity_hash',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'measurement_date' => 'immutable_date',
            'impressions' => 'integer',
            'reach' => 'integer',
            'link_clicks' => 'integer',
            'outbound_clicks' => 'integer',
            'landing_page_views' => 'integer',
            'spend' => 'decimal:4',
            'results' => 'decimal:6',
            'imported_at' => 'immutable_datetime',
        ];
    }
}