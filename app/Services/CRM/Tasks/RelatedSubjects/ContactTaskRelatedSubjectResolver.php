<?php

namespace App\Services\CRM\Tasks\RelatedSubjects;

use App\Contracts\CRM\Tasks\TaskRelatedSubjectResolverContract;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Model;

class ContactTaskRelatedSubjectResolver implements TaskRelatedSubjectResolverContract
{
    public function supports(Model $related): bool
    {
        return $related instanceof Contact;
    }

    /**
     * @return array{
     *     subject: object|null,
     *     type: ?string,
     *     label: string,
     *     name: string,
     *     url: ?string,
     *     details: array<string, string>
     * }
     */
    public function resolve(Model $related): array
    {
        if (! $related instanceof Contact) {
            return [
                'subject' => null,
                'type' => null,
                'label' => 'Related Record',
                'name' => '—',
                'url' => null,
                'details' => [],
            ];
        }

        return [
            'subject' => $related,
            'type' => Contact::class,
            'label' => config('contacts.labels.singular', 'Contact'),
            'name' => $this->contactName($related),
            'url' => route('crm.contacts.show', $related),
            'details' => [
                'Email' => $related->email ?: '—',
                'Phone' => $related->phone ?: '—',
            ],
        ];
    }

    private function contactName(Contact $contact): string
    {
        $name = trim((string) ($contact->name ?: trim(
            trim((string) $contact->first_name).' '.trim((string) $contact->last_name)
        )));

        return $name !== '' ? $name : ($contact->email ?: 'Contact #'.$contact->id);
    }
}