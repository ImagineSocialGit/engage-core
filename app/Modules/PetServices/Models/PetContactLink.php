<?php

namespace App\Modules\PetServices\Models;

use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PetContactLink extends Model
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_GUARDIAN = 'guardian';
    public const ROLE_OTHER = 'other';

    protected $fillable = [
        'pet_id',
        'contact_id',
        'role',
        'is_primary',
        'is_active',
        'started_at',
        'ended_at',
        'meta',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'meta' => 'array',
    ];

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}