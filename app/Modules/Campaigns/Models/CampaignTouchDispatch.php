<?php

namespace App\Modules\Campaigns\Models;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignTouchDispatch extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'campaign_touch_variant_id',
        'contact_id',
        'occurrence_year',
        'due_at',
        'scheduled_message_id',
        'status',
        'reason',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'campaign_touch_variant_id' => 'integer',
            'contact_id' => 'integer',
            'occurrence_year' => 'integer',
            'due_at' => 'datetime',
            'scheduled_message_id' => 'integer',
            'meta' => 'array',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(
            CampaignTouchVariant::class,
            'campaign_touch_variant_id',
        );
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function scheduledMessage(): BelongsTo
    {
        return $this->belongsTo(ScheduledMessage::class);
    }
}