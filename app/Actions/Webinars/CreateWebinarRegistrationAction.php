<?php

namespace App\Actions\Webinars;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\Lead;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\Messaging\PhoneNumberNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreateWebinarRegistrationAction
{
    public function __construct(
        private readonly PhoneNumberNormalizer $phoneNumberNormalizer,
        private readonly AddRegistrantToWebinarProviderAction $addRegistrantToWebinarProviderAction,
        private readonly DispatchWebinarRegistrationMessagesAction $dispatchWebinarRegistrationMessagesAction,
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

            $lead = Lead::query()->updateOrCreate(
                ['email' => $validated['email']],
                [
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'] ?? null,
                    'phone' => $normalizedPhone,
                    'status' => 'new',
                    'source' => 'webinar',
                    'subsource' => $webinar->slug,
                ]
            );

            $registration = WebinarRegistration::query()
                ->where('lead_id', $lead->id)
                ->where('webinar_id', $webinar->id)
                ->first();

            if ($registration) {
                $this->storeMessageConsents($validated, $request, $lead, $registration);

                return $registration;
            }

            $now = now();

            $registration = WebinarRegistration::query()->create([
                'lead_id' => $lead->id,
                'webinar_id' => $webinar->id,
                'webinar_slug' => $webinar->slug,
                'status' => 'pending',
                'source' => 'webinar_subdomain',
                'registered_at' => $now,
                'attended_at' => null,
            ]);

            $this->storeMessageConsents($validated, $request, $lead, $registration, $now);

            $registration->load(['lead', 'webinar']);

            $this->syncRegistrationToWebinarPlatform($registration, $webinar);

            DB::afterCommit(function () use ($registration) {
                $this->dispatchWebinarRegistrationMessagesAction->handle($registration);
            });

            return $registration;
        });
    }

    private function storeMessageConsents(
        array $validated,
        Request $request,
        Lead $lead,
        WebinarRegistration $registration,
        mixed $now = null
    ): void {
        $now ??= now();

        $consents = [
            'transactional_email_consent' => [
                'channel' => MessageChannel::Email,
                'purpose' => MessagePurpose::Transactional,
            ],
            'transactional_sms_consent' => [
                'channel' => MessageChannel::Sms,
                'purpose' => MessagePurpose::Transactional,
            ],
            'marketing_email_consent' => [
                'channel' => MessageChannel::Email,
                'purpose' => MessagePurpose::Marketing,
            ],
            'marketing_sms_consent' => [
                'channel' => MessageChannel::Sms,
                'purpose' => MessagePurpose::Marketing,
            ],
        ];

        foreach ($consents as $field => $consent) {
            if (! (bool) ($validated[$field] ?? false)) {
                continue;
            }

            DB::table('message_consents')->updateOrInsert(
                [
                    'lead_id' => $lead->id,
                    'channel' => $consent['channel']->value,
                    'purpose' => $consent['purpose']->value,
                ],
                [
                    'webinar_registration_id' => $registration->id,
                    'consented_at' => $now,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'source' => 'webinar_registration',
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    private function syncRegistrationToWebinarPlatform(
        WebinarRegistration $registration,
        Webinar $webinar
    ): void {
        if ($webinar->platform !== config('webinars.provider')) {
            return;
        }

        if (blank($webinar->external_id)) {
            return;
        }

        $providerResponse = $this->addRegistrantToWebinarProviderAction->handle(
            $webinar,
            $registration
        );

        $meta = $registration->meta ?? [];

        $meta['provider'] = [
            'name' => $providerResponse['name'],
            'registrant_id' => data_get($providerResponse, 'data.registrant_id'),
            'join_url' => data_get($providerResponse, 'data.join_url'),
            'raw' => $providerResponse['raw'] ?? null,
        ];

        $registration->update([
            'meta' => $meta,
        ]);
    }
}