<?php

namespace App\Services\CRM\Tasks;

use App\Models\Contact;
use App\Models\Task;

class TaskRelatedSubjectResolver
{
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
    public function resolve(Task $task): array
    {
        $task->loadMissing('related');

        $related = $task->related;

        if ($related instanceof Contact) {
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

        return [
            'subject' => null,
            'type' => null,
            'label' => 'Related Record',
            'name' => '—',
            'url' => null,
            'details' => [],
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