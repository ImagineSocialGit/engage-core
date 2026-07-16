<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Core\Actions\Contacts\CreateOrUpdateContactAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchConsentOptInMessageAction;
use App\Modules\Messaging\Actions\GrantMessageConsentsAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\PhoneNumberNormalizer;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreateWebinarRegistrationAction
{
    public function __construct(
        private readonly MessageChannelAvailability $messageChannelAvailability,
        private readonly PhoneNumberNormalizer $phoneNumberNormalizer,
        private readonly AddRegistrantToWebinarProviderAction $addRegistrantToWebinarProviderAction,
        private readonly DispatchWebinarRegistrationMessagesAction $dispatchWebinarRegistrationMessagesAction,
        private readonly GrantMessageConsentsAction $grantMessageConsentsAction,
        private readonly DispatchConsentOptInMessageAction $dispatchConsentOptInMessageAction,
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
                'meta' => [
                    'request_ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'accepted_channels' => [
                        'transactional' => $this->acceptedChannels(
                            validated: $validated,
                            purpose: MessagePurpose::Transactional,
                            scope: 'webinar',
                        ),
                        'marketing' => $this->acceptedChannels(
                            validated: $validated,
                            purpose: MessagePurpose::Marketing,
                            scope: 'webinar_nurture',
                        ),
                    ],
                ],
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
        $grants = [];

        foreach ($this->consentDefinitions() as $field => $definition) {
            if (! ($validated[$field] ?? false)) {
                continue;
            }

            if (! $this->messageChannelAvailability->isVisibleForSurface(
                channel: $definition['channel'],
                surface: 'webinar_registrations',
                purpose: $definition['purpose']->value,
                scope: $definition['scope'],
            )) {
                continue;
            }

            $grants[] = [
                'channel' => $definition['channel']->value,
                'purpose' => $definition['purpose']->value,
                'scope' => $definition['scope'],
                'consented_at' => $now,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'source' => 'webinar_registration',
                'meta' => [
                    'webinar_registration_id' => $registration->id,
                    'webinar_id' => $registration->webinar_id,
                    'webinar_slug' => $registration->webinar_slug,
                ],
            ];
        }

        $results = $this->grantMessageConsentsAction->handle(
            contact: $contact,
            grants: $grants,
            context: $registration,
        );

        foreach ($results as $result) {
            if (! $result->becameActive) {
                continue;
            }

            $this->dispatchConsentOptInMessageAction->handle(
                contact: $contact,
                grant: $result,
                payload: [
                    'webinar_registration_id' => $registration->id,
                    'webinar_id' => $registration->webinar_id,
                    'webinar_slug' => $registration->webinar_slug,
                ],
                context: $registration,
                resolverContext: [
                    'webinar_slug' => $registration->webinar_slug,
                ],
            );
        }
    }

    /**
     * @return array<string, array{channel: MessageChannel, purpose: MessagePurpose, scope: string}>
     */
    private function consentDefinitions(): array
    {
        return [
            'transactional_email_consent' => [
                'channel' => MessageChannel::Email,
                'purpose' => MessagePurpose::Transactional,
                'scope' => 'webinar',
            ],
            'transactional_sms_consent' => [
                'channel' => MessageChannel::Sms,
                'purpose' => MessagePurpose::Transactional,
                'scope' => 'webinar',
            ],
            'marketing_email_consent' => [
                'channel' => MessageChannel::Email,
                'purpose' => MessagePurpose::Marketing,
                'scope' => 'webinar_nurture',
            ],
            'marketing_sms_consent' => [
                'channel' => MessageChannel::Sms,
                'purpose' => MessagePurpose::Marketing,
                'scope' => 'webinar_nurture',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function acceptedChannels(
        array $validated,
        MessagePurpose $purpose,
        string $scope,
    ): array {
        $channels = [];

        foreach ([MessageChannel::Email, MessageChannel::Sms] as $channel) {
            if (! ($validated["{$purpose->value}_{$channel->value}_consent"] ?? false)) {
                continue;
            }

            if (! $this->messageChannelAvailability->isVisibleForSurface(
                channel: $channel,
                surface: 'webinar_registrations',
                purpose: $purpose->value,
                scope: $scope,
            )) {
                continue;
            }

            $channels[] = $channel->value;
        }

        return $channels;
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
