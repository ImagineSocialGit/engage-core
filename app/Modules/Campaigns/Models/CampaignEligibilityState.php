<?php

namespace App\Modules\Campaigns\Models;

use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignEligibilityState extends Model
{
    protected $fillable = [
        'campaign_id',
        'contact_id',
        'is_eligible',
        'eligibility_cycle',
        'became_eligible_at',
        'became_ineligible_at',
        'last_evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'contact_id' => 'integer',
            'is_eligible' => 'boolean',
            'eligibility_cycle' => 'integer',
            'became_eligible_at' => 'datetime',
            'became_ineligible_at' => 'datetime',
            'last_evaluated_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}