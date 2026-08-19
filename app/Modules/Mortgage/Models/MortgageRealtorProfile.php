<?php

namespace App\Modules\Mortgage\Models;

use App\Modules\Relationships\Models\ContactRelationship;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MortgageRealtorProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_relationship_id',
        'brokerage_name',
        'license_number',
        'last_referral_at',
        'meta',
    ];

    protected $casts = [
        'last_referral_at' => 'datetime',
        'meta' => 'array',
    ];

    public function contactRelationship(): BelongsTo
    {
        return $this->belongsTo(ContactRelationship::class);
    }


    public function productionSnapshots(): HasMany
    {
        return $this->hasMany(MortgageRealtorProductionSnapshot::class);
    }
}