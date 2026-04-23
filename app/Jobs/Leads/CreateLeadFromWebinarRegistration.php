<?php

namespace App\Jobs\Leads;

use App\Models\Lead;
use App\Models\WebinarRegistration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CreateLeadFromWebinarRegistration implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $registrationId
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $registration = WebinarRegistration::find($this->registrationId);

        if (! $registration) {
            return;
        }

        $lead = Lead::firstOrCreate(
            ['email' => $registration->email],
            [
                'first_name' => $registration->first_name,
                'last_name' => $registration->last_name,
                'name' => $registration->name,
                'phone' => $registration->phone,
                'status' => 'new',
                'source' => 'webinar',
                'subsource' => $registration->webinar_slug,
            ]
        );

        if (! $registration->lead_id) {
            $registration->update([
                'lead_id' => $lead->id,
                'status' => 'registered',
            ]);
        }
    }
}