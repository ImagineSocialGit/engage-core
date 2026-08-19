<?php

namespace App\Modules\Mortgage\Models;

use App\Modules\Core\Models\Contact;
use App\Modules\Mortgage\Enums\MortgageLoanParticipantRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MortgageLoanParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'mortgage_loan_id',
        'contact_id',
        'role',
        'position',
        'first_name',
        'last_name',
        'email',
        'phone',
        'date_of_birth',
        'mailing_address',
        'meta',
    ];

    protected $casts = [
        'role' => MortgageLoanParticipantRole::class,
        'position' => 'integer',
        'date_of_birth' => 'date',
        'meta' => 'array',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(MortgageLoan::class, 'mortgage_loan_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}