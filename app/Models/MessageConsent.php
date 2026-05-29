<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MessageConsent extends Model
{
    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'channel',
        'purpose',
        'consented_at',
        'source',
        'ip_address',
        'user_agent',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'recipient_id' => 'integer',
            'consented_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }
}