<?php

namespace App\Modules\Campaigns\Models;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CampaignEnrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'campaign_id',
        'message_chain_enrollment_id',
        'source_type',
        'source_id',
        'campaign_key',
        'start_context',
        'dedupe_key',
        'started_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'contact_id' => 'integer',
            'campaign_id' => 'integer',
            'message_chain_enrollment_id' => 'integer',
            'source_id' => 'integer',
            'start_context' => 'array',
            'started_at' => 'datetime',
            'meta' => 'array',
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

    public function messageChainEnrollment(): BelongsTo
    {
        return $this->belongsTo(MessageChainEnrollment::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function runtimeStatus(): ?string
    {
        $enrollment = $this->relationLoaded('messageChainEnrollment')
            ? $this->getRelation('messageChainEnrollment')
            : $this->messageChainEnrollment()->first();

        return $enrollment instanceof MessageChainEnrollment
            ? $enrollment->status
            : null;
    }

    public function isOpen(): bool
    {
        return in_array($this->runtimeStatus(), [
            MessageChainEnrollment::STATUS_ACTIVE,
            MessageChainEnrollment::STATUS_PAUSED,
        ], true);
    }
}