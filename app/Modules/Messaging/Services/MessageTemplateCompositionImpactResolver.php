<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCompositionLayer;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Support\Collection;

class MessageTemplateCompositionImpactResolver
{
    public function __construct(
        private readonly MessageTemplateCompositionResolver $compositionResolver,
    ) {}

    /**
     * @return Collection<int, array{template: MessageTemplate, preset: MessageTemplatePreset}>
     */
    public function templatesUsingLayer(MessageTemplateCompositionLayer $layer): Collection
    {
        return $this->candidates($layer)
            ->filter(function (array $item) use ($layer): bool {
                $sourcePayload = is_array($item['preset']->payload) ? $item['preset']->payload : [];

                return $this->compositionResolver->resolve(
                    $item['template'],
                    $sourcePayload,
                ) !== $this->compositionResolver->resolveWithoutLayer(
                    $item['template'],
                    $sourcePayload,
                    $layer,
                );
            })
            ->values();
    }

    /**
     * @param array<string, mixed> $proposedPayload
     * @return Collection<int, array{template: MessageTemplate, preset: MessageTemplatePreset}>
     */
    public function templatesChangedByProposedPayload(
        MessageTemplateCompositionLayer $layer,
        array $proposedPayload,
    ): Collection {
        return $this->candidates($layer)
            ->filter(function (array $item) use ($layer, $proposedPayload): bool {
                $sourcePayload = is_array($item['preset']->payload) ? $item['preset']->payload : [];
                $current = $this->compositionResolver->resolve($item['template'], $sourcePayload);
                $proposed = $this->compositionResolver->resolveWithLayerPayload(
                    $item['template'],
                    $sourcePayload,
                    $layer,
                    $proposedPayload,
                );

                return $current !== $proposed;
            })
            ->values();
    }

    /**
     * @return Collection<int, array{template: MessageTemplate, preset: MessageTemplatePreset}>
     */
    private function candidates(MessageTemplateCompositionLayer $layer): Collection
    {
        $clientKey = $this->normalize(config('client.key'));

        if ($layer->client_key !== null && $this->normalize($layer->client_key) !== $clientKey) {
            return collect();
        }

        $query = MessageTemplate::query()
            ->where('channel', $layer->channel)
            ->whereIn('key', MessageTemplatePreset::query()->active()->select('key'));

        match ($layer->scope_type) {
            MessageTemplateCompositionLayer::SCOPE_FAMILY => $query
                ->where('composition_family_key', $layer->family_key),
            MessageTemplateCompositionLayer::SCOPE_CONTEXT => $query
                ->where('composition_context_key', $layer->context_key),
            MessageTemplateCompositionLayer::SCOPE_CONTEXT_FAMILY => $query
                ->where('composition_context_key', $layer->context_key)
                ->where('composition_family_key', $layer->family_key),
            MessageTemplateCompositionLayer::SCOPE_MESSAGE => $query
                ->whereKey($layer->message_template_id),
            default => $query,
        };

        $templates = $query->orderBy('key')->get();
        $presets = MessageTemplatePreset::query()
            ->active()
            ->whereIn('key', $templates->pluck('key')->all())
            ->get()
            ->keyBy('key');

        return $templates
            ->map(function (MessageTemplate $template) use ($presets): ?array {
                $preset = $presets->get($template->key);

                return $preset instanceof MessageTemplatePreset
                    ? ['template' => $template, 'preset' => $preset]
                    : null;
            })
            ->filter()
            ->values();
    }

    private function normalize(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return str_replace('-', '_', strtolower(trim($value)));
    }
}