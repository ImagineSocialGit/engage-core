<?php

namespace App\Modules\Mortgage\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MortgageRealtorProductionSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'mortgage_realtor_profile_id',
        'period_ending_on',
        'period_months',
        'loan_count',
        'conventional_count',
        'va_count',
        'loan_volume',
        'source',
        'source_fingerprint',
        'meta',
    ];

    protected $casts = [
        'period_ending_on' => 'date',
        'period_months' => 'integer',
        'loan_count' => 'integer',
        'conventional_count' => 'integer',
        'va_count' => 'integer',
        'loan_volume' => 'decimal:2',
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