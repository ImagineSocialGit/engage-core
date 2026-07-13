<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Core\Actions\Contacts\CreateOrUpdateContactAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ImportMessageConsentAction;
use App\Modules\Messaging\Services\PhoneNumberNormalizer;
use App\Modules\Webinars\Data\WebinarRegistrationImportResult;
use App\Modules\Webinars\Data\WebinarRegistrationImportRow;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ImportWebinarRegistrationAction
{
    public function __construct(
        private readonly CreateOrUpdateContactAction $createOrUpdateContact,
        private readonly PhoneNumberNormalizer $phoneNumberNormalizer,
        private readonly ImportMessageConsentAction $importMessageConsent,
        private readonly DispatchWebinarRegistrationMessagesAction $dispatchWebinarRegistrationMessages,
    ) {}

    public function handle(
        Webinar $webinar,
        WebinarRegistrationImportRow $row,
        Carbon|string|null $registeredAt = null,
        bool $scheduleReminders = true,
    ): WebinarRegistrationImportResult {
        $registeredAt = $registeredAt ? Carbon::parse($registeredAt) : now();

        $result = DB::transaction(function () use ($webinar, $row, $registeredAt): WebinarRegistrationImportResult {
            $existingContact = Contact::query()
                ->where('email', $row->email)
                ->first();

            $contact = $this->createOrUpdateContact->handle(
                data: array_filter([
                    'email' => $row->email,
                    'first_name' => $row->firstName,
                    'last_name' => $row->lastName,
                    'phone' => $this->phoneNumberNormalizer->normalize($row->phone),
                    'source' => 'webinar',
                    'subsource' => $webinar->slug,
                ], fn (mixed $value): bool => $value !== null),
            );

            $consentCounts = $this->importConsents(
                contact: $contact,
                row: $row,
                webinar: $webinar,
                consentedAt: $registeredAt,
            );

            $registration = WebinarRegistration::query()->firstOrNew([
                'webinar_id' => $webinar->getKey(),
                'contact_id' => $contact->getKey(),
            ]);

            $registrationCreated = ! $registration->exists;

            $registration->forceFill([
                'webinar_slug' => $webinar->slug,
                'status' => $registration->status ?: 'pending',
                'source' => $registration->source ?: 'webinar_registration_import',
                'registered_at' => $registration->registered_at ?? $registeredAt,
                'meta' => array_replace_recursive(
                    is_array($registration->meta) ? $registration->meta : [],
                    [
                        'accepted_channels' => [
                            'transactional' => $row->acceptedTransactionalChannels(),
                            'marketing' => $row->acceptedMarketingChannels(),
                        ],
                    ],
                ),
            ])->save();

            return new WebinarRegistrationImportResult(
                contact: $contact->refresh(),
                registration: $registration->refresh(),
                contactCreated: $existingContact === null,
                registrationCreated: $registrationCreated,
                consentsCreated: $consentCounts['created'],
                consentsUpdated: $consentCounts['updated'],
                remindersScheduled: 0,
            );
        });

        if (! $scheduleReminders) {
            return $result;
        }

        $scheduledMessages = $this->dispatchWebinarRegistrationMessages->handle(
            registration: $result->registration,
            contextKeys: ['reminders'],
        );

        return new WebinarRegistrationImportResult(
            contact: $result->contact,
            registration: $result->registration,
            contactCreated: $result->contactCreated,
            registrationCreated: $result->registrationCreated,
            consentsCreated: $result->consentsCreated,
            consentsUpdated: $result->consentsUpdated,
            remindersScheduled: count($scheduledMessages),
        );
    }

    /**
     * @return array{created: int, updated: int}
     */
    private function importConsents(
        Contact $contact,
        WebinarRegistrationImportRow $row,
        Webinar $webinar,
        Carbon $consentedAt,
    ): array {
        $definitions = [
            [$row->transactionalEmailConsent, 'email', 'transactional', 'webinar'],
            [$row->transactionalSmsConsent, 'sms', 'transactional', 'webinar'],
            [$row->marketingEmailConsent, 'email', 'marketing', 'webinar_nurture'],
            [$row->marketingSmsConsent, 'sms', 'marketing', 'webinar_nurture'],
        ];

        $created = 0;
        $updated = 0;

        foreach ($definitions as [$selected, $channel, $purpose, $scope]) {
            if (! $selected) {
                continue;
            }

            $result = $this->importMessageConsent->handle(
                contact: $contact,
                channel: $channel,
                purpose: $purpose,
                scope: $scope,
                consentedAt: $consentedAt,
                source: 'webinar_registration_import',
                meta: [
                    'webinar_id' => $webinar->getKey(),
                    'webinar_slug' => $webinar->slug,
                ],
            );

            $result['created'] ? $created++ : $updated++;
        }

        return [
            'created' => $created,
            'updated' => $updated,
        ];
    }
}
