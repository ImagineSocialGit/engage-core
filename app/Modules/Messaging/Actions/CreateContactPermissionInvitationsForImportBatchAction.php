<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\ContactPermissionInvitationEligibility;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;

class CreateContactPermissionInvitationsForImportBatchAction
{
    public function __construct(
        private readonly ContactPermissionInvitationEligibility $eligibility,
        private readonly DispatchMessageAction $dispatchMessageAction,
    ) {}

    /**
     * @return array{eligible: int, scheduled: int, skipped: int}
     */
    public function handle(ContactImportBatch $importBatch): array
    {
        $eligible = 0;
        $scheduled = 0;
        $skipped = 0;

        $importBatch->contacts()
            ->orderBy('id')
            ->chunkById(100, function ($contacts) use ($importBatch, &$eligible, &$scheduled, &$skipped): void {
                foreach ($contacts as $contact) {
                    if (! $this->eligibility->eligibleForImportedContactEmailInvitation($contact)) {
                        $skipped++;

                        continue;
                    }

                    $eligible++;

                    $messages = $this->dispatchMessageAction->handle(
                        recipient: $contact,
                        channel: MessageChannel::Email,
                        purpose: MessagePurpose::Marketing,
                        scope: 'broadcast',
                        dispatchKeys: 'imported_contact_permission_invitation',
                        payload: [],
                        context: $importBatch,
                        meta: [
                            'consent_policy' => $this->consentPolicy(),
                            'permission_invitation' => [
                                'source' => 'imported_contact',
                                'contact_import_batch_id' => $importBatch->getKey(),
                            ],
                        ],
                        definitions: [
                            [
                                'dispatch_key' => 'imported_contact_permission_invitation',
                                'channel' => MessageChannel::Email->value,
                                'purpose' => MessagePurpose::Marketing->value,
                                'scope' => 'broadcast',
                                'message_type' => ContactPermissionInvitationService::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
                                'timing' => 'immediate',
                                'payload_class' => EmailPayload::class,
                                'queue' => 'marketing',
                                'payload' => [
                                    'subject' => config(
                                        'messaging.permission_invitations.email.subject',
                                        'Confirm how you want to hear from us',
                                    ),
                                    'body' => config(
                                        'messaging.permission_invitations.email.body',
                                        'Hi {first_name}, please confirm your communication preferences so we know how you want to hear from us.',
                                    ),
                                ],
                                'consent_policy' => $this->consentPolicy(),
                            ],
                        ],
                    );

                    $scheduled += count($messages);
                }
            });

        return [
            'eligible' => $eligible,
            'scheduled' => $scheduled,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function consentPolicy(): array
    {
        return [
            'permission_invitation' => [
                'source' => 'imported_contact',
                'one_time' => true,
            ],
        ];
    }
}