<?php

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportingObservation extends Model
{
    protected $fillable = [
        'event_id',
        'payload_hash',
        'reporting_session_id',
        'event_key',
        'event_version',
        'source',
        'occurred_at',
        'received_at',
        'host',
        'surface',
        'path',
        'referrer_host',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'external_platform',
        'external_campaign_id',
        'external_group_id',
        'external_creative_id',
        'external_placement',
        'click_id_hashes',
        'traffic_class',
        'classifier_key',
        'classifier_version',
        'classification_reasons',
        'device_class',
        'browser_family',
        'os_family',
        'properties',
    ];

    protected function casts(): array
    {
        return [
            'event_version' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'click_id_hashes' => 'array',
            'classifier_version' => 'integer',
            'classification_reasons' => 'array',
            'properties' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ReportingSession::class, 'reporting_session_id');
    }
}