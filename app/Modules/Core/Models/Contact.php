<?php

namespace App\Modules\Core\Models;

use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }

    protected $fillable = [
        'first_name',
        'last_name',
        'name',
        'email',
        'phone',
        'source',
        'subsource',
        'last_contacted_at',
        'last_activity_at',
        'meta',
    ];

    protected $casts = [
        'last_contacted_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'meta' => 'array',
    ];

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(ContactTag::class);
    }
}