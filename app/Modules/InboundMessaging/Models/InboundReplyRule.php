<?php

namespace App\Modules\InboundMessaging\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundReplyRule extends Model
{
    public const MATCH_EXACT = 'exact';
    public const MATCH_KEYWORD = 'keyword';

    public const MATCH_TYPES = [
        self::MATCH_EXACT,
        self::MATCH_KEYWORD,
    ];

    protected $fillable = [
        'inbound_reply_intent_id',
        'match_type',
        'value',
        'normalized_value',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'inbound_reply_intent_id' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(
            InboundReplyIntent::class,
            'inbound_reply_intent_id',
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}