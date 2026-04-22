<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
        'crm_status',
        'converted_at',
        'last_contacted_at',
    ];

    protected $casts = [
        'converted_at' => 'datetime',
        'last_contacted_at' => 'datetime',
    ];

    public function registrations(): HasMany
    {
        return $this->hasMany(WebinarRegistration::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(LeadTag::class);
    }
}