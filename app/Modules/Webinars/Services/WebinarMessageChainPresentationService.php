<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Services\MessageChainPresentationService;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarSeriesMessageChainBinding;
use Illuminate\Support\Collection;

class WebinarMessageChainPresentationService
{
    public function __construct(
        private readonly WebinarMessageChainBindingResolver $bindingResolver,
        private readonly WebinarMessageAreaRegistry $messageAreaRegistry,
        private readonly MessageChainPresentationService $messageChainPresentation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forWebinar(Webinar $webinar): array
    {
        $webinar->loadMissing('webinarSeries');
        $series = $webinar->webinarSeries;

        if (! $series instanceof WebinarSeries) {
            return $this->emptyPresentation();
        }

        return $this->forSeries($series);
    }

    /**
     * @return array<string, mixed>
     */
    public function forSeries(WebinarSeries $series): array
    {
        $bindings = $this->bindingResolver->effectiveBindingsForSeries($series);

        if ($bindings->isEmpty()) {
            return $this->emptyPresentation();
        }

        $chainGroups = $bindings
            ->filter(fn ($binding): bool => $binding->messageChain instanceof MessageChain)
            ->groupBy(fn ($binding): int => (int) $binding->message_chain_id);

        $presentedChains = $chainGroups
            ->map(function (Collection $chainBindings) use ($series): ?array {
                $firstBinding = $chainBindings->first();
                $chain = $firstBinding?->messageChain;

                if (! $chain instanceof MessageChain) {
                    return null;
                }

                $areas = $chainBindings
                    ->map(fn ($binding): ?string =>
                        $this->messageAreaRegistry->get(
                            (string) $binding->message_area_key,
                        )?->label
                    )
                    ->filter()
                    ->unique()
                    ->values();

                $seriesOwned = $chainBindings->every(
                    fn ($binding): bool =>
                        $binding instanceof WebinarSeriesMessageChainBinding,
                );
                $presentation = $this->messageChainPresentation->present(
                    messageChain: $chain,
                    anchorLabels: [
                        'webinar.starts_at' => 'webinar start',
                        'webinar.ends_at' => 'webinar end',
                    ],
                );

                $presentation['areas'] = $areas->all();
                $presentation['area_label'] = $areas->implode(' · ');
                $presentation['series_owned'] = $seriesOwned;

                foreach ($presentation['channels'] as &$channel) {
                    foreach ($channel['messages'] as &$message) {
                        $message['areas'] = $areas->all();
                        $message['area_label'] = $areas->implode(' · ');
                        $message['series_owned'] = $seriesOwned;
                        $message['update_action'] = $seriesOwned
                            ? route(
                                'crm.webinar-series.message-chains.variants.update',
                                [$series, $message['variant_id']],
                            )
                            : null;
                    }
                    unset($message);
                }
                unset($channel);

                return $presentation;
            })
            ->filter()
            ->values();

        $templateKeys = [];

        foreach ($presentedChains as $chain) {
            foreach ($chain['channels'] as $channel) {
                foreach ($channel['messages'] as $message) {
                    $templateKey = $message['template_key'] ?? null;

                    if (is_string($templateKey) && trim($templateKey) !== '') {
                        $templateKeys[] = $templateKey;
                    }
                }
            }
        }

        $templateEditLinks = $this->templateEditLinks(
            array_values(array_unique($templateKeys)),
        );

        $channels = [];

        foreach ($presentedChains as $chain) {
            foreach ($chain['channels'] as $channelKey => $channel) {
                if (! isset($channels[$channelKey])) {
                    $channels[$channelKey] = [
                        'key' => $channel['key'],
                        'label' => $channel['label'],
                        'messages' => [],
                    ];
                }

                foreach ($channel['messages'] as $message) {
                    $message['template_edit_url'] = is_string($message['template_key'] ?? null)
                        ? $templateEditLinks->get($message['template_key'])
                        : null;
                    $channels[$channelKey]['messages'][] = $message;
                }
            }
        }

        foreach ($channels as &$channel) {
            $channel['count'] = count($channel['messages']);
        }
        unset($channel);

        return [
            'message_count' => array_sum(array_map(
                static fn (array $channel): int => count($channel['messages']),
                $channels,
            )),
            'channels' => $channels,
            'chains' => $presentedChains->all(),
            'has_series_owned_messages' => $presentedChains->contains(
                fn (array $chain): bool => $chain['series_owned'] === true,
            ),
        ];
    }

    /**
     * @param array<int, string> $templateKeys
     * @return Collection<string, string>
     */
    private function templateEditLinks(array $templateKeys): Collection
    {
        if ($templateKeys === []) {
            return collect();
        }

        return MessageTemplatePreset::query()
            ->active()
            ->whereIn('key', $templateKeys)
            ->with([
                'catalogEntries' => fn ($query) => $query
                    ->active()
                    ->orderBy('item_order')
                    ->orderBy('item_label'),
            ])
            ->get()
            ->mapWithKeys(function (MessageTemplatePreset $preset): array {
                $entry = $preset->catalogEntries->first();

                if (! $entry instanceof MessageTemplateCatalogEntry) {
                    return [];
                }

                return [
                    $preset->key => route(
                        'crm.messaging.message-templates.index',
                        array_filter([
                            'channel' => $entry->channel ?: $preset->channel,
                            'purpose' => $entry->purpose ?: $preset->purpose,
                            'module' => $entry->module_key,
                            'group' => $entry->group_key,
                            'preset' => $preset->getKey(),
                        ], static fn (mixed $value): bool =>
                            $value !== null && $value !== ''
                        ),
                    ),
                ];
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPresentation(): array
    {
        return [
            'message_count' => 0,
            'channels' => [],
            'chains' => [],
            'has_series_owned_messages' => false,
        ];
    }
}