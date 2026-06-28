<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Webinars\Models\WebinarRegistration;

class DispatchWebinarOutcomeMessagesAction
{
    public function handle(WebinarRegistration $registration, ?string $event = null): void
    {
        //
        // Phase 18:
        // Webinar outcome handling no longer dispatches post-outcome messages directly.
        //
        // Outcomes are emitted where state is recorded:
        // - CreateWebinarRegistrationAction => webinar.registered
        // - CancelWebinarRegistrationAction => webinar.cancelled
        // - RecordWebinarAttendanceAction => webinar.attended / webinar.missed
        //
        // FlowRoutes consumes those through the generic AutomationEventRecorded seam.
        //
    }
}