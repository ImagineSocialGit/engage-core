<?php

namespace App\Actions\CRM\Contacts;

use App\Models\Contact;
use App\Models\ContactStatus;

class CreateOrUpdateContactAction
{
    public function __construct(
        private readonly ResolveContactStatusAction $resolveContactStatus,
        private readonly UpdateContactStatusAction $updateContactStatus,
    ) {}

    public function handle(
        array $data,
        ?string $statusKey = null,
        ?string $statusChangeReason = null,
    ): Contact {
        $email = strtolower(trim((string) $data['email']));

        $targetStatusId = $data['contact_status_id'] ?? null;

        if ($targetStatusId === null && $statusKey !== null) {
            $targetStatusId = $this->resolveContactStatus->handle($statusKey)?->id;
        }

        $contact = Contact::query()->firstOrNew([
            'email' => $email,
        ]);

        $contact->fill([
            'first_name' => $data['first_name'] ?? $contact->first_name,
            'last_name' => $data['last_name'] ?? $contact->last_name,
            'name' => $data['name'] ?? $contact->name ?? $this->buildName($data, $email),
            'phone' => $data['phone'] ?? $contact->phone,
            'source' => $data['source'] ?? $contact->source ?? 'crm',
            'subsource' => $data['subsource'] ?? $contact->subsource,
            'last_contacted_at' => $data['last_contacted_at'] ?? $contact->last_contacted_at,
            'last_activity_at' => $data['last_activity_at'] ?? $contact->last_activity_at,
            'meta' => $data['meta'] ?? $contact->meta,
        ]);

        $contact->save();

        if ($targetStatusId !== null) {
            $status = $this->resolveContactStatusById($targetStatusId);

            if ($status !== null) {
                return $this->updateContactStatus->handle(
                    contact: $contact,
                    status: $status,
                    reason: $statusChangeReason,
                );
            }
        }

        return $contact->refresh();
    }

    private function buildName(array $data, string $email): string
    {
        $name = trim(collect([
            $data['first_name'] ?? null,
            $data['last_name'] ?? null,
        ])->filter()->implode(' '));

        return $name !== '' ? $name : $email;
    }

    private function resolveContactStatusById(int|string $statusId): ?ContactStatus
    {
        return ContactStatus::query()
            ->whereKey($statusId)
            ->where('is_active', true)
            ->first();
    }
}