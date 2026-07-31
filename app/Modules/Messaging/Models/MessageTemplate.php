<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class MessageTemplate extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'key',
        'name',
        'description',
        'channel',
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
        return $this->belongsTo(MessageTemplateVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MessageTemplateVersion::class)
            ->orderByDesc('version');
    }

    /**
     * @return array<string, mixed>
     */
    public function currentPayload(): array
    {
        $version = $this->relationLoaded('currentVersion')
            ? $this->getRelation('currentVersion')
            : $this->currentVersion()->first();

        if (! $version instanceof MessageTemplateVersion) {
            return [];
        }

        return $version->payload();
    }

    public function requireCurrentVersion(): MessageTemplateVersion
    {
        $version = $this->relationLoaded('currentVersion')
            ? $this->getRelation('currentVersion')
            : $this->currentVersion()->first();

        if (! $version instanceof MessageTemplateVersion) {
            throw new RuntimeException(
                "MessageTemplate [{$this->key}] has no current version.",
            );
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