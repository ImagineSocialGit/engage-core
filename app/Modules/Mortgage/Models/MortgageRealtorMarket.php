<?php

namespace App\Modules\Mortgage\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MortgageRealtorMarket extends Model
{
    use HasFactory;

    protected $fillable = [
        'mortgage_realtor_profile_id',
        'market_key',
        'is_primary',
        'meta',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'meta' => 'array',
    ];

    public function realtorProfile(): BelongsTo
    {
        return $this->belongsTo(
            MortgageRealtorProfile::class,
            'mortgage_realtor_profile_id',
        );
    }
}