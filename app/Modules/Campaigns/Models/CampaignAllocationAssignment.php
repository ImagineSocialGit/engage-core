<?php

namespace App\Modules\Campaigns\Models;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CampaignAllocationAssignment extends Model
{
    protected $fillable = [
        'campaign_id',
        'contact_id',
        'campaign_allocation_run_id',
        'campaign_allocation_enrollment_id',
        'message_chain_version_id',
        'message_step_key',
        'scheduled_message_id',
        'assigned_at',
        'sent_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'contact_id' => 'integer',
            'campaign_allocation_run_id' => 'integer',
            'campaign_allocation_enrollment_id' => 'integer',
            'message_chain_version_id' => 'integer',
            'scheduled_message_id' => 'integer',
            'assigned_at' => 'datetime',
            'sent_at' => 'datetime',
            'meta' => 'array',
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

    public function run(): BelongsTo
    {
        return $this->belongsTo(
            CampaignAllocationRun::class,
            'campaign_allocation_run_id',
        );
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(
            CampaignAllocationEnrollment::class,
            'campaign_allocation_enrollment_id',
        );
    }

    public function messageChainVersion(): BelongsTo
    {
        return $this->belongsTo(MessageChainVersion::class);
    }

    public function scheduledMessage(): BelongsTo
    {
        return $this->belongsTo(ScheduledMessage::class);
    }
}