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

    protected $fillable = [
        'broadcast_id',
        'contact_id',
        'status',
        'scheduled_message_ids',
        'skip_reason',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'broadcast_id' => 'integer',
            'contact_id' => 'integer',
            'scheduled_message_ids' => 'array',
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