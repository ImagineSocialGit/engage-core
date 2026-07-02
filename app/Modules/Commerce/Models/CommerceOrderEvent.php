<?php

namespace App\Modules\Commerce\Models;

use Database\Factories\CommerceOrderEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommerceOrderEvent extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): CommerceOrderEventFactory
    {
        return CommerceOrderEventFactory::new();
    }

    public const EVENT_CREATED = 'created';
    public const EVENT_UPDATED = 'updated';
    public const EVENT_PAID = 'paid';
    public const EVENT_CANCELLED = 'cancelled';
    public const EVENT_REFUNDED = 'refunded';
    public const EVENT_FULFILLED = 'fulfilled';
    public const EVENT_SYNCED = 'synced';

    protected $fillable = [
        'commerce_order_id',
        'actor_type',
        'actor_id',
        'event',
        'from_status',
        'to_status',
        'occurred_at',
        'source',
        'provider',
        'external_id',
        'payload',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'commerce_order_id' => 'integer',
            'actor_id' => 'integer',
            'occurred_at' => 'datetime',
            'payload' => 'array',
            'meta' => 'array',
        ];
    }

    public function commerceOrder(): BelongsTo
    {
        return $this->belongsTo(CommerceOrder::class);
    }

    public function actor(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'actor_type', 'actor_id');
    }
}
