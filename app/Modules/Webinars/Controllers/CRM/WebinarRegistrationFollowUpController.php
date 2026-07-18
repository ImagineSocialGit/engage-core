<?php

namespace App\Modules\Webinars\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Webinars\Jobs\PostEvent\RetryWebinarRegistrationFollowUpJob;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Http\RedirectResponse;
use Throwable;

class WebinarRegistrationFollowUpController extends Controller
{
    public function __invoke(WebinarRegistration $registration): RedirectResponse
    {
        $status = data_get($registration->meta, 'post_event_follow_up.status');

        if (in_array($status, ['scheduled', 'not_applicable'], true)) {
            return back()->with(
                'success',
                'The post-webinar follow-up is already resolved.',
            );
        }

        if (! in_array($status, ['failed', 'planning'], true)) {
            return back()->with(
                'error',
                'This registration does not have a failed post-webinar follow-up to retry.',
            );
        }

        try {
            RetryWebinarRegistrationFollowUpJob::dispatch(
                (int) $registration->getKey(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->with(
                'error',
                'The post-webinar follow-up retry could not be queued.',
            );
        }

        return back()->with(
            'success',
            'The post-webinar follow-up retry has been queued.',
        );
    }
}