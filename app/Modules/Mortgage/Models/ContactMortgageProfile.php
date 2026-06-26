<?php

namespace App\Modules\Mortgage\Models;

use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactMortgageProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'mortgage_stage_id',
        'title',
        'loan_amount',
        'rate',
        'mortgage_type',
        'loan_purpose',
        'loan_program',
        'lien_position',
        'meta',
    ];

    protected $casts = [
        'loan_amount' => 'decimal:2',
        'rate' => 'decimal:3',
        'meta' => 'array',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(
            MortgageStage::class,
            'mortgage_stage_id',
        );
    }
}