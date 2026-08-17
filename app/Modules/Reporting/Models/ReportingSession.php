<?php

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportingSession extends Model
{
    protected $fillable = [
        'token_hash',
        'host',
        'surface',
        'started_at',
        'last_seen_at',
        'absolute_expires_at',
        'landing_path',
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
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'absolute_expires_at' => 'immutable_datetime',
            'click_id_hashes' => 'array',
            'classifier_version' => 'integer',
            'classification_reasons' => 'array',
        ];
    }

    public function observations(): HasMany
    {
        return $this->hasMany(ReportingObservation::class);
    }
}