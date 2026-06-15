<?php

namespace Tests\Feature\Messaging;

use App\Actions\Campaigns\ScheduleNextCampaignStepAction;
use App\Contracts\Messaging\Email\EmailMessage;
use App\Contracts\Messaging\Sms\SmsMessage;
use App\Jobs\Messaging\SendScheduledMessageJob;
use App\Messaging\Payloads\EmailPayload;
use App\Models\CampaignEnrollment;
use App\Models\Contact;
use App\Models\MessageConsent;
use App\Models\ScheduledMessage;
use App\Services\Messaging\Email\EmailMessagingService;
use App\Services\Messaging\MessageEligibilityGate;
use App\Services\Messaging\Sms\SmsMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SendScheduledMessageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_pending_email_message(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
        ]);

        $this->grantConsent($contact, 'email', 'transactional');

        $scheduledMessage = ScheduledMessage::factory()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => FakeJobEmailPayload::class,
            'payload' => [
                'to' => 'test@example.com',
            ],
            'status' => 'pending',
            'meta' => [
                'conditions' => [],
                'definition_config_path' => 'messaging.email.transactional.webinar.confirmation',
            ],
        ]);

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService
            ->shouldReceive('send')
            ->once()
            ->with(Mockery::type(FakeJobEmailPayload::class));

        app()->instance(EmailMessagingService::class, $emailService);

        (new SendScheduledMessageJob($scheduledMessage->id))->handle(
            conditionChecker: app(\App\Services\ConditionChecker::class),
            messageEligibilityGate: $this->messageEligibilityGate(),
            emailMessagingService: app(EmailMessagingService::class),
            smsMessagingService: app(SmsMessagingService::class),
            scheduleNextCampaignStepAction: app(ScheduleNextCampaignStepAction::class),
        );

        $scheduledMessage->refresh();

        $this->assertSame('sent', $scheduledMessage->status);
        $this->assertNotNull($scheduledMessage->sent_at);
    }

    public function test_it_sends_pending_sms_message(): void
    {
        $contact = Contact::factory()->create([
            'phone' => '+15555550123',
        ]);

        $this->grantConsent($contact, 'sms', 'transactional');

        $scheduledMessage = ScheduledMessage::factory()->create([
            'contact_id' => $contact->id,
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => FakeJobSmsPayload::class,
            'payload' => [
                'to' => '+15555550123',
                'message' => 'Hello',
            ],
            'status' => 'pending',
            'meta' => [
                'conditions' => [],
                'definition_config_path' => 'messaging.sms.transactional.webinar.confirmation',
            ],
        ]);

        $smsService = Mockery::mock(SmsMessagingService::class);
        $smsService
            ->shouldReceive('send')
            ->once()
            ->with(Mockery::type(FakeJobSmsPayload::class));

        app()->instance(SmsMessagingService::class, $smsService);

        (new SendScheduledMessageJob($scheduledMessage->id))->handle(
            conditionChecker: app(\App\Services\ConditionChecker::class),
            messageEligibilityGate: $this->messageEligibilityGate(),
            emailMessagingService: app(EmailMessagingService::class),
            smsMessagingService: app(SmsMessagingService::class),
            scheduleNextCampaignStepAction: app(ScheduleNextCampaignStepAction::class),
        );

        $scheduledMessage->refresh();

        $this->assertSame('sent', $scheduledMessage->status);
        $this->assertNotNull($scheduledMessage->sent_at);
    }

    public function test_it_skips_when_conditions_fail_at_send_time(): void
    {
        $contact = Contact::factory()->create([
            'status' => 'converted',
            'email' => 'test@example.com',
        ]);

        $this->grantConsent($contact, 'email', 'transactional');

        $scheduledMessage = ScheduledMessage::factory()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'follow_up',
            'payload_class' => FakeJobEmailPayload::class,
            'payload' => [
                'to' => 'test@example.com',
            ],
            'status' => 'pending',
            'meta' => [
                'conditions' => [
                    'contact.status_not_in' => [
                        'converted',
                    ],
                ],
                'definition_config_path' => 'messaging.email.transactional.webinar.follow_up',
            ],
        ]);

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService->shouldNotReceive('send');

        app()->instance(EmailMessagingService::class, $emailService);

        (new SendScheduledMessageJob($scheduledMessage->id))->handle(
            conditionChecker: app(\App\Services\ConditionChecker::class),
            messageEligibilityGate: app(\App\Services\Messaging\MessageEligibilityGate::class),
            emailMessagingService: app(EmailMessagingService::class),
            smsMessagingService: app(SmsMessagingService::class),
            scheduleNextCampaignStepAction: app(ScheduleNextCampaignStepAction::class),
        );

        $scheduledMessage->refresh();

        $this->assertSame('skipped', $scheduledMessage->status);
        $this->assertSame(
            'Message conditions no longer pass.',
            $scheduledMessage->failure_reason
        );
    }

    public function test_it_skips_when_gate_denies_send(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'message_type' => 'follow_up',
            'payload_class' => FakeJobEmailPayload::class,
            'payload' => [
                'to' => 'test@example.com',
            ],
            'status' => 'pending',
            'meta' => [
                'conditions' => [],
                'definition_config_path' => 'messaging.email.marketing.webinar.follow_up',
            ],
        ]);

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService->shouldNotReceive('send');

        app()->instance(EmailMessagingService::class, $emailService);

        (new SendScheduledMessageJob($scheduledMessage->id))->handle(
            conditionChecker: app(\App\Services\ConditionChecker::class),
            messageEligibilityGate: $this->messageEligibilityGate(false),
            emailMessagingService: app(EmailMessagingService::class),
            smsMessagingService: app(SmsMessagingService::class),
            scheduleNextCampaignStepAction: app(ScheduleNextCampaignStepAction::class),
        );

        $scheduledMessage->refresh();

        $this->assertSame('skipped', $scheduledMessage->status);
        $this->assertSame(
            'Message eligibility gate denied send.',
            $scheduledMessage->failure_reason
        );
    }

    public function test_it_marks_failed_when_payload_class_is_invalid(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
        ]);

        $this->grantConsent($contact, 'email', 'transactional');

        $scheduledMessage = ScheduledMessage::factory()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => 'Missing\\Payload',
            'payload' => [
                'to' => 'test@example.com',
            ],
            'status' => 'pending',
            'meta' => [
                'conditions' => [],
                'definition_config_path' => 'messaging.email.transactional.webinar.confirmation',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        try {
            (new SendScheduledMessageJob($scheduledMessage->id))->handle(
                conditionChecker: app(\App\Services\ConditionChecker::class),
                messageEligibilityGate: $this->messageEligibilityGate(),
                emailMessagingService: app(EmailMessagingService::class),
                smsMessagingService: app(SmsMessagingService::class),
                scheduleNextCampaignStepAction: app(ScheduleNextCampaignStepAction::class),
            );
        } finally {
            $scheduledMessage->refresh();

            $this->assertSame('failed', $scheduledMessage->status);
            $this->assertSame(
                'Scheduled message payload class is invalid.',
                $scheduledMessage->failure_reason
            );
        }
    }

    public function test_it_schedules_next_campaign_step_after_successful_send(): void
    {
        Queue::fake();

        Config::set('messaging.email.marketing.webinar', [
            'step_1' => [
                'dispatch_key' => 'webinar_ended',
                'campaign_key' => 'webinar_attended',
                'step' => 1,
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'delay',
                    'minutes' => 60,
                ],
                'payload_class' => EmailPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'subject' => 'Step 1',
                    'body' => 'First',
                ],
            ],
            'step_2' => [
                'dispatch_key' => 'marketing_message_sent',
                'campaign_key' => 'webinar_attended',
                'step' => 2,
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'delay',
                    'minutes' => 60,
                ],
                'payload_class' => EmailPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'subject' => 'Step 2',
                    'body' => 'Second',
                ],
            ],
        ]);

        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
        ]);

        $this->grantConsent($contact, 'email', 'marketing');

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_key' => 'webinar_attended',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 1,
            'started_at' => now(),
        ]);

        $message = ScheduledMessage::factory()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'message_type' => 'step_1',
            'payload_class' => FakeJobEmailPayload::class,
            'payload' => [
                'to' => 'test@example.com',
            ],
            'status' => 'pending',
            'meta' => [
                'conditions' => [],
                'definition_config_path' => 'messaging.email.marketing.webinar.step_1',
                'campaign_enrollment_id' => $enrollment->id,
                'campaign_key' => 'webinar_attended',
                'campaign_step' => 1,
            ],
        ]);

        $enrollment->update([
            'last_scheduled_message_id' => $message->id,
        ]);

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService
            ->shouldReceive('send')
            ->once()
            ->with(Mockery::type(FakeJobEmailPayload::class));

        app()->instance(EmailMessagingService::class, $emailService);

        (new SendScheduledMessageJob($message->id))->handle(
            conditionChecker: app(\App\Services\ConditionChecker::class),
            messageEligibilityGate: app(\App\Services\Messaging\MessageEligibilityGate::class),
            emailMessagingService: app(EmailMessagingService::class),
            smsMessagingService: app(SmsMessagingService::class),
            scheduleNextCampaignStepAction: app(ScheduleNextCampaignStepAction::class),
        );

        $enrollment->refresh();

        $this->assertSame(2, $enrollment->current_step);
        $this->assertNotSame($message->id, $enrollment->last_scheduled_message_id);

        $this->assertDatabaseCount('scheduled_messages', 2);
    }

    public function test_it_does_not_progress_without_campaign_metadata(): void
    {
        $message = ScheduledMessage::factory()->create([
            'status' => 'pending',
            'meta' => [],
        ]);

        (new SendScheduledMessageJob($message->id))->handle(
            conditionChecker: app(\App\Services\ConditionChecker::class),
            messageEligibilityGate: app(\App\Services\Messaging\MessageEligibilityGate::class),
            emailMessagingService: app(EmailMessagingService::class),
            smsMessagingService: app(SmsMessagingService::class),
            scheduleNextCampaignStepAction: app(ScheduleNextCampaignStepAction::class),
        );

        $this->assertDatabaseCount('campaign_enrollments', 0);
    }

    public function test_it_completes_campaign_when_no_next_step_exists(): void
    {
        Config::set('messaging.email.marketing.webinar', [
            'step_1' => [
                'dispatch_key' => 'webinar_ended',
                'campaign_key' => 'webinar_attended',
                'step' => 1,
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'delay',
                    'minutes' => 60,
                ],
                'payload_class' => EmailPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'subject' => 'Step 1',
                    'body' => 'First',
                ],
            ],
        ]);

        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
        ]);

        $this->grantConsent($contact, 'email', 'marketing');

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_key' => 'webinar_attended',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 1,
            'started_at' => now(),
        ]);

        $message = ScheduledMessage::factory()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'message_type' => 'step_1',
            'payload_class' => FakeJobEmailPayload::class,
            'payload' => [
                'to' => 'test@example.com',
            ],
            'status' => 'pending',
            'meta' => [
                'conditions' => [],
                'definition_config_path' => 'messaging.email.marketing.webinar.step_1',
                'campaign_enrollment_id' => $enrollment->id,
                'campaign_key' => 'webinar_attended',
                'campaign_step' => 1,
            ],
        ]);

        $enrollment->update([
            'last_scheduled_message_id' => $message->id,
        ]);

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService
            ->shouldReceive('send')
            ->once()
            ->with(Mockery::type(FakeJobEmailPayload::class));

        app()->instance(EmailMessagingService::class, $emailService);

        (new SendScheduledMessageJob($message->id))->handle(
            conditionChecker: app(\App\Services\ConditionChecker::class),
            messageEligibilityGate: app(\App\Services\Messaging\MessageEligibilityGate::class),
            emailMessagingService: app(EmailMessagingService::class),
            smsMessagingService: app(SmsMessagingService::class),
            scheduleNextCampaignStepAction: app(ScheduleNextCampaignStepAction::class),
        );

        $enrollment->refresh();

        $this->assertSame(CampaignEnrollment::STATUS_COMPLETED, $enrollment->status);
    }

    private function grantConsent(Contact $contact, string $channel, string $purpose): void
    {
        MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => $channel,
            'purpose' => $purpose,
            'scope' => 'webinar',
            'consented_at' => now(),
            'source' => 'test',
        ]);
    }

    private function messageEligibilityGate(bool $allowed = true): MessageEligibilityGate
    {
        $gate = Mockery::mock(MessageEligibilityGate::class);

        $gate
            ->shouldReceive('allows')
            ->once()
            ->andReturn($allowed);

        return $gate;
    }
    
}

class FakeJobEmailPayload implements EmailMessage
{
    public function __construct(
        private readonly string $to,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            to: $payload['to'],
        );
    }

    public function to(): string
    {
        return $this->to;
    }

    public function mailable(): Mailable
    {
        return new class extends Mailable {
            public function build(): static
            {
                return $this->subject('Test')->html('Test');
            }
        };
    }

    public function devPayload(): array
    {
        return [
            'to' => $this->to,
        ];
    }
}

class FakeJobSmsPayload implements SmsMessage
{
    public function __construct(
        private readonly string $to,
        private readonly string $message,
        private readonly string $purpose = 'transactional',
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            to: $payload['to'],
            message: $payload['message'],
            purpose: $payload['purpose'] ?? 'transactional',
        );
    }

    public function to(): string
    {
        return $this->to;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function kind(): string
    {
        return 'test_sms';
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function devPayload(): array
    {
        return [
            'to' => $this->to,
            'message' => $this->message,
            'purpose' => $this->purpose,
        ];
    }

    public function sourceIp(): ?string
    {
        return null;
    }
}
