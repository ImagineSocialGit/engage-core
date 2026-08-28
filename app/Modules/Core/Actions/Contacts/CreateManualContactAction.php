<?php

namespace App\Modules\Core\Actions\Contacts;

use App\Modules\Core\Events\ManualContactCreated;
use App\Modules\Core\Models\Contact;
use Illuminate\Support\Facades\DB;

final class CreateManualContactAction
{
    public function __construct(
        private readonly CreateOrUpdateContactAction $createOrUpdateContact,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function handle(
        array $data,
        ?string $statusKey = null,
        bool $existingRelationshipConfirmed = false,
        ?int $actorUserId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Contact {
        $email = strtolower(trim((string) ($data['email'] ?? '')));

        return DB::transaction(function () use (
            $data,
            $statusKey,
            $existingRelationshipConfirmed,
            $actorUserId,
            $ipAddress,
            $userAgent,
            $email,
        ): Contact {
            $alreadyExists = Contact::query()
                ->where('email', $email)
                ->exists();

            $contact = $this->createOrUpdateContact->handle(
                data: $data,
                statusKey: $statusKey,
                statusChangeReason: 'crm_manual_create',
            );

            if (! $alreadyExists) {
                ManualContactCreated::dispatch(
                    contact: $contact,
                    existingRelationshipConfirmed: $existingRelationshipConfirmed,
                    actorUserId: $actorUserId,
                    ipAddress: $ipAddress,
                    userAgent: $userAgent,
                );
            }

            return $contact;
        });
    }
}