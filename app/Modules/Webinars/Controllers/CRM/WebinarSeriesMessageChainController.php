<?php

namespace App\Modules\Webinars\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Webinars\Actions\DuplicateWebinarSeriesMessageChainsAction;
use App\Modules\Webinars\Actions\UpdateWebinarSeriesMessageTemplateAction;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarSeriesMessageChainBinding;
use App\Modules\Webinars\Requests\UpdateWebinarSeriesMessageTemplateRequest;
use App\Modules\Webinars\Services\WebinarMessageAreaRegistry;
use App\Modules\Webinars\Services\WebinarScheduleProfileResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use LogicException;
use RuntimeException;

class WebinarSeriesMessageChainController extends Controller
{
    public function show(
        WebinarSeries $series,
        WebinarScheduleProfileResolver $scheduleProfileResolver,
        WebinarMessageAreaRegistry $messageAreaRegistry,
    ): View {
        $series->load([
            'webinarScheduleProfile',
            'messageChainBindings' => fn ($query) => $query
                ->active()
                ->with([
                    'messageChain.currentVersion.steps.variants.messageTemplateVersion.messageTemplate',
                ]),
        ]);
        $bindings = $series->messageChainBindings
            ->filter(fn (WebinarSeriesMessageChainBinding $binding): bool =>
                $binding->is_active
            )
            ->values();

        return view('crm.webinars.message-chains.show', [
            'title' => $series->title.' Messages',
            'heading' => $series->title.' Messages',
            'series' => $series,
            'scheduleProfile' => $scheduleProfileResolver->resolveForSeries($series),
            'sourceSeriesOptions' => WebinarSeries::query()
                ->where('id', '!=', $series->getKey())
                ->orderBy('title')
                ->get(),
            'bindings' => $bindings,
            'chains' => $this->editorChains(
                bindings: $bindings,
                messageAreaRegistry: $messageAreaRegistry,
            ),
        ]);
    }

