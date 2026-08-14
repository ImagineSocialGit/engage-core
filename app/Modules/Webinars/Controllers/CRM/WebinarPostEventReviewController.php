<?php

namespace App\Modules\Webinars\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Webinars\Actions\PostEvent\DispatchPostWebinarFollowUpsAction;
use App\Modules\Webinars\Actions\PostEvent\ReviewWebinarPostEventAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Services\WebinarProviderManager;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class WebinarPostEventReviewController extends Controller
{
    public function show(Webinar $webinar): View
    {
        $webinar->loadMissing('webinarSeries');

        $alternateWebinars = Webinar::query()
            ->where('webinar_series_id', $webinar->webinar_series_id)
            ->where('id', '!=', $webinar->getKey())
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->orderByDesc('ends_at')
            ->limit(20)
            ->get();

        return view('crm.webinars.post-event-review', [
            'title' => 'Review Webinar Follow-ups',
            'heading' => 'Review Webinar Follow-ups',
            'webinar' => $webinar,
            'review' => $this->reviewState($webinar),
            'alternateWebinars' => $alternateWebinars,
            'attendedCount' => $webinar->registrations()->whereNotNull('attended_at')->count(),
            'missedCount' => $webinar->registrations()->where('status', 'missed')->count(),
            'attendanceReady' => ! config('webinars.post_event.attendance.enabled', false)
                || filled(data_get($webinar->meta, 'normalized.post_event.attendance_recorded_at')),
        ]);
    }

    public function update(
        Request $request,
        Webinar $webinar,
        ReviewWebinarPostEventAction $reviewPostEvent,
        DispatchPostWebinarFollowUpsAction $dispatchFollowUps,
        WebinarProviderManager $providerManager,
    ): RedirectResponse {
        $validated = $request->validate([
            'playback_mode' => [
                'required',
                Rule::in([
                    ReviewWebinarPostEventAction::MODE_CURRENT,
                    ReviewWebinarPostEventAction::MODE_ALTERNATE,
                    ReviewWebinarPostEventAction::MODE_NONE,
                ]),
            ],
            'alternate_webinar_id' => ['nullable', 'integer'],
        ]);

        $alternate = null;

        if ($validated['playback_mode'] === ReviewWebinarPostEventAction::MODE_ALTERNATE) {
            $alternate = Webinar::query()->find($validated['alternate_webinar_id'] ?? null);
        }

        try {
            $webinar = $reviewPostEvent->handle(
                webinar: $webinar,
                playbackMode: $validated['playback_mode'],
                alternateWebinar: $alternate,
                reviewedByUserId: $request->user()?->getKey(),
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'playback_mode' => $exception->getMessage(),
            ]);
        }

        $provider = $providerManager->forWebinar($webinar);
        $dispatchFollowUps->execute(
            provider: $provider,
            webinar: $webinar,
            event: 'operator.post_event_review',
        );

        $message = $validated['playback_mode'] === ReviewWebinarPostEventAction::MODE_NONE
            ? 'Replay follow-ups were suppressed for this webinar.'
            : 'The replay was approved and eligible follow-ups are ready to continue.';

        return redirect()
            ->route('crm.webinars.post-event-review.show', $webinar)
            ->with('success', $message);
    }

    /** @return array<string, mixed> */
    private function reviewState(Webinar $webinar): array
    {
        $review = data_get($webinar->meta, 'normalized.post_event.review', []);

        return is_array($review) ? $review : [];
    }
}