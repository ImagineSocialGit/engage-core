<?php

namespace App\Jobs\Webinars;

use App\Actions\Leads\UpsertLeadFromRegistration;
use App\Models\WebinarRegistration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWebinarRegistration implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $registrationId
    ) {
        $this->onQueue('webinars');
    }

    public function handle(UpsertLeadFromRegistration $upsertLeadFromRegistration): void
    {
        $registration = WebinarRegistration::find($this->registrationId);

        if (! $registration) {
            return;
        }

        $upsertLeadFromRegistration->handle($registration);
    }
}