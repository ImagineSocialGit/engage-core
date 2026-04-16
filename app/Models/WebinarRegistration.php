<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebinarRegistration extends Model
{
    protected $fillable = [
        'lead_id',
        'webinar_id',
        'webinar_slug',
        'status',
        'source',
        'first_name',
        'last_name',
        'email',
        'phone',
        'notes',
        'meta',
        'registered_at',
        'attended_at',
        'converted_at',
        'follow_up_status',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'registered_at' => 'datetime',
            'attended_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function webinar(): BelongsTo
    {
        return $this->belongsTo(Webinar::class);
    }
}