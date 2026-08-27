<?php

namespace App\Modules\Webinars\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebinarOccurrenceSuppression extends Model
{
    public const REASON_OPERATOR_REMOVED = 'operator_removed';

    protected $fillable = [
        'webinar_series_id',
        'platform',
        'provider_event_type',
        'external_id',
        'external_uuid',
        'reason',
        'suppressed_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'webinar_series_id' => 'integer',
            'suppressed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function webinarSeries(): BelongsTo
    {
        return $this->belongsTo(WebinarSeries::class);
    }
}