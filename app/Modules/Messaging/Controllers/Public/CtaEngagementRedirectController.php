<?php

namespace App\Modules\Messaging\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Actions\RecordScheduledMessageCtaEngagementAction;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\CtaTrackingRequestClassifier;
use App\Modules\Messaging\Support\CtaTrackingLinkGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CtaEngagementRedirectController extends Controller
{
    public function __invoke(
        Request $request,
        ScheduledMessage $message,
        string $cta,
        CtaTrackingRequestClassifier $classifier,
        RecordScheduledMessageCtaEngagementAction $recordEngagement,
    ): RedirectResponse {
        $destination = $request->query('destination');

        abort_unless(
            CtaTrackingLinkGenerator::isValidTrackingKey($cta)
            && CtaTrackingLinkGenerator::isTrackableDestination($destination),
            404,
        );

        $recordEngagement->handle(
            scheduledMessage: $message,
            ctaKey: $cta,
            classification: $classifier->classify($request),
        );

        return redirect()->away(trim((string) $destination));
    }
}