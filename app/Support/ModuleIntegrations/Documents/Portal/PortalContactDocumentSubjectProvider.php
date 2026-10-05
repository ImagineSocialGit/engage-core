<?php

namespace App\Support\ModuleIntegrations\Documents\Portal;

use App\Modules\Core\Models\Contact;
use App\Modules\Portal\Models\PortalContactLink;
use App\Modules\Portal\Models\PortalUser;
use App\Support\ModuleIntegrations\Documents\Portal\Contracts\PortalDocumentSubjectProvider;
use App\Support\ModuleIntegrations\Documents\Portal\Data\PortalDocumentSubject;

final class PortalContactDocumentSubjectProvider implements PortalDocumentSubjectProvider
{
    public function definitions(PortalUser $user): iterable
    {
        $links = $user->contactLinks()
            ->with('contact')
            ->where('status', PortalContactLink::STATUS_ACTIVE)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        foreach ($links as $link) {
            $contact = $link->contact;

            if (! $contact instanceof Contact) {
                continue;
            }

            yield new PortalDocumentSubject(
                subject: $contact,
                label: $this->contactLabel($contact),
                typeLabel: 'Contact',
            );
        }
    }

    private function contactLabel(Contact $contact): string
    {
        foreach ([$contact->name, $contact->email, 'Contact #'.$contact->getKey()] as $label) {
            if (is_string($label) && trim($label) !== '') {
                return trim($label);
            }
        }

        return 'Contact #'.$contact->getKey();
    }
}