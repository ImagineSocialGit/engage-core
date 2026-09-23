<?php

namespace App\Modules\Webinars\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Webinars\Actions\CreateWebinarWaitlistSignupAction;
use App\Modules\Webinars\Actions\ResolvePublicWebinarSeriesVariantAction;
use App\Modules\Webinars\Requests\StoreWebinarWaitlistSignupRequest;
use Illuminate\Http\RedirectResponse;

class WebinarWaitlistSignupController extends Controller
{
    public function __invoke(
        StoreWebinarWaitlistSignupRequest $request,
        string $seriesSlug,
        ResolvePublicWebinarSeriesVariantAction $resolvePublicVariant,
        CreateWebinarWaitlistSignupAction $createWebinarWaitlistSignupAction,
    ): RedirectResponse {
        $variant = $resolvePublicVariant->findByPublicSlug($seriesSlug);
        $series = $variant?->webinarSeries;

        abort_unless($variant && $series, 404);

        $createWebinarWaitlistSignupAction->handle(
            validated: $request->validated(),
            request: $request,
            series: $series,
            acceptedChannels: $request->acceptedMarketingChannels(),
            variant: $variant,
        );

        return redirect()
            ->route('webinar.show', $seriesSlug)
            ->with('webinar_waitlist_success', true)
            ->with(
                'success',
                config(
                    'webinars.notify-me.content.success.message',
                    'You’re on the list. We’ll let you know when the next webinar is scheduled.'
                )
            );
    }
}