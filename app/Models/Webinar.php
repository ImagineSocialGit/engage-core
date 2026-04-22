<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Webinar extends Model
{
    protected $fillable = [
        'series_id',
        'title',
        'slug',
        'status',
        'platform',
        'external_id',
        'host_account_key',
        'join_url',
        'registration_url',
        'starts_at',
        'ends_at',
        'timezone',
        'description',
        'meta',
        'provider_settings',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'meta' => 'array',
        'provider_settings' => 'array',
    ];

    public function series(): BelongsTo
    {
        return $this->belongsTo(WebinarSeries::class, 'series_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(WebinarRegistration::class);
    }
}