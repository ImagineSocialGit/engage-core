<?php

namespace App\Services\CRM\Contacts\Panels;

use App\Contracts\CRM\Contacts\ContactPanelProvider;
use App\Data\CRM\Contacts\ContactPanel;
use App\Models\Contact;
use App\Models\WebinarRegistration;

class WebinarContactPanelProvider implements ContactPanelProvider
{
    public function panels(Contact $contact): array
    {
        $registrations = WebinarRegistration::query()
            ->with('webinar.webinarSeries')
            ->where('contact_id', $contact->id)
            ->latest('registered_at')
            ->latest('id')
            ->limit(5)
            ->get();

        return [
            new ContactPanel(
                key: 'webinar-history',
                title: 'Webinar History',
                view: 'crm.contacts.panels.webinar-history',
                data: [
                    'registrations' => $registrations,
                ],
                sort: 100,
            ),
        ];
    }
}