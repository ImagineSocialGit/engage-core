<?php

namespace App\Modules\Messaging\Models;

use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContactPermissionInvitation extends Model
{
    public const CHANNEL_EMAIL = 'email';

    public const SOURCE_IMPORTED_CONTACT = 'imported_contact';

    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ACCEPTED = 'accepted';

    protected $fillable = [
        'contact_id',
        'scheduled_message_id',
        'context_type',
        'context_id',
        'channel',
        'source',
        'status',
        'claimed_at',
        'sent_at',
        'failed_at',
        'accepted_at',
        'failure_reason',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'contact_id' => 'integer',
            'scheduled_message_id' => 'integer',
            'context_id' => 'integer',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function scheduledMessage(): BelongsTo
    {
        return $this->belongsTo(ScheduledMessage::class);
    }

    public function context(): MorphTo
    {
        return $this->morphTo();
    }
}