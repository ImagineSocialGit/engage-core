<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCompositionLayer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MessageTemplateCompositionResolver
{
    public function __construct(
        private readonly MessageTemplateCompositionSchema $schema,
    ) {}

    /**
     * @param array<string, mixed> $sourcePayload
     * @return array<string, mixed>
     */
    public function resolve(
        MessageTemplate $messageTemplate,
        array $sourcePayload,
        ?string $clientKey = null,
    ): array {
        return $this->compose(
            messageTemplate: $messageTemplate,
            sourcePayload: $sourcePayload,
            layers: $this->applicableLayers($messageTemplate, $clientKey),
        );
    }

    /**
     * @param array<string, mixed> $sourcePayload
     * @return array<string, mixed>
     */
    public function resolveWithoutMessageOverride(
        MessageTemplate $messageTemplate,
        array $sourcePayload,
        ?string $clientKey = null,
    ): array {
        return $this->compose(
            messageTemplate: $messageTemplate,
            sourcePayload: $sourcePayload,
            layers: $this->applicableLayers($messageTemplate, $clientKey)
                ->except(MessageTemplateCompositionLayer::SCOPE_MESSAGE),
        );
    }

    /**
     * @param array<string, mixed> $sourcePayload
     * @param array<string, mixed> $proposedPayload
     * @return array<string, mixed>
     */
    public function resolveWithLayerPayload(
        MessageTemplate $messageTemplate,
        array $sourcePayload,
        MessageTemplateCompositionLayer $layer,
        array $proposedPayload,
        ?string $clientKey = null,
    ): array {
        $layers = $this->applicableLayers($messageTemplate, $clientKey);
        $matching = $layers->get($layer->scope_type);

        if (! $matching instanceof MessageTemplateCompositionLayer
            || (int) $matching->getKey() !== (int) $layer->getKey()
        ) {
            return $this->compose(
                messageTemplate: $messageTemplate,
                sourcePayload: $sourcePayload,
                layers: $layers,
            );
        }

        $replacement = $matching->replicate();
        $replacement->setRawAttributes($matching->getAttributes(), true);
        $replacement->payload = $this->schema->validatePayload(
            (string) $messageTemplate->channel,
            $proposedPayload,
        );

        $layers->put($layer->scope_type, $replacement);

        return $this->compose(
            messageTemplate: $messageTemplate,
            sourcePayload: $sourcePayload,
            layers: $layers,
        );
    }

    /**
     * @param array<string, mixed> $sourcePayload
     * @return array<string, mixed>
     */
    public function resolveWithoutLayer(
        MessageTemplate $messageTemplate,
        array $sourcePayload,
        MessageTemplateCompositionLayer $layer,
        ?string $clientKey = null,
    ): array {
        $layers = $this->applicableLayers($messageTemplate, $clientKey);
        $matching = $layers->get($layer->scope_type);

        if ($matching instanceof MessageTemplateCompositionLayer
            && (int) $matching->getKey() === (int) $layer->getKey()
        ) {
            $layers->forget($layer->scope_type);
        }

        return $this->compose(
            messageTemplate: $messageTemplate,
            sourcePayload: $sourcePayload,
            layers: $layers,
        );
    }

    /**
     * @return Collection<string, MessageTemplateCompositionLayer>
     */
    public function applicableLayers(
        MessageTemplate $messageTemplate,
        ?string $clientKey = null,
    ): Collection {
        $channel = $this->segment((string) $messageTemplate->channel);
        $clientKey = $this->nullableSegment($clientKey ?? config('client.key'));
        $contextKey = $this->nullableSegment($messageTemplate->composition_context_key);
        $familyKey = $this->nullableSegment($messageTemplate->composition_family_key);

        return $this->matchingLayers(
            messageTemplate: $messageTemplate,
            channel: $channel,
            clientKey: $clientKey,
            contextKey: $contextKey,
            familyKey: $familyKey,
        );
    }

    /**
     * @param array<string, mixed> $sourcePayload
     * @param Collection<string, MessageTemplateCompositionLayer> $layers
     * @return array<string, mixed>
     */
    private function compose(
        MessageTemplate $messageTemplate,
        array $sourcePayload,
        Collection $layers,
    ): array {
        $channel = $this->segment((string) $messageTemplate->channel);
        $resolved = [];

        foreach ([
            MessageTemplateCompositionLayer::SCOPE_PLATFORM,
            MessageTemplateCompositionLayer::SCOPE_CLIENT,
            MessageTemplateCompositionLayer::SCOPE_FAMILY,
            MessageTemplateCompositionLayer::SCOPE_CONTEXT,
            MessageTemplateCompositionLayer::SCOPE_CONTEXT_FAMILY,
        ] as $scopeType) {
            $layer = $layers->get($scopeType);

            if ($layer instanceof MessageTemplateCompositionLayer) {
                $resolved = $this->overlay(
                    $resolved,
                    $this->schema->validatePayload(
                        $channel,
                        is_array($layer->payload) ? $layer->payload : [],
                    ),
                );
            }
        }

        $resolved = $this->overlay($resolved, $sourcePayload);

        $messageLayer = $layers->get(MessageTemplateCompositionLayer::SCOPE_MESSAGE);

        if ($messageLayer instanceof MessageTemplateCompositionLayer) {
            $resolved = $this->overlay(
                $resolved,
                $this->schema->validatePayload(
                    $channel,
                    is_array($messageLayer->payload) ? $messageLayer->payload : [],
                ),
            );
        }

        return $resolved;
    }

    /**
     * @return Collection<string, MessageTemplateCompositionLayer>
     */
    private function matchingLayers(
        MessageTemplate $messageTemplate,
        string $channel,
        ?string $clientKey,
        ?string $contextKey,
        ?string $familyKey,
    ): Collection {
        $query = MessageTemplateCompositionLayer::query()
            ->where('channel', $channel)
            ->where(function (Builder $query) use ($messageTemplate, $clientKey, $contextKey, $familyKey): void {
                $query->where(function (Builder $query): void {
                    $query->where('scope_type', MessageTemplateCompositionLayer::SCOPE_PLATFORM)
                        ->whereNull('client_key')
                        ->whereNull('context_key')
                        ->whereNull('family_key')
                        ->whereNull('message_template_id');
                });

                if ($clientKey !== null) {
                    $query->orWhere(function (Builder $query) use ($clientKey): void {
                        $query->where('scope_type', MessageTemplateCompositionLayer::SCOPE_CLIENT)
                            ->where('client_key', $clientKey)
                            ->whereNull('context_key')
                            ->whereNull('family_key')
                            ->whereNull('message_template_id');
                    });

                    if ($familyKey !== null) {
                        $query->orWhere(function (Builder $query) use ($clientKey, $familyKey): void {
                            $query->where('scope_type', MessageTemplateCompositionLayer::SCOPE_FAMILY)
                                ->where('client_key', $clientKey)
                                ->whereNull('context_key')
                                ->where('family_key', $familyKey)
                                ->whereNull('message_template_id');
                        });
                    }

                    if ($contextKey !== null) {
                        $query->orWhere(function (Builder $query) use ($clientKey, $contextKey): void {
                            $query->where('scope_type', MessageTemplateCompositionLayer::SCOPE_CONTEXT)
                                ->where('client_key', $clientKey)
                                ->where('context_key', $contextKey)
                                ->whereNull('family_key')
                                ->whereNull('message_template_id');
                        });

                        if ($familyKey !== null) {
                            $query->orWhere(function (Builder $query) use ($clientKey, $contextKey, $familyKey): void {
                                $query->where('scope_type', MessageTemplateCompositionLayer::SCOPE_CONTEXT_FAMILY)
                                    ->where('client_key', $clientKey)
                                    ->where('context_key', $contextKey)
                                    ->where('family_key', $familyKey)
                                    ->whereNull('message_template_id');
                            });
                        }
                    }
                }

                $query->orWhere(function (Builder $query) use ($messageTemplate): void {
                    $query->where('scope_type', MessageTemplateCompositionLayer::SCOPE_MESSAGE)
                        ->where('message_template_id', $messageTemplate->getKey())
                        ->whereNull('client_key')
                        ->whereNull('context_key')
                        ->whereNull('family_key');
                });
            });

        return $query->get()->keyBy('scope_type');
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overlay
     * @return array<string, mixed>
     */
    private function overlay(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if ($value === null) {
                unset($base[$key]);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    private function segment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }

    private function nullableSegment(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->segment($value);
    }
}