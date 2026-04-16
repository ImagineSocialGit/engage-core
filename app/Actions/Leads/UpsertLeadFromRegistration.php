<?php

namespace App\Actions\Leads;

use App\Models\Lead;
use App\Models\WebinarRegistration;

class UpsertLeadFromRegistration
{
    public function handle(WebinarRegistration $registration): Lead
    {
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
                'notes' => $registration->notes,
            ]
        );

        if (! $registration->lead_id) {
            $registration->update([
                'lead_id' => $lead->id,
                'status' => 'registered',
            ]);
        }

        return $lead;
    }
}