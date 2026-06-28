<?php

namespace App\Modules\Webinars\Jobs\PostEvent;

use App\Modules\Webinars\Actions\PostEvent\DispatchWebinarOutcomeMessagesAction;
use App\Modules\Webinars\Models\WebinarRegistration;
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
        $registration = WebinarRegistration::query()->find($this->registrationId);

        if (! $registration) {
            return;
        }

        $dispatchWebinarOutcomeMessagesAction->handle(
            registration: $registration,
            event: $this->event,
        );
    }
}