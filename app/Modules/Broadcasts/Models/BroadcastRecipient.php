<?php

namespace App\Modules\Broadcasts\Models;

use App\Modules\Core\Models\Contact;
use Database\Factories\BroadcastRecipientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BroadcastRecipient extends Model
{
    use HasFactory;

    protected static function newFactory(): BroadcastRecipientFactory
    {
        return BroadcastRecipientFactory::new();
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'broadcast_id',
        'contact_id',
        'status',
        'scheduled_message_ids',
        'sent_at',
        'skip_reason',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'broadcast_id' => 'integer',
            'contact_id' => 'integer',
            'scheduled_message_ids' => 'array',
            'sent_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}