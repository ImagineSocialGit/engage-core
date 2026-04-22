<?php

namespace App\Jobs\Webinars;

use App\Actions\Webinars\ProcessWebinarOutcomeAction;
use App\Models\WebinarRegistration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RoutePostWebinarRegistrationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $registrationId
    ) {}

    public function handle(ProcessWebinarOutcomeAction $processWebinarOutcomeAction): void
    {
        $registration = WebinarRegistration::query()
            ->with('webinar')
            ->find($this->registrationId);

        if (! $registration || ! $registration->webinar) {
            return;
        }

        $processWebinarOutcomeAction->execute($registration);
    }
}