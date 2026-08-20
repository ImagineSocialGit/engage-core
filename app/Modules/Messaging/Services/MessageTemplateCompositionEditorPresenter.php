<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCompositionLayer;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MessageTemplateCompositionEditorPresenter
{
    public function __construct(
        private readonly MessageTemplateCompositionResolver $compositionResolver,
        private readonly MessageTemplateCompositionImpactResolver $impactResolver,
    ) {}

    /**
     * @return array{
     *   effective_payload: array<string,mixed>,
     *   baseline_payload: array<string,mixed>,
     *   message_override: ?MessageTemplateCompositionLayer,
     *   shared_layers: Collection<int,array<string,mixed>>,
     *   field_sources: array<string,array{label:string,scope_type:string}>
     * }
     */
    public function forTemplate(
        MessageTemplate $template,
        MessageTemplatePreset $preset,
    ): array {
        $sourcePayload = is_array($preset->payload) ? $preset->payload : [];
        $layers = $this->compositionResolver->applicableLayers($template);
        $effectivePayload = $this->compositionResolver->resolve($template, $sourcePayload);
        $baselinePayload = $this->compositionResolver->resolveWithoutMessageOverride($template, $sourcePayload);
        $messageOverride = $layers->get(MessageTemplateCompositionLayer::SCOPE_MESSAGE);

        $sharedLayers = $layers
            ->except(MessageTemplateCompositionLayer::SCOPE_MESSAGE)
            ->map(function (MessageTemplateCompositionLayer $layer): array {
                return [
                    'layer' => $layer,
                    'label' => $this->layerLabel($layer),
                    'field_labels' => collect(array_keys(is_array($layer->payload) ? $layer->payload : []))
                        ->map(fn (string $field): string => $this->fieldLabel($field))
                        ->values()
                        ->all(),
                    'affected_count' => $this->impactResolver->templatesUsingLayer($layer)->count(),
                ];
            })
            ->values();

        return [
            'effective_payload' => $effectivePayload,
            'baseline_payload' => $baselinePayload,
            'message_override' => $messageOverride instanceof MessageTemplateCompositionLayer ? $messageOverride : null,
            'shared_layers' => $sharedLayers,
            'field_sources' => $this->fieldSources($template, $sourcePayload, $layers),
        ];
    }

    /**
     * @param array<string,mixed> $sourcePayload
     * @param Collection<string, MessageTemplateCompositionLayer> $layers
     * @return array<string,array{label:string,scope_type:string}>
     */
    private function fieldSources(
        MessageTemplate $template,
        array $sourcePayload,
        Collection $layers,
    ): array {
        $sources = [];

        foreach ([
            MessageTemplateCompositionLayer::SCOPE_PLATFORM,
            MessageTemplateCompositionLayer::SCOPE_CLIENT,
            MessageTemplateCompositionLayer::SCOPE_FAMILY,
            MessageTemplateCompositionLayer::SCOPE_CONTEXT,
            MessageTemplateCompositionLayer::SCOPE_CONTEXT_FAMILY,
        ] as $scopeType) {
            $layer = $layers->get($scopeType);

            if (! $layer instanceof MessageTemplateCompositionLayer) {
                continue;
            }

            foreach (array_keys(is_array($layer->payload) ? $layer->payload : []) as $field) {
                $sources[$field] = [
                    'label' => $this->layerLabel($layer),
                    'scope_type' => $scopeType,
                ];
            }
        }

        foreach (array_keys($sourcePayload) as $field) {
            $sources[$field] = [
                'label' => 'This message definition',
                'scope_type' => 'source',
            ];
        }

        $messageLayer = $layers->get(MessageTemplateCompositionLayer::SCOPE_MESSAGE);

        if ($messageLayer instanceof MessageTemplateCompositionLayer) {
            foreach (array_keys(is_array($messageLayer->payload) ? $messageLayer->payload : []) as $field) {
                $sources[$field] = [
                    'label' => 'Message override',
                    'scope_type' => MessageTemplateCompositionLayer::SCOPE_MESSAGE,
                ];
            }
        }

        return $sources;
    }

    private function layerLabel(MessageTemplateCompositionLayer $layer): string
    {
        return match ($layer->scope_type) {
            MessageTemplateCompositionLayer::SCOPE_PLATFORM => 'Platform shared content',
            MessageTemplateCompositionLayer::SCOPE_CLIENT => 'Client shared content',
            MessageTemplateCompositionLayer::SCOPE_FAMILY => 'Shared '.Str::headline((string) $layer->family_key).' content',
            MessageTemplateCompositionLayer::SCOPE_CONTEXT => Str::headline((string) $layer->context_key).' shared content',
            MessageTemplateCompositionLayer::SCOPE_CONTEXT_FAMILY => Str::headline((string) $layer->context_key).' · '.Str::headline((string) $layer->family_key),
            default => 'Shared content',
        };
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'cta' => 'Primary CTA',
            'ctas' => 'CTA set',
            'secondary_link' => 'Secondary link',
            default => Str::headline($field),
        };
    }
}