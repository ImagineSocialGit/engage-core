<?php

namespace App\Modules\Campaigns\Models;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class CampaignPriorMessageReceipt extends Model
{
    public const SOURCE_CONTACT_IMPORT = 'contact_import';
    public const SOURCE_OPERATOR_ATTESTATION = 'operator_attestation';

    protected $fillable = [
        'contact_id',
        'campaign_id',
        'message_step_key',
        'evidence_source',
        'source_type',
        'source_id',
        'attested_by',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'contact_id' => 'integer',
            'campaign_id' => 'integer',
            'source_id' => 'integer',
            'attested_by' => 'integer',
            'received_at' => 'datetime',
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

    public function attester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attested_by');
    }
}