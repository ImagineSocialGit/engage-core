<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MessageChainStepVariant extends Model
{
    protected static function booted(): void
    {
        static::saving(
            static fn (self $variant): mixed =>
                $variant->assertVersionIsMutable(),
        );
        static::deleting(
            static fn (self $variant): mixed =>
                $variant->assertVersionIsMutable(),
        );
    }

    protected $fillable = [
        'message_chain_step_id',
        'key',
        'sort_order',
        'message_template_version_id',
        'channel',
        'purpose',
        'scope',
        'message_type',
        'reply_profile_key',
        'queue',
        'dependency_policy',
        'conditions',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'message_chain_step_id' => 'integer',
            'sort_order' => 'integer',
            'message_template_version_id' => 'integer',
            'dependency_policy' => 'array',
            'conditions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function messageChainStep(): BelongsTo
    {
        return $this->belongsTo(MessageChainStep::class);
    }

    public function messageTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(MessageTemplateVersion::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $definition = [
            'key' => $this->key,
            'sort_order' => (int) $this->sort_order,
            'message_template_version_id' =>
                (int) $this->message_template_version_id,
            'channel' => $this->channel,
            'purpose' => $this->purpose,
            'scope' => $this->scope,
            'message_type' => $this->message_type,
            'queue' => $this->queue,
            'dependency_policy' => is_array($this->dependency_policy)
                ? $this->dependency_policy
                : null,
            'conditions' => is_array($this->conditions)
                ? $this->conditions
                : null,
            'is_active' => (bool) $this->is_active,
        ];

        if (is_string($this->reply_profile_key)
            && trim($this->reply_profile_key) !== ''
        ) {
            $definition['reply_profile_key'] = trim($this->reply_profile_key);
        }

        return $definition;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    private function assertVersionIsMutable(): void
    {
        if (! $this->message_chain_step_id) {
            return;
        }

        $published = MessageChainStep::query()
            ->whereKey($this->message_chain_step_id)
            ->whereHas(
                'messageChainVersion',
                fn (Builder $query): Builder =>
                    $query->whereNotNull('published_at'),
            )
            ->exists();

        if ($published) {
            throw new LogicException(
                'Published MessageChainStepVariant records are immutable.',
            );
        }
    }
}