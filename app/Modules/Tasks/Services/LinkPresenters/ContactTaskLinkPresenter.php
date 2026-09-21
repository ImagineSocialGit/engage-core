<?php

namespace App\Modules\Tasks\Services\LinkPresenters;

use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Contracts\TaskLinkPresenterContract;
use Illuminate\Database\Eloquent\Model;

class ContactTaskLinkPresenter implements TaskLinkPresenterContract
{
    public function supports(Model $linkable): bool
    {
        return $linkable instanceof Contact;
    }

    /**
     * @return array{
     *     record: Model,
     *     type: string,
     *     label: string,
     *     name: string,
     *     url: ?string,
     *     details: array<string, string>
     * }
     */
    public function present(Model $linkable): array
    {
        $linkable->loadMissing('tags');

        $status = null;

        if (module_enabled('workflow')) {
            $linkable->loadMissing('workflowProfile.contactStatus');
            $status = $linkable->workflowProfile?->contactStatus;
        }

        $details = [
            ...($status !== null ? ['Status' => $status->name ?: 'No status'] : []),
            'Tags' => $linkable->tags->pluck('tag')->filter()->join(', ') ?: 'No tags',
            'Email' => $linkable->email ?: '—',
            'Phone' => $linkable->phone ?: '—',
        ];

        return [
            'record' => $linkable,
            'type' => $linkable->getMorphClass(),
            'kind' => 'contact',
            'label' => config('contacts.labels.singular', 'Contact'),
            'name' => $this->contactName($linkable),
            'url' => route('crm.contacts.show', $linkable),
            'details' => $details,
        ];
    }

    private function contactName(Contact $contact): string
    {
        $name = trim((string) ($contact->name ?: trim(
            trim((string) $contact->first_name).' '.trim((string) $contact->last_name)
        )));

        return $name !== ''
            ? $name
            : ($contact->email ?: 'Contact #'.$contact->getKey());
    }
}