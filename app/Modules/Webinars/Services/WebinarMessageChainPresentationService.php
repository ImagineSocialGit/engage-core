<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Services\MessageChainPresentationService;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarSeriesMessageChainBinding;
use App\Modules\Webinars\Support\WebinarPlaybackLinkGenerator;
use Illuminate\Support\Collection;

class WebinarMessageChainPresentationService
{
    public function __construct(
        private readonly WebinarMessageChainBindingResolver $bindingResolver,
        private readonly WebinarMessageAreaRegistry $messageAreaRegistry,
        private readonly MessageChainPresentationService $messageChainPresentation,
        private readonly WebinarPlaybackLinkGenerator $playbackLinkGenerator,
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

        return $this->withOccurrencePreview(
            presentation: $this->presentationForBindings(
                bindings: $this->bindingResolver
                    ->effectiveBindingsForWebinar($webinar),
                series: $series,
            ),
            webinar: $webinar,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function forSeries(WebinarSeries $series): array
    {
        return $this->presentationForBindings(
            bindings: $this->bindingResolver->effectiveBindingsForSeries($series),
            series: $series,
        );
    }

    /**
     * @param Collection<int, mixed> $bindings
     * @return array<string, mixed>
     */
    private function presentationForBindings(
        Collection $bindings,
        WebinarSeries $series,
    ): array {
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
                        $message['update_action'] = route(
                            'crm.webinar-series.message-chains.variants.update',
                            [$series, $message['variant_id']],
                        );
                        $message['edit_note'] = $seriesOwned
                            ? 'Publishing creates a new immutable template and chain version for this Webinar series. Existing enrollments stay pinned to the version they already started with.'
                            : 'This series currently uses shared defaults. The first edit automatically creates a series-specific copy, then publishes the change without altering the shared default for other Webinar series.';
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
     * Resolve only values that are truthfully known from this Webinar occurrence.
     *
     * The published/editable template payload remains unchanged. This projection
     * is used only by the occurrence-specific preview, so recipient/registration
     * values such as first_name, webinar_join_url, and cancel_registration_url
     * remain visibly unresolved when no such context exists.
     *
     * @param array<string, mixed> $presentation
     * @return array<string, mixed>
     */
    private function withOccurrencePreview(
        array $presentation,
        Webinar $webinar,
    ): array {
        $replacements = $this->occurrenceTokenReplacements($webinar);

        foreach ($presentation['channels'] ?? [] as $channelKey => $channel) {
            foreach ($channel['messages'] ?? [] as $messageIndex => $message) {
                $sourcePayload = is_array($message['payload'] ?? null)
                    ? $message['payload']
                    : [];

                $message['edit_payload'] = $sourcePayload;
                $message['payload'] = $this->replaceKnownTokens(
                    value: $sourcePayload,
                    replacements: $replacements,
                );
                $channel['messages'][$messageIndex] = $message;
            }

            $presentation['channels'][$channelKey] = $channel;
        }

        return $presentation;
    }

    /**
     * @return array<string, string>
     */
    private function occurrenceTokenReplacements(Webinar $webinar): array
    {
        $timezone = trim((string) $webinar->timezone);

        if ($timezone === '') {
            $timezone = (string) config(
                'client.timezone',
                config('app.timezone', 'UTC'),
            );
        }

        $startsAt = $webinar->starts_at;
        $endsAt = $webinar->ends_at;
        $series = $webinar->webinarSeries;

        $values = [
            'webinar.id' => $webinar->getKey(),
            'webinar.title' => $webinar->title,
            'webinar.slug' => $webinar->slug,
            'webinar.platform' => $webinar->platform,
            'webinar.registration_url' => $webinar->registration_url,
            'webinar.starts_at' => $startsAt?->toIso8601String(),
            'webinar.ends_at' => $endsAt?->toIso8601String(),
            'webinar.timezone' => $timezone,
            'webinar.description' => $webinar->description,

            'webinar_title' => $webinar->title,
            'webinar_slug' => $webinar->slug,
            'webinar_timezone' => $timezone,
            'webinar_start_date' => $startsAt?->copy()
                ->setTimezone($timezone)
                ->format('F j, Y'),
            'webinar_start_time' => $startsAt?->copy()
                ->setTimezone($timezone)
                ->format('g:i A T'),
            'webinar_start_datetime' => $startsAt?->copy()
                ->setTimezone($timezone)
                ->format('F j, Y \a\t g:i A T'),
            'webinar_end_date' => $endsAt?->copy()
                ->setTimezone($timezone)
                ->format('F j, Y'),
            'webinar_end_time' => $endsAt?->copy()
                ->setTimezone($timezone)
                ->format('g:i A T'),
            'webinar_end_datetime' => $endsAt?->copy()
                ->setTimezone($timezone)
                ->format('F j, Y \a\t g:i A T'),

            'webinar_series.id' => $series?->getKey(),
            'webinar_series.title' => $series?->title,
            'webinar_series.slug' => $series?->slug,
            'webinar_series.status' => $series?->status,
        ];

        if (
            filled($webinar->playback_url)
            && filled($webinar->playback_token)
        ) {
            $values['webinar_playback_url'] =
                $this->playbackLinkGenerator->forWebinar($webinar);
        }

        $replacements = [];

        foreach ($values as $token => $value) {
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $replacements['{'.$token.'}'] = (string) $value;
        }

        return $replacements;
    }

    /**
     * @param array<string, string> $replacements
     */
    private function replaceKnownTokens(
        mixed $value,
        array $replacements,
    ): mixed {
        if (is_string($value)) {
            return strtr($value, $replacements);
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->replaceKnownTokens(
                value: $child,
                replacements: $replacements,
            );
        }

        return $value;
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