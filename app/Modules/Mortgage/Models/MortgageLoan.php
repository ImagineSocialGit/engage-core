<?php

namespace App\Modules\Mortgage\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MortgageLoan extends Model
{
    use HasFactory;

    protected $fillable = [
        'mortgage_stage_id',
        'source_system',
        'source_record_id',
        'source_fingerprint',
        'loan_originator',
        'loan_purpose',
        'loan_program',
        'mortgage_type',
        'lien_position',
        'loan_amount',
        'note_rate',
        'sales_price',
        'appraised_value',
        'cash_to_close',
        'subject_property_street',
        'subject_property_city',
        'subject_property_state',
        'subject_property_zip',
        'closed_on',
        'meta',
    ];

    protected $casts = [
        'loan_amount' => 'decimal:2',
        'note_rate' => 'decimal:4',
        'sales_price' => 'decimal:2',
        'appraised_value' => 'decimal:2',
        'cash_to_close' => 'decimal:2',
        'closed_on' => 'date',
        'meta' => 'array',
    ];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(
            MortgageStage::class,
            'mortgage_stage_id',
        );
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MortgageLoanParticipant::class);
    }

    public function realtors(): HasMany
    {
        return $this->hasMany(MortgageLoanRealtor::class);
    }
}