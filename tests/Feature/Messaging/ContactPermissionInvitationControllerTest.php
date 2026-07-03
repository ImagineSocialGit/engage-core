<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContactPermissionInvitationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'messaging.permission_invitations.consent.scopes' => [
                'broadcast',
                'campaign',
            ],
            'messaging.channel_availability.email.runtime_supported' => true,
            'messaging.channel_availability.email.provider_enabled' => true,
            'messaging.channel_availability.email.surfaces.permission_invitations' => true,
            'messaging.channel_availability.email.purpose_scopes' => ['*' => true],
            'messaging.channel_availability.sms.runtime_supported' => true,
            'messaging.channel_availability.sms.provider_enabled' => false,
            'messaging.channel_availability.sms.surfaces.permission_invitations' => false,
            'messaging.channel_availability.sms.purpose_scopes' => ['*' => true],
        ]);
    }

    public function test_public_invitation_page_renders_email_option_from_token(): void
    {
        $invitation = $this->invitation();

        $response = $this->get(route('messaging.permission-invitations.show', [
            'token' => $invitation->token,
        ]));

        $response->assertOk();
        $response->assertSee($invitation->contact->email);
        $response->assertSee('name="channels[]"', false);
        $response->assertSee('value="email"', false);
        $response->assertDontSee('value="sms"', false);
        $response->assertDontSee('name="phone"', false);
    }

    public function test_public_invitation_page_renders_sms_option_when_enabled_for_permission_invitations(): void
    {
        $this->enablePermissionInvitationSms();

        $invitation = $this->invitation();

        $response = $this->get(route('messaging.permission-invitations.show', [
            'token' => $invitation->token,
        ]));

        $response->assertOk();
        $response->assertSee('value="email"', false);
        $response->assertSee('value="sms"', false);
        $response->assertSee('name="phone"', false);
    }

    public function test_invalid_token_returns_404(): void
    {
        $this->get(route('messaging.permission-invitations.show', [
            'token' => 'missing-token',
        ]))->assertNotFound();

        $this->post(route('messaging.permission-invitations.store', [
            'token' => 'missing-token',
        ]), [
            'channels' => ['email'],
        ])->assertNotFound();
    }

    public function test_contact_can_opt_into_email(): void
    {
        $invitation = $this->invitation();

        $response = $this->post(route('messaging.permission-invitations.store', [
            'token' => $invitation->token,
        ]), [
            'channels' => ['email'],
        ]);

        $response->assertRedirect(route('messaging.permission-invitations.show', [
            'token' => $invitation->token,
        ]));

        $invitation->refresh();

        $this->assertSame(ContactPermissionInvitation::STATUS_ACCEPTED, $invitation->status);
        $this->assertNotNull($invitation->accepted_at);
        $this->assertSame(['email'], $invitation->accepted_channels);

        foreach (['broadcast', 'campaign'] as $scope) {
            $this->assertDatabaseHas('message_consents', [
                'contact_id' => $invitation->contact_id,
                'channel' => MessageChannel::Email->value,
                'purpose' => MessagePurpose::Marketing->value,
                'scope' => $scope,
                'source' => 'imported_contact_permission_invitation',
            ]);
        }

        $this->assertSame(2, MessageConsent::query()->count());
    }

    public function test_accepting_invitation_emits_permission_invitation_accepted_automation_event(): void
    {
        Event::fake([AutomationEventRecorded::class]);

        $invitation = $this->invitation();

        $response = $this->post(route('messaging.permission-invitations.store', [
            'token' => $invitation->token,
        ]), [
            'channels' => ['email'],
        ]);

        $response->assertRedirect(route('messaging.permission-invitations.show', [
            'token' => $invitation->token,
        ]));

        $invitation->refresh();

        Event::assertDispatched(
            AutomationEventRecorded::class,
            function (AutomationEventRecorded $event) use ($invitation): bool {
                return $event->event->eventKey === 'permission_invitation.accepted'
                    && $event->event->contactId === $invitation->contact_id
                    && $event->event->subjectType === $invitation->getMorphClass()
                    && $event->event->subjectId === $invitation->getKey()
                    && ($event->event->payload['permission_invitation']['accepted_channels'] ?? null) === ['email']
                    && ($event->event->payload['permission_invitation']['consent_scopes'] ?? null) === ['broadcast', 'campaign']
                    && ($event->event->meta['source_module'] ?? null) === 'messaging'
                    && ($event->event->meta['source'] ?? null) === 'imported_contact_permission_invitation';
            },
        );
    }

    public function test_sms_opt_in_is_rejected_when_hidden_for_permission_invitations(): void
    {
        $invitation = $this->invitation();

        $response = $this->from(route('messaging.permission-invitations.show', [
            'token' => $invitation->token,
        ]))->post(route('messaging.permission-invitations.store', [
            'token' => $invitation->token,
        ]), [
            'channels' => ['sms'],
            'phone' => '+15555550123',
        ]);

        $response->assertSessionHasErrors('channels.0');

        $invitation->refresh();

        $this->assertNull($invitation->accepted_at);
        $this->assertSame(0, MessageConsent::query()->count());
    }

    public function test_contact_can_opt_into_sms_with_phone_when_sms_is_enabled(): void
    {
        $this->enablePermissionInvitationSms();

        $invitation = $this->invitation();

        $response = $this->post(route('messaging.permission-invitations.store', [
            'token' => $invitation->token,
        ]), [
            'channels' => ['sms'],
            'phone' => '+15555550123',
        ]);

        $response->assertRedirect(route('messaging.permission-invitations.show', [
            'token' => $invitation->token,
        ]));

        $invitation->refresh();
        $invitation->contact->refresh();

        $this->assertSame(['sms'], $invitation->accepted_channels);
        $this->assertSame('+15555550123', $invitation->contact->phone);

        foreach (['broadcast', 'campaign'] as $scope) {
            $this->assertDatabaseHas('message_consents', [
                'contact_id' => $invitation->contact_id,
                'channel' => MessageChannel::Sms->value,
                'purpose' => MessagePurpose::Marketing->value,
                'scope' => $scope,
                'source' => 'imported_contact_permission_invitation',
            ]);
        }

        $this->assertSame(2, MessageConsent::query()->count());
    }

    public function test_sms_opt_in_requires_phone_when_sms_is_enabled(): void
    {
        $this->enablePermissionInvitationSms();

        $invitation = $this->invitation();

        $response = $this->from(route('messaging.permission-invitations.show', [
            'token' => $invitation->token,
        ]))->post(route('messaging.permission-invitations.store', [
            'token' => $invitation->token,
        ]), [
            'channels' => ['sms'],
        ]);

        $response->assertSessionHasErrors('phone');

        $invitation->refresh();

        $this->assertNull($invitation->accepted_at);
        $this->assertSame(0, MessageConsent::query()->count());
    }

    public function test_contact_can_opt_into_email_and_sms_when_sms_is_enabled(): void
    {
        $this->enablePermissionInvitationSms();

        $invitation = $this->invitation();

        $response = $this->post(route('messaging.permission-invitations.store', [
            'token' => $invitation->token,
        ]), [
            'channels' => ['email', 'sms'],
            'phone' => '+15555550123',
        ]);

        $response->assertRedirect(route('messaging.permission-invitations.show', [
            'token' => $invitation->token,
        ]));

        $invitation->refresh();
        $invitation->contact->refresh();

        $this->assertSame(['email', 'sms'], $invitation->accepted_channels);
        $this->assertSame('+15555550123', $invitation->contact->phone);

        $this->assertSame(4, MessageConsent::query()->count());

        foreach (['email', 'sms'] as $channel) {
            foreach (['broadcast', 'campaign'] as $scope) {
                $this->assertDatabaseHas('message_consents', [
                    'contact_id' => $invitation->contact_id,
                    'channel' => $channel,
                    'purpose' => MessagePurpose::Marketing->value,
                    'scope' => $scope,
                    'source' => 'imported_contact_permission_invitation',
                ]);
            }
        }
    }

    public function test_accepted_invitation_shows_confirmation_page(): void
    {
        $invitation = $this->invitation([
            'status' => ContactPermissionInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'accepted_channels' => ['email', 'sms'],
        ]);

        $response = $this->get(route('messaging.permission-invitations.show', [
            'token' => $invitation->token,
        ]));

        $response->assertOk();
        $response->assertSee('EMAIL, SMS');
    }

    public function test_already_accepted_invitation_cannot_create_duplicate_consent_rows(): void
    {
        $this->enablePermissionInvitationSms();

        $invitation = $this->invitation([
            'status' => ContactPermissionInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'accepted_channels' => ['email'],
        ]);

        MessageConsent::query()->create([
            'contact_id' => $invitation->contact_id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'broadcast',
            'consented_at' => now()->subMinute(),
            'source' => 'imported_contact_permission_invitation',
        ]);

        $response = $this->post(route('messaging.permission-invitations.store', [
            'token' => $invitation->token,
        ]), [
            'channels' => ['email', 'sms'],
            'phone' => '+15555550123',
        ]);

        $response->assertRedirect(route('messaging.permission-invitations.show', [
            'token' => $invitation->token,
        ]));

        $invitation->refresh();
        $invitation->contact->refresh();

        $this->assertSame(['email'], $invitation->accepted_channels);
        $this->assertNull($invitation->contact->phone);
        $this->assertSame(1, MessageConsent::query()->count());
    }

    public function test_already_accepted_invitation_does_not_emit_permission_invitation_accepted_automation_event(): void
    {
        Event::fake([AutomationEventRecorded::class]);

        $this->enablePermissionInvitationSms();

        $invitation = $this->invitation([
            'status' => ContactPermissionInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'accepted_channels' => ['email'],
        ]);

        MessageConsent::query()->create([
            'contact_id' => $invitation->contact_id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'broadcast',
            'consented_at' => now()->subMinute(),
            'source' => 'imported_contact_permission_invitation',
        ]);

        $response = $this->post(route('messaging.permission-invitations.store', [
            'token' => $invitation->token,
        ]), [
            'channels' => ['email', 'sms'],
            'phone' => '+15555550123',
        ]);

        $response->assertRedirect(route('messaging.permission-invitations.show', [
            'token' => $invitation->token,
        ]));

        Event::assertNotDispatched(AutomationEventRecorded::class);
    }

    public function test_acceptance_falls_back_to_broadcast_scope_when_scope_config_is_missing(): void
    {
        config([
            'messaging.permission_invitations.consent.scopes' => null,
        ]);

        $invitation = $this->invitation();

        $response = $this->post(route('messaging.permission-invitations.store', [
            'token' => $invitation->token,
        ]), [
            'channels' => ['email'],
        ]);

        $response->assertRedirect(route('messaging.permission-invitations.show', [
            'token' => $invitation->token,
        ]));

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $invitation->contact_id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'broadcast',
            'source' => 'imported_contact_permission_invitation',
        ]);

        $this->assertSame(1, MessageConsent::query()->count());
    }

    private function enablePermissionInvitationSms(): void
    {
        config([
            'messaging.channel_availability.sms.provider_enabled' => true,
            'messaging.channel_availability.sms.surfaces.permission_invitations' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function invitation(array $attributes = []): ContactPermissionInvitation
    {
        $contact = Contact::factory()->create([
            'email' => 'imported@example.test',
            'source' => 'import',
            'phone' => null,
        ]);

        return ContactPermissionInvitation::query()->create(array_replace([
            'contact_id' => $contact->id,
            'token' => Str::random(64),
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
            'status' => ContactPermissionInvitation::STATUS_SENT,
            'claimed_at' => now()->subMinutes(5),
            'sent_at' => now()->subMinutes(4),
            'meta' => [],
        ], $attributes));
    }
}