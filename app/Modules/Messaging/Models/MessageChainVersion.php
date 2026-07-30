<?php

namespace App\Modules\Messaging\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessageChainVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'message_chain_id',
        'version',
        'exit_conditions',
        'content_hash',
        'published_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'message_chain_id' => 'integer',
            'version' => 'integer',
            'exit_conditions' => 'array',
            'published_at' => 'datetime',
            'created_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function messageChain(): BelongsTo
    {
        return $this->belongsTo(MessageChain::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(MessageChainStep::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(MessageChainEnrollment::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}