    public function duplicate(
        Request $request,
        WebinarSeries $series,
        DuplicateWebinarSeriesMessageChainsAction $duplicateMessageChains,
    ): RedirectResponse {
        $validated = $request->validate([
            'source_webinar_series_id' => [
                'nullable',
                'integer',
                'exists:webinar_series,id',
            ],
        ]);
        $sourceSeriesId = (int) ($validated['source_webinar_series_id'] ?? 0);

        if ($sourceSeriesId > 0 && $sourceSeriesId === (int) $series->getKey()) {
            return redirect()
                ->route('crm.webinar-series.message-chains.show', $series)
                ->with('error', 'Choose a different Webinar series as the message source.');
        }

        $sourceSeries = $sourceSeriesId > 0
            ? WebinarSeries::query()->findOrFail($sourceSeriesId)
            : null;
        $actor = $request->user();

        try {
            $duplicateMessageChains->handle(
                targetSeries: $series,
                sourceSeries: $sourceSeries,
                createdBy: $actor instanceof User ? $actor : null,
            );
        } catch (LogicException|RuntimeException $exception) {
            return redirect()
                ->route('crm.webinar-series.message-chains.show', $series)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('crm.webinar-series.message-chains.show', $series)
            ->with(
                'success',
                $sourceSeries instanceof WebinarSeries
                    ? "Custom message chains duplicated from {$sourceSeries->title}."
                    : 'Custom message chains created from the effective schedule profile.',
            );
    }

    public function updateVariant(
        UpdateWebinarSeriesMessageTemplateRequest $request,
        WebinarSeries $series,
        MessageChainStepVariant $variant,
        UpdateWebinarSeriesMessageTemplateAction $updateMessageTemplate,
    ): RedirectResponse {
        $actor = $request->user();

        $updateMessageTemplate->handle(
            series: $series,
            variant: $variant,
            payload: $request->safePayload(),
            createdBy: $actor instanceof User ? $actor : null,
        );

        return redirect()
            ->route('crm.webinar-series.message-chains.show', $series)
            ->with('success', 'Series message copy updated and a new immutable chain version was published.');
    }

    /**
     * @param Collection<int, WebinarSeriesMessageChainBinding> $bindings
     * @return Collection<int, array<string, mixed>>
     */
    private function editorChains(
        Collection $bindings,
        WebinarMessageAreaRegistry $messageAreaRegistry,
    ): Collection {
        return $bindings
            ->groupBy('message_chain_id')
            ->map(function (Collection $chainBindings) use (
                $messageAreaRegistry,
            ): ?array {
                $firstBinding = $chainBindings->first();
                $chain = $firstBinding?->messageChain;

                if (! $chain instanceof MessageChain) {
                    return null;
                }

                $version = $chain->currentVersion;

                if (! $version) {
                    return null;
                }

                $version->loadMissing(
                    'steps.variants.messageTemplateVersion.messageTemplate',
                );
                $areas = $chainBindings
                    ->map(fn (WebinarSeriesMessageChainBinding $binding): ?string =>
                        $messageAreaRegistry->get($binding->message_area_key)?->label
                    )
                    ->filter()
                    ->unique()
                    ->values();

                return [
                    'id' => $chain->getKey(),
                    'key' => $chain->key,
                    'name' => $chain->name,
                    'description' => $chain->description,
                    'version' => $version->version,
                    'areas' => $areas,
                    'steps' => $version->steps
                        ->filter(fn (MessageChainStep $step): bool =>
                            (bool) $step->is_active
                        )
                        ->map(fn (MessageChainStep $step): array => [
                            'id' => $step->getKey(),
                            'key' => $step->key,
                            'name' => $step->name ?: Str::headline($step->key),
                            'timing' => $this->timingLabel($step),
                            'variants' => $step->variants
                                ->filter(fn (MessageChainStepVariant $variant): bool =>
                                    (bool) $variant->is_active
                                )
                                ->map(fn (MessageChainStepVariant $variant): array =>
                                    $this->editorVariant($variant)
                                )
                                ->values(),
                        ])
                        ->values(),
                ];
            })
            ->filter()
            ->sortBy('name')
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function editorVariant(
        MessageChainStepVariant $variant,
    ): array {
        $version = $variant->messageTemplateVersion;

        if (! $version instanceof MessageTemplateVersion) {
            throw new RuntimeException(
                "MessageChainStepVariant [{$variant->getKey()}] has no immutable template version.",
            );
        }

        return [
            'id' => $variant->getKey(),
            'key' => $variant->key,
            'channel' => $variant->channel,
            'purpose' => $variant->purpose,
            'scope' => $variant->scope,
            'message_type' => $variant->message_type,
            'template_name' => $version->messageTemplate?->name
                ?? Str::headline($variant->message_type),
            'template_version' => $version->version,
            'payload' => $version->payload(),
        ];
    }

    private function timingLabel(MessageChainStep $step): string
    {
        return match ($step->timing_type) {
            MessageChainStep::TIMING_IMMEDIATE => 'Immediate',
            MessageChainStep::TIMING_DELAY => $this->durationLabel(
                (int) $step->offset_seconds,
            ).' after enrollment',
            MessageChainStep::TIMING_ANCHORED => $this->anchoredTimingLabel(
                (int) $step->offset_seconds,
            ),
            MessageChainStep::TIMING_NEXT_DAY_AT => sprintf(
                'Day %+d at %s',
                (int) $step->day_offset,
                substr((string) $step->local_time, 0, 5),
            ),
            default => Str::headline((string) $step->timing_type),
        };
    }

    private function anchoredTimingLabel(int $seconds): string
    {
        if ($seconds === 0) {
            return 'At webinar start';
        }

        return $this->durationLabel(abs($seconds))
            .($seconds < 0 ? ' before webinar start' : ' after webinar start');
    }

    private function durationLabel(int $seconds): string
    {
        $minutes = max(0, (int) round($seconds / 60));

        if ($minutes % 1440 === 0 && $minutes >= 1440) {
            $days = (int) ($minutes / 1440);

            return $days.' '.Str::plural('day', $days);
        }

        if ($minutes % 60 === 0 && $minutes >= 60) {
            $hours = (int) ($minutes / 60);

            return $hours.' '.Str::plural('hour', $hours);
        }

        return $minutes.' '.Str::plural('minute', $minutes);
    }
}