<?php

namespace App\Jobs\Webinars\PostEvent;

use App\Actions\Webinars\PostEvent\DispatchWebinarOutcomeMessagesAction;
use App\Models\WebinarRegistration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RoutePostWebinarRegistrationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $registrationId,
        public ?string $event = null,
    ) {}

    public function handle(DispatchWebinarOutcomeMessagesAction $dispatchWebinarOutcomeMessagesAction): void
    {
        $registration = WebinarRegistration::query()
            ->with([
                'contact',
                'webinar',
                'webinar.webinarSeries',
            ])
            ->find($this->registrationId);

        if (! $registration || ! $registration->webinar) {
            return;
        }

        $dispatchWebinarOutcomeMessagesAction->handle(
            registration: $registration,
            event: $this->event,
        );
    }
}