<?php

namespace App\Modules\Webinars\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Webinars\Actions\DuplicateWebinarSeriesMessageChainsAction;
use App\Modules\Webinars\Actions\UpdateWebinarSeriesMessageTemplateAction;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarSeriesMessageChainBinding;
use App\Modules\Webinars\Requests\UpdateWebinarSeriesMessageTemplateRequest;
use App\Modules\Webinars\Services\WebinarMessageChainPresentationService;
use App\Modules\Webinars\Services\WebinarScheduleProfileResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use LogicException;
use RuntimeException;

class WebinarSeriesMessageChainController extends Controller
{
    public function show(
        WebinarSeries $series,
        WebinarScheduleProfileResolver $scheduleProfileResolver,
        WebinarMessageChainPresentationService $messageChainPresentation,
    ): View {
        $series->load([
            'webinarScheduleProfile',
            'messageChainBindings' => fn ($query) => $query->active(),
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
            'messageReview' => $messageChainPresentation->forSeries($series),
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
}