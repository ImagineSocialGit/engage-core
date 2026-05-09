<?php

namespace App\Actions\Webinars;

use App\Data\WebinarMessageData;
use App\Jobs\Messaging\DispatchWebinarRegistrationMessagesJob;
use App\Models\Lead;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\Messaging\PhoneNumberNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreateWebinarRegistration
{
    public function __construct(
        protected PhoneNumberNormalizer $phoneNumberNormalizer,
        protected ScheduleWebinarRemindersAction $scheduleWebinarRemindersAction,
        protected RegisterAttendeeWithWebinarProviderAction $registerAttendeeWithWebinarProviderAction,
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
                return $registration;
            }

            $registration = WebinarRegistration::query()->create([
                'lead_id' => $lead->id,
                'webinar_id' => $webinar->id,
                'webinar_slug' => $webinar->slug,
                'status' => 'pending',
                'source' => 'webinar_subdomain',
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'] ?? null,
                'email' => $validated['email'],
                'phone' => $normalizedPhone,
                'meta' => [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
                'registered_at' => now(),
                'attended_at' => null,
            ]);

            $registration->load(['lead', 'webinar']);

            $this->syncRegistrationToWebinarPlatform($registration, $webinar);

            DispatchWebinarRegistrationMessagesJob::dispatch(
                WebinarMessageData::fromRegistration($registration)->toArray()
            )->onQueue(config('webinars.queues.registrations'));

            $this->scheduleWebinarRemindersAction->execute($registration);

            return $registration;
        });
    }

    private function syncRegistrationToWebinarPlatform(
        WebinarRegistration $registration,
        Webinar $webinar
    ): void {
        if ($webinar->platform !== config('webinars.provider', 'zoom')) {
            return;
        }

        if (blank($webinar->external_id)) {
            return;
        }

        $providerResponse = $this->registerAttendeeWithWebinarProviderAction->handle(
            $webinar,
            $registration
        );

        $meta = $registration->meta ?? [];

        $meta['provider'] = [
            'name' => $providerResponse['name'],
            'registrant_id' => $providerResponse['registrant_id'] ?? null,
            'join_url' => $providerResponse['join_url'] ?? null,
            'raw' => $providerResponse['raw'] ?? null,
        ];

        $registration->update([
            'meta' => $meta,
        ]);
    }
}
