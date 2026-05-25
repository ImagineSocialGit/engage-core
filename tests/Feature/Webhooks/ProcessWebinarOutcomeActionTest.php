<?php

namespace Tests\Feature\Webinars;

use App\Actions\Webinars\ProcessWebinarOutcomeAction;
use App\Jobs\Messaging\SendEmailMessageJob;
use App\Jobs\Messaging\SendSmsMessageJob;
use App\Messaging\Payloads\Webinars\WebinarFollowUpEmailPayload;
use App\Messaging\Payloads\Webinars\WebinarFollowUpSmsPayload;
use App\Models\Lead;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Services\Messaging\MessageEligibilityGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessWebinarOutcomeActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_attended_registration_routes_to_replay_follow_up(): void
    {
        Queue::fake();

        $this->mockMessageEligibility();

        $webinar = Webinar::factory()->create();

        $lead = Lead::query()->create([
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'name' => 'Jeff Yarnall',
            'email' => 'attendee@example.com',
            'phone' => '+15555550123',
            'status' => 'new',
            'source' => 'webinar',
        ]);

        $registration = WebinarRegistration::query()->create([
            'lead_id' => $lead->id,
            'webinar_id' => $webinar->id,
            'webinar_slug' => $webinar->slug,
            'status' => 'registered',
            'source' => 'webinar_subdomain',
            'email' => $lead->email,
            'phone' => $lead->phone,
            'registered_at' => now(),
            'attended_at' => now(),
        ]);

        app(ProcessWebinarOutcomeAction::class)
            ->handle($registration);

        Queue::assertPushed(SendEmailMessageJob::class, function (SendEmailMessageJob $job): bool {
            return $job->payloadClass === WebinarFollowUpEmailPayload::class
                && $job->payload['follow_up_type'] === 'webinar_replay';
        });

        Queue::assertPushed(SendSmsMessageJob::class, function (SendSmsMessageJob $job): bool {
            return $job->payloadClass === WebinarFollowUpSmsPayload::class
                && $job->payload['follow_up_type'] === 'webinar_replay';
        });
    }

    public function test_non_attendee_routes_to_missed_follow_up(): void
    {
        Queue::fake();

        $this->mockMessageEligibility();

        $webinar = Webinar::factory()->create();

        $lead = Lead::query()->create([
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'name' => 'Jeff Yarnall',
            'email' => 'attendee@example.com',
            'phone' => '+15555550123',
            'status' => 'new',
            'source' => 'webinar',
        ]);

        $registration = WebinarRegistration::query()->create([
            'lead_id' => $lead->id,
            'webinar_id' => $webinar->id,
            'webinar_slug' => $webinar->slug,
            'status' => 'registered',
            'source' => 'webinar_subdomain',
            'email' => $lead->email,
            'phone' => $lead->phone,
            'registered_at' => now(),
            'attended_at' => null,
        ]);

        app(ProcessWebinarOutcomeAction::class)
            ->handle($registration);

        Queue::assertPushed(SendEmailMessageJob::class, function (SendEmailMessageJob $job): bool {
            return $job->payloadClass === WebinarFollowUpEmailPayload::class
                && $job->payload['follow_up_type'] === 'webinar_missed';
        });

        Queue::assertPushed(SendSmsMessageJob::class, function (SendSmsMessageJob $job): bool {
            return $job->payloadClass === WebinarFollowUpSmsPayload::class
                && $job->payload['follow_up_type'] === 'webinar_missed';
        });
    }

    private function mockMessageEligibility(): void
    {
        $this->mock(MessageEligibilityGate::class, function ($mock): void {
            $mock->shouldReceive('canSend')->andReturnTrue();
        });
    }
}