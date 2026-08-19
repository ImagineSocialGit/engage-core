<?php

namespace App\Modules\Mortgage\Models;

use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MortgageRealtorProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'relationship_stage_key',
        'brokerage_name',
        'license_number',
        'last_referral_at',
        'last_contact_at',
        'meta',
    ];

    protected $casts = [
        'last_referral_at' => 'datetime',
        'last_contact_at' => 'datetime',
        'meta' => 'array',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function markets(): HasMany
    {
        return $this->hasMany(MortgageRealtorMarket::class);
    }

    public function productionSnapshots(): HasMany
    {
        return $this->hasMany(MortgageRealtorProductionSnapshot::class);
    }
}