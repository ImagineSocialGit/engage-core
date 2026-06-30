<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Core\Actions\Contacts\CreateOrUpdateContactAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\GrantMessageConsentAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\PhoneNumberNormalizer;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreateWebinarRegistrationAction
{
    public function __construct(
        private readonly PhoneNumberNormalizer $phoneNumberNormalizer,
        private readonly AddRegistrantToWebinarProviderAction $addRegistrantToWebinarProviderAction,
        private readonly DispatchWebinarRegistrationMessagesAction $dispatchWebinarRegistrationMessagesAction,
        private readonly GrantMessageConsentAction $grantMessageConsentAction,
        private readonly CreateOrUpdateContactAction $createOrUpdateContact,
        private readonly EmitWebinarAutomationEventAction $emitWebinarAutomationEvent,
    ) {}

    public function handle(array $validated, Request $request, string $webinarSlug = 'default'): WebinarRegistration
    {
        return DB::transaction(function () use ($validated, $request, $webinarSlug) {
            $webinar = Webinar::query()
                ->where('slug', $webinarSlug)
                ->firstOrFail();

            $normalizedPhone = $this->phoneNumberNormalizer->normalize(
                $validated['phone'] ?? null
            );

            $contact = $this->createOrUpdateContact->handle(
                data: [
                    'email' => $validated['email'],
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'] ?? null,
                    'phone' => $normalizedPhone,
                    'source' => 'webinar',
                    'subsource' => $webinar->slug,
                ],
            );

            $registration = WebinarRegistration::query()
                ->where('contact_id', $contact->id)
                ->where('webinar_id', $webinar->id)
                ->first();

            if ($registration) {
                $this->storeMessageConsents($validated, $request, $contact, $registration);

                return $registration;
            }

            $now = now();

            $registration = WebinarRegistration::query()->create([
                'contact_id' => $contact->id,
                'webinar_id' => $webinar->id,
                'webinar_slug' => $webinar->slug,
                'status' => 'pending',
                'source' => 'webinar_subdomain',
                'registered_at' => $now,
                'attended_at' => null,
            ]);

            $this->storeMessageConsents($validated, $request, $contact, $registration, $now);

            $registration->load(['contact', 'webinar', 'webinar.webinarSeries']);

            $this->syncRegistrationToWebinarPlatform($registration, $webinar);

            DB::afterCommit(function () use ($registration, $now) {
                $registration = $registration->fresh([
                    'contact',
                    'webinar',
                    'webinar.webinarSeries',
                ]);

                if (! $registration) {
                    return;
                }

                $this->emitWebinarAutomationEvent->forRegistration(
                    eventKey: 'webinar.registered',
                    registration: $registration,
                    occurredAt: $registration->registered_at ?? $now,
                );

                $this->dispatchWebinarRegistrationMessagesAction->handle($registration);
            });

            return $registration;
        });
    }

    private function storeMessageConsents(
        array $validated,
        Request $request,
        Contact $contact,
        WebinarRegistration $registration,
        mixed $now = null
    ): void {
        $now ??= now();

        $consents = [
            'transactional_email_consent' => [
                [
                    'channel' => MessageChannel::Email,
                    'purpose' => MessagePurpose::Transactional,
                    'scope' => 'webinar',
                    'dispatch_opt_in_message' => false,
                ],
            ],

            'marketing_email_consent' => [
                [
                    'channel' => MessageChannel::Email,
                    'purpose' => MessagePurpose::Marketing,
                    'scope' => 'webinar_nurture',
                    'dispatch_opt_in_message' => true,
                ],
            ],
        ];

        foreach ($consents as $field => $consentDefinitions) {
            if (! ($validated[$field] ?? false)) {
                continue;
            }

            foreach ($consentDefinitions as $consent) {
                $this->grantMessageConsentAction->handle(
                    contact: $contact,
                    data: [
                        'channel' => $consent['channel']->value,
                        'purpose' => $consent['purpose']->value,
                        'scope' => $consent['scope'],
                        'consented_at' => $now,
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'source' => 'webinar_registration',
                        'meta' => [
                            'webinar_registration_id' => $registration->id,
                            'webinar_id' => $registration->webinar_id,
                            'webinar_slug' => $registration->webinar_slug,
                        ],
                    ],
                    optInPayload: [
                        'webinar_registration_id' => $registration->id,
                        'webinar_id' => $registration->webinar_id,
                        'webinar_slug' => $registration->webinar_slug,
                    ],
                    context: $registration,
                    resolverContext: [
                        'webinar_slug' => $registration->webinar_slug,
                    ],
                    dispatchOptInMessage: (bool) $consent['dispatch_opt_in_message'],
                );
            }
        }
    }

    private function syncRegistrationToWebinarPlatform(
        WebinarRegistration $registration,
        Webinar $webinar
    ): void {
        if (blank($webinar->providerKey())) {
            return;
        }

        if (blank($webinar->external_id)) {
            return;
        }

        $providerRegistration = $this->addRegistrantToWebinarProviderAction->handle(
            $webinar,
            $registration
        );

        $meta = $registration->meta ?? [];

        $meta['provider'] = $providerRegistration->toMeta();

        $registration->update([
            'meta' => $meta,
        ]);
    }
}