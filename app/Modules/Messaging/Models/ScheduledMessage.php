<?php

namespace App\Modules\Messaging\Models;

use Database\Factories\ScheduledMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ScheduledMessage extends Model
{
    use HasFactory;

    protected static function newFactory(): ScheduledMessageFactory
    {
        return ScheduledMessageFactory::new();
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'context_type',
        'context_id',
        'behavior_owner_type',
        'behavior_owner_id',
        'message_template_version_id',
        'message_chain_enrollment_id',
        'message_chain_step_variant_id',
        'channel',
        'message_type',
        'reply_profile_key',
        'purpose',
        'scope',
        'payload_class',
        'queue',
        'dispatch_keys',
        'definition_config_path',
        'payload',
        'send_at',
        'status',
        'provider_idempotency_key',
        'dedupe_key',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'recipient_id' => 'integer',
            'context_id' => 'integer',
            'behavior_owner_id' => 'integer',
            'message_template_version_id' => 'integer',
            'message_chain_enrollment_id' => 'integer',
            'message_chain_step_variant_id' => 'integer',
            'dispatch_keys' => 'array',
            'payload' => 'array',
            'send_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    public function context(): MorphTo
    {
        return $this->morphTo();
    }

    public function behaviorOwner(): MorphTo
    {
        return $this->morphTo('behavior_owner');
    }

    public function messageTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(MessageTemplateVersion::class);
    }

    public function messageChainEnrollment(): BelongsTo
    {
        return $this->belongsTo(MessageChainEnrollment::class);
    }

    public function messageChainStepVariant(): BelongsTo
    {
        return $this->belongsTo(MessageChainStepVariant::class);
    }

    public function replyProfileKey(): ?string
    {
        if (is_string($this->reply_profile_key)
            && trim($this->reply_profile_key) !== ''
        ) {
            return trim($this->reply_profile_key);
        }

        if ($this->message_chain_step_variant_id === null) {
            return null;
        }

        $variant = $this->relationLoaded('messageChainStepVariant')
            ? $this->getRelation('messageChainStepVariant')
            : $this->messageChainStepVariant()->first();

        return $variant instanceof MessageChainStepVariant
            && is_string($variant->reply_profile_key)
            && trim($variant->reply_profile_key) !== ''
                ? trim($variant->reply_profile_key)
                : null;
    }

    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(ScheduledMessageDeliveryAttempt::class)
            ->orderBy('attempt_number');
    }

    public function latestDeliveryAttempt(): HasOne
    {
        return $this->hasOne(ScheduledMessageDeliveryAttempt::class)
            ->ofMany('attempt_number', 'max');
    }

    public function terminalOutboxEvent(): HasOne
    {
        return $this->hasOne(ScheduledMessageOutboxEvent::class);
    }

    public function renderContext(): HasOne
    {
        return $this->hasOne(ScheduledMessageRenderContext::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(ScheduledMessageComponent::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }


    public function scopeBackgroundEligible(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereNull('message_chain_enrollment_id')
                ->orWhereHas(
                    'messageChainEnrollment',
                    fn (Builder $enrollment): Builder => $enrollment
                        ->where(function (Builder $surface): void {
                            $surface
                                ->whereNull('surface')
                                ->orWhere(
                                    'surface',
                                    'not like',
                                    MessageChainEnrollment::TESTING_SURFACE_PREFIX.'%',
                                );
                        }),
                );
        });
    }

    public function isTestingRuntime(): bool
    {
        if ($this->message_chain_enrollment_id === null) {
            return false;
        }

        $enrollment = $this->relationLoaded('messageChainEnrollment')
            ? $this->getRelation('messageChainEnrollment')
            : $this->messageChainEnrollment()->first();

        return $enrollment instanceof MessageChainEnrollment
            && $enrollment->isTestingSurface();
    }

    /**
     * @return array<int, string>
     */
    public function dispatchKeys(): array
    {
        return array_values(array_filter(
            $this->dispatch_keys ?? [],
            fn (mixed $dispatchKey): bool => is_string($dispatchKey) && trim($dispatchKey) !== '',
        ));
    }
}