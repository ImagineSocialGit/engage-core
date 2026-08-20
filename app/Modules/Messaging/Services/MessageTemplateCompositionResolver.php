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
     * Resolve bounded authoring composition into one complete publishable payload.
     *
     * Runtime sending never calls this resolver. MessageTemplateVersion remains
     * the immutable, fully-resolved runtime contract.
     *
     * @param array<string, mixed> $sourcePayload
     * @return array<string, mixed>
     */
    public function resolve(
        MessageTemplate $messageTemplate,
        array $sourcePayload,
        ?string $clientKey = null,
    ): array {
        $channel = $this->segment((string) $messageTemplate->channel);
        $clientKey = $this->nullableSegment(
            $clientKey ?? config('client.key'),
        );
        $contextKey = $this->nullableSegment(
            $messageTemplate->composition_context_key,
        );
        $familyKey = $this->nullableSegment(
            $messageTemplate->composition_family_key,
        );

        $layers = $this->matchingLayers(
            messageTemplate: $messageTemplate,
            channel: $channel,
            clientKey: $clientKey,
            contextKey: $contextKey,
            familyKey: $familyKey,
        );

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

        // Current config/preset payload is the authoritative source definition.
        // As clients are deliberately migrated to composition, inherited fields
        // are removed from source config so shared layers can supply them.
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
            ->where(function (Builder $query) use (
                $messageTemplate,
                $clientKey,
                $contextKey,
                $familyKey,
            ): void {
                $query->where(function (Builder $query): void {
                    $query
                        ->where('scope_type', MessageTemplateCompositionLayer::SCOPE_PLATFORM)
                        ->whereNull('client_key')
                        ->whereNull('context_key')
                        ->whereNull('family_key')
                        ->whereNull('message_template_id');
                });

                if ($clientKey !== null) {
                    $query->orWhere(function (Builder $query) use ($clientKey): void {
                        $query
                            ->where('scope_type', MessageTemplateCompositionLayer::SCOPE_CLIENT)
                            ->where('client_key', $clientKey)
                            ->whereNull('context_key')
                            ->whereNull('family_key')
                            ->whereNull('message_template_id');
                    });

                    if ($familyKey !== null) {
                        $query->orWhere(function (Builder $query) use ($clientKey, $familyKey): void {
                            $query
                                ->where('scope_type', MessageTemplateCompositionLayer::SCOPE_FAMILY)
                                ->where('client_key', $clientKey)
                                ->whereNull('context_key')
                                ->where('family_key', $familyKey)
                                ->whereNull('message_template_id');
                        });
                    }

                    if ($contextKey !== null) {
                        $query->orWhere(function (Builder $query) use ($clientKey, $contextKey): void {
                            $query
                                ->where('scope_type', MessageTemplateCompositionLayer::SCOPE_CONTEXT)
                                ->where('client_key', $clientKey)
                                ->where('context_key', $contextKey)
                                ->whereNull('family_key')
                                ->whereNull('message_template_id');
                        });

                        if ($familyKey !== null) {
                            $query->orWhere(function (Builder $query) use ($clientKey, $contextKey, $familyKey): void {
                                $query
                                    ->where('scope_type', MessageTemplateCompositionLayer::SCOPE_CONTEXT_FAMILY)
                                    ->where('client_key', $clientKey)
                                    ->where('context_key', $contextKey)
                                    ->where('family_key', $familyKey)
                                    ->whereNull('message_template_id');
                            });
                        }
                    }
                }

                $query->orWhere(function (Builder $query) use ($messageTemplate): void {
                    $query
                        ->where('scope_type', MessageTemplateCompositionLayer::SCOPE_MESSAGE)
                        ->where('message_template_id', $messageTemplate->getKey())
                        ->whereNull('client_key')
                        ->whereNull('context_key')
                        ->whereNull('family_key');
                });
            });

        return $query->get()->keyBy('scope_type');
    }

    /**
     * Top-level fields are intentional semantic units. Arrays such as CTA lists
     * replace as a unit; composition never recursively merges arbitrary content.
     *
     * A null value explicitly removes an inherited field.
     *
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