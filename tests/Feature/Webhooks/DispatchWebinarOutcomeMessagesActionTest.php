<?php

namespace Tests\Feature\Webinars;

use App\Actions\Webinars\DispatchWebinarOutcomeMessagesAction;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Jobs\Messaging\SendScheduledMessageJob;
use App\Messaging\Payloads\Webinars\WebinarFollowUpEmailPayload;
use App\Messaging\Payloads\Webinars\WebinarFollowUpSmsPayload;
use App\Models\Lead;
use App\Models\ScheduledMessage;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchWebinarOutcomeMessagesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_attended_registration_routes_to_replay_follow_up(): void
    {
        Queue::fake();

        $webinar = Webinar::factory()->create();
        $lead = $this->createLead();

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

        app(DispatchWebinarOutcomeMessagesAction::class)->handle($registration);

        $this->assertScheduledFollowUp(
            registration: $registration,
            channel: MessageChannel::Email->value,
            messageType: 'webinar_post_replay',
            payloadClass: WebinarFollowUpEmailPayload::class,
            followUpType: 'replay',
        );

        $this->assertScheduledFollowUp(
            registration: $registration,
            channel: MessageChannel::Sms->value,
            messageType: 'webinar_post_replay',
            payloadClass: WebinarFollowUpSmsPayload::class,
            followUpType: 'replay',
        );

        Queue::assertPushed(SendScheduledMessageJob::class, 2);
    }

    public function test_non_attendee_routes_to_missed_follow_up(): void
    {
        Queue::fake();

        $webinar = Webinar::factory()->create();
        $lead = $this->createLead();

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

        app(DispatchWebinarOutcomeMessagesAction::class)->handle($registration);

        $this->assertScheduledFollowUp(
            registration: $registration,
            channel: MessageChannel::Email->value,
            messageType: 'webinar_post_missed',
            payloadClass: WebinarFollowUpEmailPayload::class,
            followUpType: 'missed',
        );

        $this->assertScheduledFollowUp(
            registration: $registration,
            channel: MessageChannel::Sms->value,
            messageType: 'webinar_post_missed',
            payloadClass: WebinarFollowUpSmsPayload::class,
            followUpType: 'missed',
        );

        Queue::assertPushed(SendScheduledMessageJob::class, 2);
    }

    private function createLead(): Lead
    {
        return Lead::query()->create([
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'name' => 'Jeff Yarnall',
            'email' => 'attendee@example.com',
            'phone' => '+15555550123',
            'status' => 'new',
            'source' => 'webinar',
        ]);
    }

    private function assertScheduledFollowUp(
        WebinarRegistration $registration,
        string $channel,
        string $messageType,
        string $payloadClass,
        string $followUpType,
    ): void {
        $scheduledMessage = ScheduledMessage::query()
            ->whereMorphedTo('recipient', $registration->lead)
            ->whereMorphedTo('context', $registration)
            ->where('channel', $channel)
            ->where('message_type', $messageType)
            ->where('purpose', MessagePurpose::Transactional->value)
            ->where('payload_class', $payloadClass)
            ->first();

        $this->assertNotNull($scheduledMessage);
        $this->assertSame('pending', $scheduledMessage->status);
        $this->assertSame($followUpType, $scheduledMessage->payload['follow_up_type']);
    }
}