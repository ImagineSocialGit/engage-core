<?php

namespace App\Modules\Webinars\Services\ContactPanels;

use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Core\Contracts\Contacts\ContactPanelProvider;
use App\Modules\Core\Data\Contacts\ContactPanel;

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