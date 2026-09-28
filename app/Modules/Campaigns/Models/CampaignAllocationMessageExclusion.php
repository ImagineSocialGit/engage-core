<?php

namespace App\Modules\Campaigns\Models;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class CampaignAllocationMessageExclusion extends Model
{
    protected $fillable = [
        'contact_id',
        'campaign_id',
        'message_step_key',
        'source_type',
        'source_id',
        'excluded_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'contact_id' => 'integer',
            'campaign_id' => 'integer',
            'source_id' => 'integer',
            'excluded_by' => 'integer',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function excluder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by');
    }
}