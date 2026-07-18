<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Core\Actions\Contacts\CreateOrUpdateContactAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\GrantMessageConsentsAction;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\PhoneNumberNormalizer;
use App\Modules\Webinars\Data\WebinarRegistrationResult;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreateWebinarRegistrationAction
{
    public function __construct(
        private readonly MessageChannelAvailability $messageChannelAvailability,
        private readonly PhoneNumberNormalizer $phoneNumberNormalizer,
        private readonly GrantMessageConsentsAction $grantMessageConsentsAction,
        private readonly CreateOrUpdateContactAction $createOrUpdateContact,
        private readonly EmitWebinarAutomationEventAction $emitWebinarAutomationEvent,
        private readonly FinalizeWebinarRegistrationAction $finalizeRegistration,
    ) {}

    public function handle(
        array $validated,
        Request $request,
        Webinar $webinar,
    ): WebinarRegistrationResult {
        $result = DB::transaction(function () use (
            $validated,
            $request,
            $webinar,
        ): WebinarRegistrationResult {
            $normalizedPhone = $this->phoneNumberNormalizer->normalize(
                $validated['phone'] ?? null,
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
                ->where('contact_id', $contact->getKey())
                ->where('webinar_id', $webinar->getKey())
                ->first();

            if ($registration instanceof WebinarRegistration) {
                return WebinarRegistrationResult::existing(
                    registration: $registration,
                    consentGrants: $this->storeMessageConsents(
                        validated: $validated,
                        request: $request,
                        contact: $contact,
                        registration: $registration,
                    ),
                );
            }

            $now = now();

            $registration = WebinarRegistration::query()->create([
                'contact_id' => $contact->getKey(),
                'webinar_id' => $webinar->getKey(),
                'webinar_slug' => $webinar->slug,
                'status' => 'pending',
                'source' => 'webinar_subdomain',
                'registered_at' => $now,
                'attended_at' => null,
                'meta' => [
                    // Consent/business provenance. Reporting must not reuse this
                    // as anonymous visitor identity.
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

            $consentGrants = $this->storeMessageConsents(
                validated: $validated,
                request: $request,
                contact: $contact,
                registration: $registration,
                now: $now,
            );

            $registration->load([
                'contact',
                'webinar',
                'webinar.webinarSeries',
            ]);

            $this->emitWebinarAutomationEvent->forRegistration(
                eventKey: 'webinar.registered',
                registration: $registration,
                occurredAt: $registration->registered_at ?? $now,
            );

            return WebinarRegistrationResult::created(
                registration: $registration,
                consentGrants: $consentGrants,
            );
        });

        // This runs only after the local registration/consent transaction has
        // committed. Provider or delivery failures cannot roll it back.
        try {
            $this->finalizeRegistration->handle($result);
        } catch (Throwable $exception) {
            // The local registration is already committed. Downstream failures
            // are operational failures, not registration failures.
            report($exception);
        }

        return $result;
    }

    /**
     * @return array<int, MessageConsentGrantResult>
     */
    private function storeMessageConsents(
        array $validated,
        Request $request,
        Contact $contact,
        WebinarRegistration $registration,
        mixed $now = null,
    ): array {
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
                    'webinar_registration_id' => $registration->getKey(),
                    'webinar_id' => $registration->webinar_id,
                    'webinar_slug' => $registration->webinar_slug,
                ],
            ];
        }

        return $this->grantMessageConsentsAction->handle(
            contact: $contact,
            grants: $grants,
            context: $registration,
        );
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

    /** @return array<int, string> */
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
}