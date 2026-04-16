<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Webinar extends Model
{
    protected $fillable = [
        'title',
        'slug',
        'status',
        'join_url',
        'platform',
        'starts_at',
        'timezone',
        'ends_at',
        'description',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(WebinarRegistration::class);
    }
}