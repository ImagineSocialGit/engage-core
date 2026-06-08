<?php

namespace App\Models;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessageConsent extends Model
{
    protected $fillable = [
        'contact_id',
        'channel',
        'purpose',
        'scope',
        'consented_at',
        'source',
        'ip_address',
        'user_agent',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'purpose' => MessagePurpose::class,
            'contact_id' => 'integer',
            'consented_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function revocations(): HasMany
    {
        return $this->hasMany(ConsentRevocation::class);
    }
}