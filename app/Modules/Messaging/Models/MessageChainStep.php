<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class MessageChainStep extends Model
{
    public const TIMING_IMMEDIATE = 'immediate';
    public const TIMING_DELAY = 'delay';
    public const TIMING_ANCHORED = 'anchored';
    public const TIMING_NEXT_DAY_AT = 'next_day_at';

    public const VARIANT_STRATEGY_FIRST_AVAILABLE = 'first_available';
    public const VARIANT_STRATEGY_SEND_ALL_ELIGIBLE = 'send_all_eligible';
    public const VARIANT_STRATEGY_DEPENDENCY_AWARE = 'dependency_aware';

    public const ADVANCE_ALL_TERMINAL = 'all_terminal';
    public const ADVANCE_FIRST_SENT = 'first_sent';
    public const ADVANCE_FIRST_TERMINAL = 'first_terminal';

    protected static function booted(): void
    {
        static::saving(
            static fn (self $step): mixed =>
                $step->assertVersionIsMutable(),
        );
        static::deleting(
            static fn (self $step): mixed =>
                $step->assertVersionIsMutable(),
        );
    }

    protected $fillable = [
        'message_chain_version_id',
        'key',
        'name',
        'sort_order',
        'timing_type',
        'anchor_key',
        'offset_seconds',
        'day_offset',
        'local_time',
        'variant_strategy',
        'advance_policy',
        'conditions',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'message_chain_version_id' => 'integer',
            'sort_order' => 'integer',
            'offset_seconds' => 'integer',
            'day_offset' => 'integer',
            'conditions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function messageChainVersion(): BelongsTo
    {
        return $this->belongsTo(MessageChainVersion::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(MessageChainStepVariant::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function currentEnrollments(): HasMany
    {
        return $this->hasMany(
            MessageChainEnrollment::class,
            'current_message_chain_step_id',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $variants = $this->relationLoaded('variants')
            ? $this->getRelation('variants')
            : $this->variants()->get();

        return [
            'key' => $this->key,
            'name' => $this->name,
            'sort_order' => (int) $this->sort_order,
            'timing_type' => $this->timing_type,
            'anchor_key' => $this->anchor_key,
            'offset_seconds' => (int) $this->offset_seconds,
            'day_offset' => (int) $this->day_offset,
            'local_time' => $this->normalizedLocalTime(),
            'variant_strategy' => $this->variant_strategy,
            'advance_policy' => $this->advance_policy,
            'conditions' => is_array($this->conditions)
                ? $this->conditions
                : null,
            'is_active' => (bool) $this->is_active,
            'variants' => $variants
                ->map(
                    fn (MessageChainStepVariant $variant): array =>
                        $variant->definition(),
                )
                ->values()
                ->all(),
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    private function assertVersionIsMutable(): void
    {
        if (! $this->message_chain_version_id) {
            return;
        }

        $published = MessageChainVersion::query()
            ->whereKey($this->message_chain_version_id)
            ->whereNotNull('published_at')
            ->exists();

        if ($published) {
            throw new LogicException(
                'Published MessageChainStep records are immutable.',
            );
        }
    }

    private function normalizedLocalTime(): ?string
    {
        if ($this->local_time === null) {
            return null;
        }

        if ($this->local_time instanceof \DateTimeInterface) {
            return $this->local_time->format('H:i:s');
        }

        $value = trim((string) $this->local_time);

        return $value !== '' ? $value : null;
    }
}