<?php

namespace Tests\Feature\Portal;

use App\Modules\Messaging\Data\Delivery\ScheduledMessageTerminalResult;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\MessageRecipientGateRegistry;
use App\Modules\Messaging\Services\MessageRecipientPayloadProviderRegistry;
use App\Modules\Portal\Actions\CreatePortalInvitationAction;
use App\Modules\Portal\Actions\DeliverPortalInvitationAction;
use App\Modules\Portal\Contracts\PortalAccountNotificationTransport;
use App\Modules\Portal\Models\PortalInvitation;
use App\Modules\Portal\Models\PortalUser;
use App\Modules\Portal\Providers\PortalModuleServiceProvider;
use App\Modules\Portal\Services\PortalSecretLinkCodec;
use App\Providers\Modules\IntegrationsModuleServiceProvider;
use App\Support\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class PortalMessagingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->enablePortalMessagingRuntime();
    }

    public function test_invitation_delivery_uses_messaging_without_persisting_the_raw_secret(): void
    {
        $issued = app(CreatePortalInvitationAction::class)->handle(
            email: 'customer@example.test',
            channel: 'email',
        );

        app(DeliverPortalInvitationAction::class)->handle($issued);

        $message = ScheduledMessage::query()
            ->where('scope', 'portal')
            ->where('message_type', 'portal_invitation')
            ->sole();

        $payload = json_encode($message->payload, JSON_THROW_ON_ERROR);

        $this->assertSame(PortalInvitation::STATUS_PENDING, $issued->invitation->fresh()->status);
        $this->assertSame($issued->invitation->getMorphClass(), $message->recipient_type);
        $this->assertSame($issued->invitation->getKey(), $message->recipient_id);
        $this->assertSame($issued->invitation->getMorphClass(), $message->context_type);
        $this->assertSame('transactional', $message->purpose);
        $this->assertStringNotContainsString($issued->token, $payload);
        $this->assertStringContainsString('e1_', $payload);

        Queue::assertPushed(SendScheduledMessageJob::class);

        $message->forceFill([
            'status' => ScheduledMessage::STATUS_SENT,
        ])->save();

        event(new ScheduledMessageSent(
            scheduledMessage: $message->fresh(),
            terminalResult: new ScheduledMessageTerminalResult(
                scheduledMessageId: (int) $message->getKey(),
                status: ScheduledMessage::STATUS_SENT,
                occurredAt: CarbonImmutable::now('UTC'),
            ),
        ));

        $invitation = $issued->invitation->fresh();

        $this->assertSame(PortalInvitation::STATUS_SENT, $invitation->status);
        $this->assertNotNull($invitation->sent_at);

        $activationUrl = data_get($message->payload, 'cta.url');

        $this->assertIsString($activationUrl);
        $this->get($activationUrl)->assertOk()->assertViewIs('portal.invitations.accept');
    }

    public function test_password_reset_delivery_keeps_the_broker_token_encrypted_at_rest_and_resets_password(): void
    {
        $user = PortalUser::factory()
            ->active()
            ->withPassword('old-password')
            ->create([
                'email' => 'reset@example.test',
            ]);

        $this->post(route('portal.password.request'), [
            'email' => $user->email,
        ])->assertRedirect();

        $message = ScheduledMessage::query()
            ->where('scope', 'portal')
            ->where('message_type', 'portal_password_reset')
            ->sole();

        $resetUrl = data_get($message->payload, 'cta.url');
        $this->assertIsString($resetUrl);

        $path = parse_url($resetUrl, PHP_URL_PATH);
        $this->assertIsString($path);
        $wrappedToken = basename($path);
        $rawToken = app(PortalSecretLinkCodec::class)->decode(
            $wrappedToken,
            PortalSecretLinkCodec::PURPOSE_PASSWORD_RESET,
        );

        $this->assertTrue(Password::broker(
            PortalModuleServiceProvider::PASSWORD_BROKER,
        )->tokenExists($user, $rawToken));

        $payload = json_encode($message->payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($rawToken, $payload);

        $this->post(route('portal.password.update', [
            'token' => $wrappedToken,
        ]), [
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect(route('portal.login'));

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertFalse(Password::broker(
            PortalModuleServiceProvider::PASSWORD_BROKER,
        )->tokenExists($user->fresh(), $rawToken));
    }

    public function test_email_verification_delivery_uses_signed_portal_url_and_marks_the_account_verified(): void
    {
        $user = PortalUser::factory()
            ->active()
            ->withPassword('portal-password')
            ->create([
                'email' => 'verify@example.test',
                'email_verified_at' => null,
            ]);

        $this->actingAs($user, 'portal');

        $this->post(route('portal.verification.send'))->assertRedirect();

        $message = ScheduledMessage::query()
            ->where('scope', 'portal')
            ->where('message_type', 'portal_email_verification')
            ->sole();

        $verificationUrl = data_get($message->payload, 'cta.url');
        $this->assertIsString($verificationUrl);

        $this->get($verificationUrl)->assertRedirect(route('portal.home'));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_portal_message_gate_rechecks_account_state_before_send(): void
    {
        $user = PortalUser::factory()
            ->active()
            ->withPassword('portal-password')
            ->create([
                'email' => 'gate@example.test',
                'email_verified_at' => null,
            ]);

        $transport = app(PortalAccountNotificationTransport::class);
        $this->assertTrue($transport->available());
        $this->assertTrue($transport->deliverEmailVerification(
            $user,
            route('portal.home'),
        ));

        $message = ScheduledMessage::query()->sole();
        $user->markEmailAsVerified();

        $reason = app(\App\Modules\Messaging\Services\ScheduledMessageGate::class)
            ->denialReason($message->fresh());

        $this->assertNotNull($reason);
    }

    private function enablePortalMessagingRuntime(): void
    {
        $enabled = config('modules.enabled', []);
        $enabled = is_array($enabled) ? $enabled : [];
        config()->set('modules.enabled', array_values(array_unique([
            ...$enabled,
            'portal',
            'messaging',
        ])));

        foreach ([
            ModuleManager::class,
            PortalAccountNotificationTransport::class,
            MessageRecipientGateRegistry::class,
            MessageRecipientPayloadProviderRegistry::class,
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $portal = new PortalModuleServiceProvider($this->app);
        $portal->register();
        $portal->boot();

        $integrations = new IntegrationsModuleServiceProvider($this->app);
        $integrations->register();
        $integrations->boot();

        foreach ([
            PortalAccountNotificationTransport::class,
            MessageRecipientGateRegistry::class,
            MessageRecipientPayloadProviderRegistry::class,
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }
}