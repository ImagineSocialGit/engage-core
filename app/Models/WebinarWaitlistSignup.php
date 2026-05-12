<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebinarWaitlistSignup extends Model
{
    protected $fillable = [
        'webinar_series_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'email_consent_at',
        'sms_consent_at',
        'source_page',
        'ip_address',
        'user_agent',
        'meta',
    ];

    protected $casts = [
        'email_consent_at' => 'datetime',
        'sms_consent_at' => 'datetime',
        'meta' => 'array',
    ];

    public function series(): BelongsTo
    {
        return $this->belongsTo(WebinarSeries::class, 'webinar_series_id');
    }
}