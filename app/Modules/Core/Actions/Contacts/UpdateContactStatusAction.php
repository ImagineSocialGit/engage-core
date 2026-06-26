<?php

namespace App\Modules\Core\Actions\Contacts;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use Illuminate\Support\Facades\DB;

class UpdateContactStatusAction
{
    public function handle(
        Contact $contact,
        ContactStatus $status,
        ?string $reason = null,
    ): Contact {
        return DB::transaction(function () use ($contact, $status, $reason): Contact {
            $profile = ContactWorkflowProfile::query()->firstOrNew([
                'contact_id' => $contact->id,
            ]);

            $fromStatusId = $profile->contact_status_id;

            if ((int) $fromStatusId === (int) $status->id) {
                return $contact->refresh()->load('workflowProfile');
            }

            $profile->fill([
                'contact_status_id' => $status->id,
                'last_status_changed_at' => now(),
                'meta' => [
                    ...($profile->meta ?? []),
                    'last_status_change' => [
                        'from_contact_status_id' => $fromStatusId,
                        'to_contact_status_id' => $status->id,
                        'reason' => $reason,
                        'changed_at' => now()->toISOString(),
                    ],
                ],
            ]);

            $profile->save();

            $contact->forceFill([
                'last_activity_at' => now(),
            ])->save();

            return $contact->refresh()->load('workflowProfile.contactStatus');
        });
    }
}