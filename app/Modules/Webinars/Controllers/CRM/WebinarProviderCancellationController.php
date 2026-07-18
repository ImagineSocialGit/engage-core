<?php

namespace App\Modules\Webinars\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Webinars\Actions\QueueWebinarProviderCancellationAction;
use App\Modules\Webinars\Data\WebinarProviderCancellationResult;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Http\RedirectResponse;

class WebinarProviderCancellationController extends Controller
{
    public function __invoke(
        WebinarRegistration $registration,
        QueueWebinarProviderCancellationAction $queueProviderCancellation,
    ): RedirectResponse {
        $result = $queueProviderCancellation->handle($registration);

        if ($result->status === WebinarProviderCancellationResult::STATUS_NOT_CANCELLED) {
            return back()->with(
                'error',
                'Only cancelled webinar registrations can be reconciled with the provider.',
            );
        }

        if ($result->status === WebinarProviderCancellationResult::STATUS_FAILED) {
            return back()->with(
                'error',
                'The provider cancellation retry could not be queued. Try again after the queue connection is restored.',
            );
        }

        if (in_array($result->status, [
            WebinarProviderCancellationResult::STATUS_NOT_REQUIRED,
            WebinarProviderCancellationResult::STATUS_SUCCEEDED,
            WebinarProviderCancellationResult::STATUS_ALREADY_SUCCEEDED,
        ], true)) {
            return back()->with(
                'success',
                'The provider cancellation is already reconciled.',
            );
        }

        return back()->with(
            'success',
            'The provider cancellation retry has been queued.',
        );
    }
}
