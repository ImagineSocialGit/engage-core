<?php

namespace App\Modules\Mortgage\Models;

use App\Modules\Core\Models\Contact;
use App\Modules\Mortgage\Enums\HasRealtorState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactMortgageProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'has_realtor',
        'original_lead_at',
        'meta',
    ];

    protected $casts = [
        'has_realtor' => HasRealtorState::class,
        'original_lead_at' => 'datetime',
        'meta' => 'array',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}