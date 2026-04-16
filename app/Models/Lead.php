<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $fillable = [
        'first_name',
        'last_name',
        'name',
        'email',
        'phone',
        'status',
        'source',
        'subsource',
        'notes',
        'last_contacted_at',
    ];

    protected function casts(): array
    {
        return [
            'last_contacted_at' => 'datetime',
        ];
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withTimestamps();
    }

    public function webinarRegistrations(): HasMany
    {
        return $this->hasMany(WebinarRegistration::class);
    }

    public function leadNotes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}