<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Messaging\Actions\ClaimScheduledMessageForSendingAction;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Contracts\Email\EmailMessage;
use App\Modules\Messaging\Contracts\Sms\SmsMessage;
use App\Modules\Messaging\Data\Delivery\MessageSendResult;
use App\Modules\Messaging\Events\ScheduledMessageFailed;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
use App\Modules\Messaging\Services\Email\EmailMessagingService;
use App\Modules\Messaging\Services\ScheduledMessageGate;
use App\Modules\Messaging\Services\Sms\SmsMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SendScheduledMessageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_a_planned_email_even_when_its_provenance_path_has_moved(): void
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
            'definition_config_path' => 'messaging.email.definitions.moved.confirmation',
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
            ->with(Mockery::type(FakeJobEmailPayload::class))
            ->andReturn(MessageSendResult::sent(provider: 'test_email'));

        app()->instance(EmailMessagingService::class, $emailService);

        $this->handleScheduledMessage($scheduledMessage);

        $scheduledMessage->refresh();

        $this->assertSame('sent', $scheduledMessage->status);
        $this->assertNotNull($scheduledMessage->sent_at);
        $this->assertSame(1, $scheduledMessage->send_attempts);
        $this->assertSame('test_email', $scheduledMessage->provider);
        $this->assertNull($scheduledMessage->sending_at);

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
            ->with(Mockery::type(FakeJobSmsPayload::class))
            ->andReturn(MessageSendResult::sent(provider: 'test_sms'));

        app()->instance(SmsMessagingService::class, $smsService);

        $this->handleScheduledMessage($scheduledMessage);

        $scheduledMessage->refresh();

        $this->assertSame('sent', $scheduledMessage->status);
        $this->assertNotNull($scheduledMessage->sent_at);

        Event::assertDispatched(
            ScheduledMessageSent::class,
            fn (ScheduledMessageSent $event): bool => $event->scheduledMessage->is($scheduledMessage),
        );
    }


    public function test_it_sends_provider_ready_rendered_sms_while_stored_payload_remains_tokenized(): void
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
            'payload_class' => SmsPayload::class,
            'payload' => [
                'to' => '+15555550123',
                'message' => 'Hi {first_name}, join here: {webinar_join_url}',
                'tokens' => [
                    'first_name' => 'Jeff',
                    'webinar_join_url' => 'https://example.test/join/abc123',
                ],
            ],
            'status' => ScheduledMessage::STATUS_PENDING,
            'meta' => [
                'conditions' => [],
            ],
        ]);

        $capturedPayload = null;

        $smsService = Mockery::mock(SmsMessagingService::class);
        $smsService
            ->shouldReceive('send')
            ->once()
            ->with(Mockery::on(function (SmsMessage $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;

                return $payload instanceof SmsPayload
                    && $payload->message() === 'Hi Jeff, join here: https://example.test/join/abc123';
            }))
            ->andReturn(MessageSendResult::sent(provider: 'test_sms'));

        app()->instance(SmsMessagingService::class, $smsService);

        $this->handleScheduledMessage($scheduledMessage);

        $scheduledMessage->refresh();

        $this->assertSame(ScheduledMessage::STATUS_SENT, $scheduledMessage->status);
        $this->assertNotNull($scheduledMessage->sent_at);
        $this->assertInstanceOf(SmsPayload::class, $capturedPayload);
        $this->assertSame('Hi Jeff, join here: https://example.test/join/abc123', $capturedPayload->message());
        $this->assertSame('Hi {first_name}, join here: {webinar_join_url}', $scheduledMessage->payload['message']);
        $this->assertSame('Jeff', $scheduledMessage->payload['tokens']['first_name']);
        $this->assertSame('https://example.test/join/abc123', $scheduledMessage->payload['tokens']['webinar_join_url']);

        Event::assertDispatched(
            ScheduledMessageSent::class,
            fn (ScheduledMessageSent $event): bool => $event->scheduledMessage->is($scheduledMessage),
        );
    }

    public function test_it_does_not_resolve_legacy_token_containers_at_send_time(): void
    {
        Event::fake([
            ScheduledMessageSent::class,
            ScheduledMessageSkipped::class,
        ]);

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
            'payload_class' => SmsPayload::class,
            'payload' => [
                'to' => '+15555550123',
                'message' => 'Hi {first_name}, {webinar.title} starts at {webinar_start_time}.',
                'runtime_context' => [
                    'first_name' => 'Runtime',
                ],
                'context' => [
                    'webinar' => [
                        'title' => 'Legacy Webinar',
                    ],
                    'webinar_start_time' => '7:00 PM CDT',
                ],
                'tokens' => [
                    'first_name' => 'Jeff',
                ],
            ],
            'status' => ScheduledMessage::STATUS_PENDING,
            'meta' => [
                'conditions' => [],
            ],
        ]);

        $smsService = Mockery::mock(SmsMessagingService::class);
        $smsService->shouldNotReceive('send');

        app()->instance(SmsMessagingService::class, $smsService);

        $this->handleScheduledMessage($scheduledMessage);

        $scheduledMessage->refresh();

        $this->assertSame(
            ScheduledMessage::STATUS_SKIPPED,
            $scheduledMessage->status,
        );
        $this->assertStringContainsString(
            '{webinar.title}',
            (string) $scheduledMessage->skip_reason,
        );
        Event::assertDispatched(
            ScheduledMessageSkipped::class,
            fn (ScheduledMessageSkipped $event): bool => $event->scheduledMessage->is($scheduledMessage),
        );
        Event::assertNotDispatched(ScheduledMessageSent::class);
    }

    public function test_pending_destination_and_content_do_not_change_with_contact_or_config(): void
    {
        Queue::fake();
        Event::fake([ScheduledMessageSent::class]);

        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Original subject for {first_name}',
                    'body' => 'Original body for {first_name}.',
                ],
            ],
        ]);

        $contact = Contact::factory()->create([
            'first_name' => 'Jeff',
            'email' => 'original@example.com',
        ]);

        $this->grantConsent($contact, 'email', 'transactional');

        $messages = app(DispatchMessageAction::class)->handle(
            recipient: $contact,
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            dispatchKeys: 'registration_created',
            behavior: [
                'timing' => 'immediate',
            ],
        );

        $this->assertCount(1, $messages);

        $scheduledMessage = $messages[0];

        $contact->forceFill([
            'first_name' => 'Changed',
            'email' => 'changed@example.com',
        ])->save();

        Config::set(
            'messaging.email.definitions.transactional.webinar.confirmation.payload.subject',
            'Changed subject for {first_name}',
        );
        Config::set(
            'messaging.email.definitions.transactional.webinar.confirmation.payload.body',
            'Changed body for {first_name}.',
        );

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService
            ->shouldReceive('send')
            ->once()
            ->with(Mockery::on(
                fn (EmailMessage $payload): bool =>
                    $payload instanceof EmailPayload
                    && $payload->to() === 'original@example.com'
                    && $payload->subject() === 'Original subject for Jeff'
                    && $payload->text() === 'Original body for Jeff.',
            ))
            ->andReturn(MessageSendResult::sent(provider: 'test_email'));

        app()->instance(EmailMessagingService::class, $emailService);

        $this->handleScheduledMessage($scheduledMessage);

        $scheduledMessage->refresh();

        $this->assertSame(
            ScheduledMessage::STATUS_SENT,
            $scheduledMessage->status,
        );
        $this->assertSame(
            'original@example.com',
            $scheduledMessage->payload['to'],
        );
        $this->assertSame(
            'Jeff',
            $scheduledMessage->payload['tokens']['first_name'],
        );
    }

    public function test_it_sends_pending_sms_broadcast_message(): void
    {
        Event::fake([ScheduledMessageSent::class]);

        $contact = Contact::factory()->create([
            'phone' => '+15555550123',
        ]);

        $this->grantConsent($contact, 'sms', 'marketing', 'broadcast');

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
                'message' => 'This is an SMS broadcast.',
                'purpose' => 'marketing',
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
            ->with(Mockery::on(function (FakeJobSmsPayload $payload): bool {
                return $payload->to() === '+15555550123'
                    && $payload->message() === 'This is an SMS broadcast.'
                    && $payload->purpose() === 'marketing';
            }))
            ->andReturn(MessageSendResult::sent(provider: 'test_sms'));

        app()->instance(SmsMessagingService::class, $smsService);

        $this->handleScheduledMessage($scheduledMessage);

        $scheduledMessage->refresh();

        $this->assertSame(ScheduledMessage::STATUS_SENT, $scheduledMessage->status);
        $this->assertNotNull($scheduledMessage->sent_at);

        Event::assertDispatched(
            ScheduledMessageSent::class,
            fn (ScheduledMessageSent $event): bool => $event->scheduledMessage->is($scheduledMessage),
        );
    }

    public function test_it_sends_imported_contact_permission_invitation_once_without_existing_consent(): void
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
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'message_type' => ContactPermissionInvitationService::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'payload_class' => FakeJobEmailPayload::class,
            'payload' => [
                'to' => 'imported@example.com',
            ],
            'status' => 'pending',
            'meta' => [
                'conditions' => [],
                'consent_policy' => [
                    'permission_invitation' => [
                        'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
                        'one_time' => true,
                    ],
                ],
            ],
        ]);

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService
            ->shouldReceive('send')
            ->once()
            ->with(Mockery::type(FakeJobEmailPayload::class))
            ->andReturn(MessageSendResult::sent(provider: 'test_email'));

        app()->instance(EmailMessagingService::class, $emailService);

        $this->handleScheduledMessage($scheduledMessage);

        $scheduledMessage->refresh();

        $this->assertSame('sent', $scheduledMessage->status);
        $this->assertNotNull($scheduledMessage->sent_at);

        $this->assertDatabaseHas('contact_permission_invitations', [
            'contact_id' => $contact->id,
            'scheduled_message_id' => $scheduledMessage->id,
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
            'status' => ContactPermissionInvitation::STATUS_SENT,
        ]);

        Event::assertDispatched(
            ScheduledMessageSent::class,
            fn (ScheduledMessageSent $event): bool => $event->scheduledMessage->is($scheduledMessage),
        );
    }

    public function test_it_does_not_apply_imported_contact_permission_invitation_to_sms(): void
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
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'message_type' => ContactPermissionInvitationService::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'payload_class' => FakeJobSmsPayload::class,
            'payload' => [
                'to' => '+15555550123',
                'message' => 'Hello',
            ],
            'status' => 'pending',
            'meta' => [
                'conditions' => [],
                'consent_policy' => [
                    'permission_invitation' => [
                        'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
                        'one_time' => true,
                    ],
                ],
            ],
        ]);

        $smsService = Mockery::mock(SmsMessagingService::class);
        $smsService->shouldNotReceive('send');

        app()->instance(SmsMessagingService::class, $smsService);

        $this->handleScheduledMessage($scheduledMessage);

        $scheduledMessage->refresh();

        $this->assertSame('skipped', $scheduledMessage->status);
        $this->assertSame('Message eligibility gate denied send.', $scheduledMessage->skip_reason);
        $this->assertNull($scheduledMessage->sent_at);

        $this->assertDatabaseMissing('contact_permission_invitations', [
            'contact_id' => $contact->id,
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
        ]);

        Event::assertNotDispatched(ScheduledMessageSent::class);
    }

    public function test_it_skips_imported_contact_permission_invitation_when_already_used(): void
    {
        Event::fake([ScheduledMessageSent::class]);

        $contact = Contact::factory()->create([
            'email' => 'already-invited@example.com',
            'source' => 'import',
        ]);

        ContactPermissionInvitation::query()->create([
            'contact_id' => $contact->id,
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
            'status' => ContactPermissionInvitation::STATUS_SENT,
            'claimed_at' => now()->subMinutes(10),
            'sent_at' => now()->subMinutes(9),
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'message_type' => ContactPermissionInvitationService::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'payload_class' => FakeJobEmailPayload::class,
            'payload' => [
                'to' => 'already-invited@example.com',
            ],
            'status' => 'pending',
            'meta' => [
                'conditions' => [],
                'consent_policy' => [
                    'permission_invitation' => [
                        'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
                        'one_time' => true,
                    ],
                ],
            ],
        ]);

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService->shouldNotReceive('send');

        app()->instance(EmailMessagingService::class, $emailService);

        $this->handleScheduledMessage($scheduledMessage);

        $scheduledMessage->refresh();

        $this->assertSame('skipped', $scheduledMessage->status);
        $this->assertSame('Message eligibility gate denied send.', $scheduledMessage->skip_reason);
        $this->assertNull($scheduledMessage->sent_at);

        $this->assertSame(1, ContactPermissionInvitation::query()
            ->where('contact_id', $contact->id)
            ->where('channel', ContactPermissionInvitation::CHANNEL_EMAIL)
            ->where('source', ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT)
            ->count());

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

        $this->handleScheduledMessage($scheduledMessage);

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

        $this->handleScheduledMessage($scheduledMessage);

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

        $this->handleScheduledMessage(
            scheduledMessage: $scheduledMessage,
            scheduledMessageGate: $this->scheduledMessageGate('Message eligibility gate denied send.'),
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
            $this->handleScheduledMessage($scheduledMessage);
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

    public function test_it_injects_public_preference_url_into_permission_invitation_email_payload(): void
    {
        Event::fake([ScheduledMessageSent::class]);

        config([
            'messaging.permission_invitations.email.cta_label' => 'Confirm preferences',
            'messaging.permission_invitations.email.secondary_link_label' => 'Copy this link',
        ]);

        $contact = Contact::factory()->create([
            'email' => 'imported@example.com',
            'source' => 'import',
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'message_type' => ContactPermissionInvitationService::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'payload_class' => FakeJobPermissionInvitationEmailPayload::class,
            'payload' => [
                'to' => 'imported@example.com',
                'subject' => 'Confirm preferences',
                'body' => 'Please confirm preferences.',
            ],
            'status' => 'pending',
            'meta' => [
                'conditions' => [],
                'consent_policy' => [
                    'permission_invitation' => [
                        'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
                        'one_time' => true,
                    ],
                ],
            ],
        ]);

        $capturedPayload = null;

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService
            ->shouldReceive('send')
            ->once()
            ->with(Mockery::on(function (FakeJobPermissionInvitationEmailPayload $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;

                return true;
            }))
            ->andReturn(MessageSendResult::sent(provider: 'test_email'));

        app()->instance(EmailMessagingService::class, $emailService);

        $this->handleScheduledMessage($scheduledMessage);

        $scheduledMessage->refresh();

        $invitation = ContactPermissionInvitation::query()
            ->where('contact_id', $contact->id)
            ->first();

        $this->assertNotNull($invitation);
        $this->assertNotNull($invitation->token);
        $this->assertSame('sent', $scheduledMessage->status);

        $expectedUrl = route('messaging.permission-invitations.show', [
            'token' => $invitation->token,
        ]);

        $this->assertInstanceOf(FakeJobPermissionInvitationEmailPayload::class, $capturedPayload);
        $this->assertSame('Confirm preferences', $capturedPayload->cta['label']);
        $this->assertSame($expectedUrl, $capturedPayload->cta['url']);
        $this->assertSame('Copy this link', $capturedPayload->secondaryLink['label']);
        $this->assertSame($expectedUrl, $capturedPayload->secondaryLink['url']);
        $this->assertSame($expectedUrl, $capturedPayload->tokens['permission_invitation']['url']);
    }


    public function test_it_renders_permission_invitation_email_with_runtime_cta_and_secondary_link(): void
    {
        Event::fake([ScheduledMessageSent::class]);

        config([
            'messaging.permission_invitations.email.cta_label' => 'Confirm preferences',
            'messaging.permission_invitations.email.secondary_link_label' => 'Copy this link',
        ]);

        $contact = Contact::factory()->create([
            'email' => 'imported@example.com',
            'source' => 'import',
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'message_type' => ContactPermissionInvitationService::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'payload_class' => EmailPayload::class,
            'payload' => [
                'to' => 'imported@example.com',
                'subject' => 'Confirm your preferences',
                'body' => "Please confirm how you want to hear from us.
{cta}
Thanks.",
            ],
            'status' => 'pending',
            'meta' => [
                'conditions' => [],
                'consent_policy' => [
                    'permission_invitation' => [
                        'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
                        'one_time' => true,
                    ],
                ],
            ],
        ]);

        $capturedHtml = null;

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService
            ->shouldReceive('send')
            ->once()
            ->with(Mockery::on(function (EmailMessage $payload) use (&$capturedHtml): bool {
                if (! $payload instanceof EmailPayload) {
                    return false;
                }

                $capturedHtml = $payload->html();

                return true;
            }))
            ->andReturn(MessageSendResult::sent(provider: 'test_email'));

        app()->instance(EmailMessagingService::class, $emailService);

        $this->handleScheduledMessage($scheduledMessage);

        $scheduledMessage->refresh();

        $invitation = ContactPermissionInvitation::query()
            ->where('contact_id', $contact->id)
            ->first();

        $this->assertNotNull($invitation);
        $this->assertSame('sent', $scheduledMessage->status);

        $expectedUrl = route('messaging.permission-invitations.show', [
            'token' => $invitation->token,
        ]);

        $this->assertIsString($capturedHtml);
        $this->assertStringContainsString('Confirm preferences', $capturedHtml);
        $this->assertStringContainsString('href="'.$expectedUrl.'"', $capturedHtml);
        $this->assertStringContainsString('Copy this link', $capturedHtml);
        $this->assertStringContainsString($expectedUrl, $capturedHtml);
        $this->assertStringNotContainsString('{cta}', $capturedHtml);
    }

    public function test_it_uses_default_permission_invitation_email_labels_when_config_omits_them(): void
    {
        Event::fake([ScheduledMessageSent::class]);

        config([
            'messaging.permission_invitations.email' => [],
        ]);

        $contact = Contact::factory()->create([
            'email' => 'imported@example.com',
            'source' => 'import',
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'message_type' => ContactPermissionInvitationService::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'payload_class' => FakeJobPermissionInvitationEmailPayload::class,
            'payload' => [
                'to' => 'imported@example.com',
                'subject' => 'Confirm preferences',
                'body' => 'Please confirm preferences.',
            ],
            'status' => 'pending',
            'meta' => [
                'conditions' => [],
                'consent_policy' => [
                    'permission_invitation' => [
                        'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
                        'one_time' => true,
                    ],
                ],
            ],
        ]);

        $capturedPayload = null;

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService
            ->shouldReceive('send')
            ->once()
            ->with(Mockery::on(function (FakeJobPermissionInvitationEmailPayload $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;

                return true;
            }))
            ->andReturn(MessageSendResult::sent(provider: 'test_email'));

        app()->instance(EmailMessagingService::class, $emailService);

        $this->handleScheduledMessage($scheduledMessage);

        $scheduledMessage->refresh();

        $invitation = ContactPermissionInvitation::query()
            ->where('contact_id', $contact->id)
            ->first();

        $this->assertNotNull($invitation);

        $expectedUrl = route('messaging.permission-invitations.show', [
            'token' => $invitation->token,
        ]);

        $this->assertInstanceOf(FakeJobPermissionInvitationEmailPayload::class, $capturedPayload);
        $this->assertIsString($capturedPayload->cta['label']);
        $this->assertNotSame('', trim($capturedPayload->cta['label']));
        $this->assertSame($expectedUrl, $capturedPayload->cta['url']);
        $this->assertIsString($capturedPayload->secondaryLink['label']);
        $this->assertNotSame('', trim($capturedPayload->secondaryLink['label']));
        $this->assertSame($expectedUrl, $capturedPayload->secondaryLink['url']);
    }

    public function test_permission_invitation_public_url_uses_configured_base_url(): void
    {
        config([
            'messaging.permission_invitations.public.base_url' => 'https://crm.example.test/',
        ]);

        $contact = Contact::factory()->create([
            'email' => 'imported@example.com',
            'source' => 'import',
        ]);

        $invitation = ContactPermissionInvitation::query()->create([
            'contact_id' => $contact->id,
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
            'status' => ContactPermissionInvitation::STATUS_SENT,
            'token' => 'configured-base-url-token',
            'claimed_at' => now(),
            'sent_at' => now(),
        ]);

        $url = app(ContactPermissionInvitationService::class)->publicUrl($invitation);

        $this->assertSame(
            'https://crm.example.test/preferences/configured-base-url-token',
            $url,
        );
    }

    public function test_permission_invitation_page_renders_without_client_key(): void
    {
        $this->withoutVite();

        config([
            'app.client_key' => null,
            'client.key' => null,
            'messaging.permission_invitations.content' => [],
            'messaging.permission_invitations.style' => [],
        ]);

        $contact = Contact::factory()->create([
            'email' => 'imported@example.com',
            'source' => 'import',
        ]);

        $invitation = ContactPermissionInvitation::query()->create([
            'contact_id' => $contact->id,
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
            'status' => ContactPermissionInvitation::STATUS_SENT,
            'token' => 'public-page-token',
            'claimed_at' => now(),
            'sent_at' => now(),
        ]);

        $this->get(route('messaging.permission-invitations.show', ['token' => $invitation->token]))
            ->assertOk()
            ->assertSee('imported@example.com')
            ->assertSee('name="channels[]"', false)
            ->assertSee('value="email"', false);
    }

    public function test_permission_invitation_email_only_acceptance_renders_accepted_page_and_creates_consent(): void
    {
        $this->withoutVite();

        config([
            'app.client_key' => null,
            'client.key' => null,
            'messaging.permission_invitations.content' => [],
            'messaging.permission_invitations.style' => [],
            'messaging.permission_invitations.consent.scopes' => [
                'broadcast',
                'campaign',
            ],
        ]);

        $contact = Contact::factory()->create([
            'email' => 'imported@example.com',
            'source' => 'import',
        ]);

        $invitation = ContactPermissionInvitation::query()->create([
            'contact_id' => $contact->id,
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
            'status' => ContactPermissionInvitation::STATUS_SENT,
            'token' => 'email-only-acceptance-token',
            'claimed_at' => now(),
            'sent_at' => now(),
        ]);

        $this->post(route('messaging.permission-invitations.store', ['token' => $invitation->token]), [
            'channels' => ['email'],
        ])->assertRedirect(route('messaging.permission-invitations.show', ['token' => $invitation->token]));

        $invitation->refresh();

        $this->assertSame(ContactPermissionInvitation::STATUS_ACCEPTED, $invitation->status);
        $this->assertSame(['email'], $invitation->accepted_channels);

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'source' => 'imported_contact_permission_invitation',
        ]);

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign',
            'source' => 'imported_contact_permission_invitation',
        ]);

        $this->get(route('messaging.permission-invitations.show', ['token' => $invitation->token]))
            ->assertOk()
            ->assertSee('EMAIL')
            ->assertDontSee('name="channels[]"', false);
    }

    public function test_it_treats_contacts_with_import_batch_as_imported_for_permission_invitations(): void
    {
        Event::fake([ScheduledMessageSent::class]);

        $batch = ContactImportBatch::factory()->create();

        $contact = Contact::factory()->create([
            'email' => 'batch-imported@example.com',
            'source' => null,
            'contact_import_batch_id' => $batch->id,
            'meta' => [],
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'message_type' => ContactPermissionInvitationService::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'payload_class' => FakeJobEmailPayload::class,
            'payload' => [
                'to' => 'batch-imported@example.com',
            ],
            'status' => 'pending',
            'meta' => [
                'conditions' => [],
                'consent_policy' => [
                    'permission_invitation' => [
                        'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
                        'one_time' => true,
                    ],
                ],
            ],
        ]);

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService
            ->shouldReceive('send')
            ->once()
            ->with(Mockery::type(FakeJobEmailPayload::class))
            ->andReturn(MessageSendResult::sent(provider: 'test_email'));

        app()->instance(EmailMessagingService::class, $emailService);

        $this->handleScheduledMessage($scheduledMessage);

        $scheduledMessage->refresh();

        $this->assertSame(ScheduledMessage::STATUS_SENT, $scheduledMessage->status);

        $this->assertDatabaseHas('contact_permission_invitations', [
            'contact_id' => $contact->id,
            'scheduled_message_id' => $scheduledMessage->id,
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
            'status' => ContactPermissionInvitation::STATUS_SENT,
        ]);

        Event::assertDispatched(
            ScheduledMessageSent::class,
            fn (ScheduledMessageSent $event): bool => $event->scheduledMessage->is($scheduledMessage),
        );
    }

    public function test_it_skips_email_before_send_when_payload_contains_unresolved_token(): void
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
            'payload_class' => EmailPayload::class,
            'payload' => [
                'to' => 'test@example.com',
                'subject' => 'Hello {missing_token}',
                'body' => 'This message should not send.',
            ],
            'status' => ScheduledMessage::STATUS_PENDING,
            'meta' => [
                'conditions' => [],
            ],
        ]);

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService->shouldNotReceive('send');

        app()->instance(EmailMessagingService::class, $emailService);

        $this->handleScheduledMessage($scheduledMessage);

        $scheduledMessage->refresh();

        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $scheduledMessage->status);
        $this->assertStringContainsString(
            'Message payload contains unresolved token(s): {missing_token}.',
            (string) $scheduledMessage->skip_reason,
        );
        $this->assertNull($scheduledMessage->sent_at);
        $this->assertNull($scheduledMessage->failure_reason);

        Event::assertNotDispatched(ScheduledMessageSent::class);
    }

    private function grantConsent(
        Contact $contact,
        string $channel,
        string $purpose,
        string $scope = 'webinar',
    ): void
    {
        MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => $channel,
            'purpose' => $purpose,
            'scope' => $scope,
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

    public function test_atomic_claim_prevents_a_second_worker_from_claiming_the_same_message(): void
    {
        $scheduledMessage = ScheduledMessage::factory()->create([
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);

        $action = app(ClaimScheduledMessageForSendingAction::class);
        $firstClaim = $action->handle($scheduledMessage);
        $secondClaim = $action->handle($scheduledMessage);

        $this->assertInstanceOf(ScheduledMessage::class, $firstClaim);
        $this->assertSame(ScheduledMessage::STATUS_SENDING, $firstClaim->status);
        $this->assertSame(1, $firstClaim->send_attempts);
        $this->assertNull($secondClaim);
        $this->assertSame(ScheduledMessage::STATUS_SENDING, $scheduledMessage->refresh()->status);
    }

    public function test_service_skip_result_marks_message_skipped_instead_of_sent(): void
    {
        Event::fake([
            ScheduledMessageSent::class,
            ScheduledMessageSkipped::class,
        ]);

        $contact = Contact::factory()->create([
            'phone' => '+15555550123',
        ]);
        $this->grantConsent($contact, 'sms', 'transactional');

        $scheduledMessage = ScheduledMessage::factory()->sms()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->getKey(),
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => FakeJobSmsPayload::class,
            'payload' => [
                'to' => '+15555550123',
                'message' => 'Hello',
            ],
            'meta' => ['conditions' => []],
        ]);

        $smsService = Mockery::mock(SmsMessagingService::class);
        $smsService->shouldReceive('send')
            ->once()
            ->andReturn(MessageSendResult::skipped(
                reasonCode: 'sms_disabled',
                reason: 'SMS delivery is disabled.',
            ));
        app()->instance(SmsMessagingService::class, $smsService);

        $this->handleScheduledMessage($scheduledMessage);

        $scheduledMessage->refresh();

        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $scheduledMessage->status);
        $this->assertSame('SMS delivery is disabled.', $scheduledMessage->skip_reason);
        $this->assertSame('sms_disabled', data_get($scheduledMessage->meta, 'delivery.reason_code'));
        $this->assertNull($scheduledMessage->sent_at);
        $this->assertNull($scheduledMessage->sending_at);
        Event::assertNotDispatched(ScheduledMessageSent::class);
        Event::assertDispatched(ScheduledMessageSkipped::class);
    }

    public function test_retryable_exception_returns_message_to_pending_for_another_attempt(): void
    {
        Event::fake([ScheduledMessageFailed::class]);

        $contact = Contact::factory()->create([
            'email' => 'retry@example.com',
        ]);
        $this->grantConsent($contact, 'email', 'transactional');

        $scheduledMessage = ScheduledMessage::factory()->email()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->getKey(),
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => FakeJobEmailPayload::class,
            'payload' => ['to' => 'retry@example.com'],
            'meta' => ['conditions' => []],
        ]);

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Temporary provider outage.'));
        app()->instance(EmailMessagingService::class, $emailService);

        try {
            $this->handleScheduledMessage($scheduledMessage);
            $this->fail('Expected retryable delivery exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Temporary provider outage.', $exception->getMessage());
        }

        $scheduledMessage->refresh();

        $this->assertSame(ScheduledMessage::STATUS_PENDING, $scheduledMessage->status);
        $this->assertSame(1, $scheduledMessage->send_attempts);
        $this->assertNull($scheduledMessage->sending_at);
        $this->assertNull($scheduledMessage->failed_at);
        $this->assertSame('Temporary provider outage.', $scheduledMessage->failure_reason);
        $this->assertTrue((bool) data_get($scheduledMessage->meta, 'delivery.retryable'));
        Event::assertNotDispatched(ScheduledMessageFailed::class);
    }

    public function test_retryable_exception_marks_message_failed_on_final_delivery_attempt(): void
    {
        Event::fake([ScheduledMessageFailed::class]);

        $contact = Contact::factory()->create([
            'email' => 'final-attempt@example.com',
        ]);
        $this->grantConsent($contact, 'email', 'transactional');

        $scheduledMessage = ScheduledMessage::factory()->email()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->getKey(),
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => FakeJobEmailPayload::class,
            'payload' => ['to' => 'final-attempt@example.com'],
            'send_attempts' => 2,
            'meta' => ['conditions' => []],
        ]);

        $emailService = Mockery::mock(EmailMessagingService::class);
        $emailService->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Provider still unavailable.'));
        app()->instance(EmailMessagingService::class, $emailService);

        try {
            $this->handleScheduledMessage($scheduledMessage);
            $this->fail('Expected terminal delivery exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Provider still unavailable.', $exception->getMessage());
        }

        $scheduledMessage->refresh();

        $this->assertSame(ScheduledMessage::STATUS_FAILED, $scheduledMessage->status);
        $this->assertSame(3, $scheduledMessage->send_attempts);
        $this->assertNotNull($scheduledMessage->failed_at);
        $this->assertNull($scheduledMessage->sending_at);
        Event::assertDispatched(ScheduledMessageFailed::class);
    }

    private function handleScheduledMessage(
        ScheduledMessage $scheduledMessage,
        ?ScheduledMessageGate $scheduledMessageGate = null,
    ): void {
        (new SendScheduledMessageJob($scheduledMessage->id))->handle(
            claimScheduledMessage: app(ClaimScheduledMessageForSendingAction::class),
            scheduledMessageGate: $scheduledMessageGate ?? app(ScheduledMessageGate::class),
            emailMessagingService: app(EmailMessagingService::class),
            smsMessagingService: app(SmsMessagingService::class),
            permissionInvitationService: app(ContactPermissionInvitationService::class),
        );
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

class FakeJobPermissionInvitationEmailPayload implements EmailMessage
{
    public function __construct(
        private readonly string $to,
        public readonly array $tokens = [],
        public readonly array $cta = [],
        public readonly array $secondaryLink = [],
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            to: $payload['to'],
            tokens: is_array($payload['tokens'] ?? null) ? $payload['tokens'] : [],
            cta: is_array($payload['cta'] ?? null) ? $payload['cta'] : [],
            secondaryLink: is_array($payload['secondary_link'] ?? null) ? $payload['secondary_link'] : [],
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
            'tokens' => $this->tokens,
            'cta' => $this->cta,
            'secondary_link' => $this->secondaryLink,
        ];
    }
}