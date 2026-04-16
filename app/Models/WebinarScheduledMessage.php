<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebinarScheduledMessage extends Model
{
    protected $fillable = [
        'webinar_registration_id',
        'channel',
        'message_type',
        'scheduled_for',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(WebinarRegistration::class, 'webinar_registration_id');
    }
}