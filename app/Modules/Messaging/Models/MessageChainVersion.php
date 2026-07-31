<?php

namespace App\Modules\Messaging\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class MessageChainVersion extends Model
{
    protected static function booted(): void
    {
        static::updating(static function (self $version): void {
            $dirty = array_keys($version->getDirty());
            sort($dirty);

            if (
                $version->getOriginal('published_at') === null
                && $version->published_at !== null
                && $dirty === ['published_at']
            ) {
                return;
            }

            throw new LogicException(
                'Published MessageChainVersion records are immutable.',
            );
        });

        static::deleting(static function (): never {
            throw new LogicException(
                'Published MessageChainVersion records are immutable.',
            );
        });
    }

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

    /**
     * @return array{
     *     exit_conditions: array<string, mixed>|null,
     *     steps: array<int, array<string, mixed>>
     * }
     */
    public function definition(): array
    {
        $steps = $this->relationLoaded('steps')
            ? $this->getRelation('steps')
            : $this->steps()->with('variants')->get();

        $steps->loadMissing('variants');

        return [
            'exit_conditions' => is_array($this->exit_conditions)
                ? $this->exit_conditions
                : null,
            'steps' => $steps
                ->map(
                    fn (MessageChainStep $step): array =>
                        $step->definition(),
                )
                ->values()
                ->all(),
        ];
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