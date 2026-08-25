<?php

namespace App\Modules\InboundMessaging\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InboundReplyProfile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'key',
        'label',
        'description',
        'is_active',
        'source',
        'source_config_path',
        'source_version',
        'is_customized',
        'customized_at',
        'last_synced_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_customized' => 'boolean',
            'customized_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function intents(): HasMany
    {
        return $this->hasMany(InboundReplyIntent::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function activeIntents(): HasMany
    {
        return $this->intents()->where('is_active', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}