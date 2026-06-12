<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebinarWaitlistSignup extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'webinar_series_id',
        'notified_at',
        'source_page',
        'meta',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
        'meta' => 'array',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function webinarSeries(): BelongsTo
    {
        return $this->belongsTo(WebinarSeries::class);
    }
}