<?php

namespace App\Modules\PetServices\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PetVaccination extends Model
{
    use SoftDeletes;

    public const VERIFICATION_UNVERIFIED = 'unverified';
    public const VERIFICATION_VERIFIED = 'verified';
    public const VERIFICATION_REJECTED = 'rejected';

    protected $fillable = [
        'pet_id',
        'vaccination_key',
        'name',
        'administered_on',
        'expires_on',
        'verification_status',
        'verified_at',
        'source',
        'meta',
    ];

    protected $casts = [
        'administered_on' => 'date',
        'expires_on' => 'date',
        'verified_at' => 'datetime',
        'meta' => 'array',
    ];

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }
}