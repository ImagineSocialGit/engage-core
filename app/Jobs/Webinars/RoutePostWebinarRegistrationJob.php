<?php

namespace App\Jobs\Webinars;

use App\Actions\Webinars\DispatchWebinarOutcomeMessagesAction;
use App\Models\WebinarRegistration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RoutePostWebinarRegistrationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $registrationId
    ) {}

    public function handle(DispatchWebinarOutcomeMessagesAction $dispatchWebinarOutcomeMessagesAction): void
    {
        $registration = WebinarRegistration::query()
            ->with('webinar')
            ->find($this->registrationId);

        if (! $registration || ! $registration->webinar) {
            return;
        }

        $dispatchWebinarOutcomeMessagesAction->handle($registration);
    }
}
