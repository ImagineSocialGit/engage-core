<?php

namespace App\Modules\Messaging\Models;

use Database\Factories\MessageTemplatePresetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessageTemplatePreset extends Model
{
    use HasFactory;

    protected static function newFactory(): MessageTemplatePresetFactory
    {
        return MessageTemplatePresetFactory::new();
    }

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'key',
        'name',
        'description',
        'channel',
        'purpose',
        'scope',
        'message_type',
        'payload_class',
        'queue',
        'dispatch_keys',
        'timing',
        'schedule',
        'conditions',
        'payload',
        'tokens',
        'status',
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
            'dispatch_keys' => 'array',
            'schedule' => 'array',
            'conditions' => 'array',
            'payload' => 'array',
            'tokens' => 'array',
            'is_active' => 'boolean',
            'source_version' => 'integer',
            'is_customized' => 'boolean',
            'customized_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(MessageTemplatePresetAssignment::class);
    }

    public function catalogEntries(): HasMany
    {
        return $this->hasMany(MessageTemplateCatalogEntry::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->is_active && $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toMessageDefinition(?MessageTemplatePresetAssignment $assignment = null): array
    {
        $meta = array_replace_recursive(
            $this->meta ?? [],
            [
                'message_template_preset' => [
                    'id' => $this->getKey(),
                    'key' => $this->key,
                    'assignment_id' => $assignment?->getKey(),
                    'source_config_path' => $this->source_config_path,
                ],
            ],
        );

        $definition = array_filter([
            'channel' => $assignment?->channel ?? $this->channel,
            'purpose' => $assignment?->purpose ?? $this->purpose,
            'scope' => $assignment?->scope ?? $this->scope,
            'message_type' => $assignment?->message_type ?? $this->message_type,
            'dispatch_keys' => $this->dispatchKeys(),
            'timing' => $this->timing ?: 'immediate',
            'schedule' => $this->schedule,
            'conditions' => $this->conditions ?? [],
            'payload_class' => $this->payload_class,
            'queue' => $this->queue,
            'payload' => $this->payload ?? [],
            'campaign_key' => $assignment?->campaign_key,
            'step' => $assignment?->campaign_step,
            'notification_type' => $meta['notification_type'] ?? null,
            'skip_when_join_clicked' => (bool) ($meta['skip_when_join_clicked'] ?? false),
            'meta' => $meta,
        ], static fn (mixed $value): bool => $value !== null);

        $definition['config_path'] = null;

        return $definition;
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

