<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Messaging\Contracts\Email\EmailMessage;
use App\Modules\Messaging\Contracts\Sms\SmsMessage;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\ContactPermissionInvitationService;
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

        $this->handleScheduledMessage($scheduledMessage);

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

        $this->handleScheduledMessage($scheduledMessage);

        $scheduledMessage->refresh();

        $this->assertSame('sent', $scheduledMessage->status);
        $this->assertNotNull($scheduledMessage->sent_at);

        Event::assertDispatched(
            ScheduledMessageSent::class,
            fn (ScheduledMessageSent $event): bool => $event->scheduledMessage->is($scheduledMessage),
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
            }));

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
            ->with(Mockery::type(FakeJobEmailPayload::class));

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
            }));

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
            }));

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
            }));

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
        $this->assertSame('Confirm my preferences', $capturedPayload->cta['label']);
        $this->assertSame($expectedUrl, $capturedPayload->cta['url']);
        $this->assertSame('Or copy and paste this link into your browser', $capturedPayload->secondaryLink['label']);
        $this->assertSame($expectedUrl, $capturedPayload->secondaryLink['url']);
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
            ->with(Mockery::type(FakeJobEmailPayload::class));

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

    private function handleScheduledMessage(
        ScheduledMessage $scheduledMessage,
        ?ScheduledMessageGate $scheduledMessageGate = null,
    ): void {
        (new SendScheduledMessageJob($scheduledMessage->id))->handle(
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