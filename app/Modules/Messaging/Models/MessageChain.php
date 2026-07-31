<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class MessageChain extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'key',
        'name',
        'description',
        'status',
        'current_version_id',
        'source',
        'source_version',
        'is_customized',
        'customized_at',
    ];

    protected function casts(): array
    {
        return [
            'current_version_id' => 'integer',
            'is_customized' => 'boolean',
            'customized_at' => 'datetime',
        ];
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(MessageChainVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MessageChainVersion::class)
            ->orderByDesc('version');
    }

    public function requireCurrentVersion(): MessageChainVersion
    {
        $version = $this->relationLoaded('currentVersion')
            ? $this->getRelation('currentVersion')
            : $this->currentVersion()
                ->with('steps.variants')
                ->first();

        if (! $version instanceof MessageChainVersion) {
            throw new RuntimeException(
                "MessageChain [{$this->key}] has no current version.",
            );
        }

        if (! $version->relationLoaded('steps')) {
            $version->load('steps.variants');
        }

        return $version;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeCustomized(Builder $query): Builder
    {
        return $query->where('is_customized', true);
    }

    public function scopeNotCustomized(Builder $query): Builder
    {
        return $query->where('is_customized', false);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}