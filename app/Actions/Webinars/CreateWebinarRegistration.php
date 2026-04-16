<?php

namespace App\Actions\Webinars;

use App\Actions\Leads\AttachTagToLeadAction;
use App\Data\WebinarMessageData;
use App\Jobs\Messaging\DispatchWebinarRegistrationMessagesJob;
use App\Jobs\Webinars\RoutePostWebinarRegistrationJob;
use App\Models\Lead;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\Messaging\PhoneNumberNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreateWebinarRegistration
{
    public function __construct(
        protected AttachTagToLeadAction $attachTagToLeadAction,
        protected PhoneNumberNormalizer $phoneNumberNormalizer,
        protected ScheduleWebinarRemindersAction $scheduleWebinarRemindersAction,
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
                ]
            );

            $registration = WebinarRegistration::query()->where([
                'lead_id' => $lead->id,
                'webinar_id' => $webinar->id,
            ])->first();

            if (! $registration) {
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
                    'notes' => $validated['notes'] ?? null,
                    'registered_at' => now(),
                    'meta' => [
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ],
                ]);

                $registration->load(['lead', 'webinar']);

                DispatchWebinarRegistrationMessagesJob::dispatch(
                    WebinarMessageData::fromRegistration($registration)->toArray()
                )->onQueue('notifications');

                $this->scheduleWebinarRemindersAction->execute($registration);

                RoutePostWebinarRegistrationJob::dispatch($registration->id)
                    ->delay($webinar->ends_at)
                    ->onQueue('notifications');
            }

            return $registration;
        });
    }
}