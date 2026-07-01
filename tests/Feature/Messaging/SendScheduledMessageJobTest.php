<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Contracts\Email\EmailMessage;
use App\Modules\Messaging\Contracts\Sms\SmsMessage;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\Email\EmailMessagingService;
use App\Modules\Messaging\Services\ScheduledMessageGate;
use App\Modules\Messaging\Services\Sms\SmsMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class SendScheduledMessageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_pending_email_message(): void
    {
        Event::fake([ScheduledMessageSent::class]);

        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
        ]);

        $this->grantConsent($contact, 'email', 'transactional');

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
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
            ],
        ]);

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService
            ->shouldReceive('send')
            ->once()
            ->with(Mockery::type(FakeJobEmailPayload::class));

        app()->instance(EmailMessagingService::class, $emailService);

        (new SendScheduledMessageJob($scheduledMessage->id))->handle(
            scheduledMessageGate: app(ScheduledMessageGate::class),
            emailMessagingService: app(EmailMessagingService::class),
            smsMessagingService: app(SmsMessagingService::class),
        );

        $scheduledMessage->refresh();

        $this->assertSame('sent', $scheduledMessage->status);
        $this->assertNotNull($scheduledMessage->sent_at);

        Event::assertDispatched(
            ScheduledMessageSent::class,
            fn (ScheduledMessageSent $event): bool => $event->scheduledMessage->is($scheduledMessage),
        );
    }

    public function test_it_sends_pending_sms_message(): void
    {
        Event::fake([ScheduledMessageSent::class]);

        $contact = Contact::factory()->create([
            'phone' => '+15555550123',
        ]);

        $this->grantConsent($contact, 'sms', 'transactional');

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
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
            ],
        ]);

        $smsService = Mockery::mock(SmsMessagingService::class);
        $smsService
            ->shouldReceive('send')
            ->once()
            ->with(Mockery::type(FakeJobSmsPayload::class));

        app()->instance(SmsMessagingService::class, $smsService);

        (new SendScheduledMessageJob($scheduledMessage->id))->handle(
            scheduledMessageGate: app(ScheduledMessageGate::class),
            emailMessagingService: app(EmailMessagingService::class),
            smsMessagingService: app(SmsMessagingService::class),
        );

        $scheduledMessage->refresh();

        $this->assertSame('sent', $scheduledMessage->status);
        $this->assertNotNull($scheduledMessage->sent_at);

        Event::assertDispatched(
            ScheduledMessageSent::class,
            fn (ScheduledMessageSent $event): bool => $event->scheduledMessage->is($scheduledMessage),
        );
    }

    public function test_it_sends_imported_contact_marketing_email_with_permission_pass_without_consent(): void
    {
        Event::fake([ScheduledMessageSent::class]);

        $contact = Contact::factory()->create([
            'email' => 'imported@example.com',
            'source' => 'import',
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'message_type' => 'broadcast',
            'payload_class' => FakeJobEmailPayload::class,
            'payload' => [
                'to' => 'imported@example.com',
            ],
            'status' => 'pending',
            'meta' => [
                'conditions' => [],
                'consent_policy' => [
                    'imported_contact_permission_pass' => true,
                ],
            ],
        ]);

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService
            ->shouldReceive('send')
            ->once()
            ->with(Mockery::type(FakeJobEmailPayload::class));

        app()->instance(EmailMessagingService::class, $emailService);

        (new SendScheduledMessageJob($scheduledMessage->id))->handle(
            scheduledMessageGate: app(ScheduledMessageGate::class),
            emailMessagingService: app(EmailMessagingService::class),
            smsMessagingService: app(SmsMessagingService::class),
        );

        $scheduledMessage->refresh();

        $this->assertSame('sent', $scheduledMessage->status);
        $this->assertNotNull($scheduledMessage->sent_at);

        Event::assertDispatched(
            ScheduledMessageSent::class,
            fn (ScheduledMessageSent $event): bool => $event->scheduledMessage->is($scheduledMessage),
        );
    }

    public function test_it_does_not_apply_imported_contact_permission_pass_to_sms(): void
    {
        Event::fake([ScheduledMessageSent::class]);

        $contact = Contact::factory()->create([
            'phone' => '+15555550123',
            'source' => 'import',
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'message_type' => 'broadcast',
            'payload_class' => FakeJobSmsPayload::class,
            'payload' => [
                'to' => '+15555550123',
                'message' => 'Hello',
            ],
            'status' => 'pending',
            'meta' => [
                'conditions' => [],
                'consent_policy' => [
                    'imported_contact_permission_pass' => true,
                ],
            ],
        ]);

        $smsService = Mockery::mock(SmsMessagingService::class);
        $smsService->shouldNotReceive('send');

        app()->instance(SmsMessagingService::class, $smsService);

        (new SendScheduledMessageJob($scheduledMessage->id))->handle(
            scheduledMessageGate: app(ScheduledMessageGate::class),
            emailMessagingService: app(EmailMessagingService::class),
            smsMessagingService: app(SmsMessagingService::class),
        );

        $scheduledMessage->refresh();

        $this->assertSame('skipped', $scheduledMessage->status);
        $this->assertSame('Message eligibility gate denied send.', $scheduledMessage->skip_reason);
        $this->assertNull($scheduledMessage->sent_at);

        Event::assertNotDispatched(ScheduledMessageSent::class);
    }

    public function test_it_skips_when_conditions_fail_at_send_time(): void
    {
        Event::fake([ScheduledMessageSent::class]);

        $contact = Contact::factory()->create([
            'source' => 'webinar',
            'email' => 'test@example.com',
        ]);

        $this->grantConsent($contact, 'email', 'transactional');

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
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
                    'contact.source_not_in' => [
                        'webinar',
                    ],
                ],
            ],
        ]);

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService->shouldNotReceive('send');

        app()->instance(EmailMessagingService::class, $emailService);

        (new SendScheduledMessageJob($scheduledMessage->id))->handle(
            scheduledMessageGate: app(ScheduledMessageGate::class),
            emailMessagingService: app(EmailMessagingService::class),
            smsMessagingService: app(SmsMessagingService::class),
        );

        $scheduledMessage->refresh();

        $this->assertSame('skipped', $scheduledMessage->status);
        $this->assertSame(
            'Message conditions no longer pass.',
            $scheduledMessage->skip_reason
        );
        $this->assertNull($scheduledMessage->failure_reason);

        Event::assertNotDispatched(ScheduledMessageSent::class);
    }

    public function test_it_skips_when_consent_was_revoked_before_send(): void
    {
        Event::fake([ScheduledMessageSent::class]);

        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
        ]);

        $this->grantConsent($contact, 'email', 'marketing');

        ConsentRevocation::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'revoked_at' => now(),
            'source' => 'test',
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
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
            ],
        ]);

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService->shouldNotReceive('send');

        app()->instance(EmailMessagingService::class, $emailService);

        (new SendScheduledMessageJob($scheduledMessage->id))->handle(
            scheduledMessageGate: app(ScheduledMessageGate::class),
            emailMessagingService: app(EmailMessagingService::class),
            smsMessagingService: app(SmsMessagingService::class),
        );

        $scheduledMessage->refresh();

        $this->assertSame('skipped', $scheduledMessage->status);
        $this->assertNotNull($scheduledMessage->skip_reason);
        $this->assertNull($scheduledMessage->failure_reason);
        $this->assertNull($scheduledMessage->sent_at);

        Event::assertNotDispatched(ScheduledMessageSent::class);
    }

    public function test_it_skips_when_gate_denies_send(): void
    {
        Event::fake([ScheduledMessageSent::class]);

        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
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
            ],
        ]);

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService->shouldNotReceive('send');

        app()->instance(EmailMessagingService::class, $emailService);

        (new SendScheduledMessageJob($scheduledMessage->id))->handle(
            scheduledMessageGate: $this->scheduledMessageGate('Message eligibility gate denied send.'),
            emailMessagingService: app(EmailMessagingService::class),
            smsMessagingService: app(SmsMessagingService::class),
        );

        $scheduledMessage->refresh();

        $this->assertSame('skipped', $scheduledMessage->status);
        $this->assertSame(
            'Message eligibility gate denied send.',
            $scheduledMessage->skip_reason
        );
        $this->assertNull($scheduledMessage->failure_reason);

        Event::assertNotDispatched(ScheduledMessageSent::class);
    }

    public function test_it_marks_failed_when_payload_class_is_invalid(): void
    {
        Event::fake([ScheduledMessageSent::class]);

        $contact = Contact::factory()->create([
            'email' => 'test@example.com',
        ]);

        $this->grantConsent($contact, 'email', 'transactional');

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
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
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);

        try {
            (new SendScheduledMessageJob($scheduledMessage->id))->handle(
                scheduledMessageGate: app(ScheduledMessageGate::class),
                emailMessagingService: app(EmailMessagingService::class),
                smsMessagingService: app(SmsMessagingService::class),
            );
        } finally {
            $scheduledMessage->refresh();

            $this->assertSame('failed', $scheduledMessage->status);
            $this->assertSame(
                'Scheduled message payload class is invalid.',
                $scheduledMessage->failure_reason
            );

            Event::assertNotDispatched(ScheduledMessageSent::class);
        }
    }

    private function grantConsent(Contact $contact, string $channel, string $purpose): void
    {
        MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => $channel,
            'purpose' => $purpose,
            'scope' => 'webinar',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);
    }

    private function scheduledMessageGate(?string $denialReason = null): ScheduledMessageGate
    {
        $gate = Mockery::mock(ScheduledMessageGate::class);

        $gate
            ->shouldReceive('denialReason')
            ->once()
            ->with(Mockery::type(ScheduledMessage::class))
            ->andReturn($denialReason);

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