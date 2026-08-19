<?php

namespace App\Modules\Mortgage\Models;

use App\Modules\Core\Models\Contact;
use App\Modules\Mortgage\Enums\MortgageLoanRealtorRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MortgageLoanRealtor extends Model
{
    use HasFactory;

    protected $fillable = [
        'mortgage_loan_id',
        'contact_id',
        'role',
        'position',
        'name',
        'email',
        'phone',
        'meta',
    ];

    protected $casts = [
        'role' => MortgageLoanRealtorRole::class,
        'position' => 'integer',
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