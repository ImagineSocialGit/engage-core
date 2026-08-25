<?php

namespace App\Modules\InboundMessaging\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InboundReplyIntent extends Model
{
    protected $fillable = [
        'inbound_reply_profile_id',
        'key',
        'label',
        'description',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'inbound_reply_profile_id' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(
            InboundReplyProfile::class,
            'inbound_reply_profile_id',
        );
    }

    public function rules(): HasMany
    {
        return $this->hasMany(InboundReplyRule::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function activeRules(): HasMany
    {
        return $this->rules()->where('is_active', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}