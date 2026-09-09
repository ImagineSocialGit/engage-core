<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Support\HumanVerification\Contracts\HumanVerificationProvider;
use App\Support\HumanVerification\Data\HumanVerificationRequest;
use App\Support\HumanVerification\Data\HumanVerificationResult;
use App\Support\HumanVerification\Data\HumanVerificationWidget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ContactPermissionInvitationHumanVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionInvitationTestHumanVerificationProvider::$verifyCalls = 0;

        config([
            'human_verification.enabled' => true,
            'human_verification.provider' => PermissionInvitationTestHumanVerificationProvider::KEY,
            'human_verification.surfaces.messaging_permissions' => [
                'enabled' => true,
                'action' => 'messaging_permissions',
            ],
            'human_verification.providers.'.PermissionInvitationTestHumanVerificationProvider::KEY => [
                'driver' => PermissionInvitationTestHumanVerificationProvider::class,
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

    public function test_permission_invitation_page_mounts_shared_human_verification_when_enabled(): void
    {
        $invitation = $this->invitation();

        $this->get(route('messaging.permission-invitations.show', [
            'token' => $invitation->token,
        ]))
            ->assertOk()
            ->assertSee('data-public-human-verification', false)
            ->assertSee('messaging_permissions');
    }

    public function test_permission_invitation_post_fails_closed_without_successful_human_verification(): void
    {
        $invitation = $this->invitation();
        $showUrl = route('messaging.permission-invitations.show', [
            'token' => $invitation->token,
        ]);

        $this->from($showUrl)
            ->post(route('messaging.permission-invitations.store', [
                'token' => $invitation->token,
            ]), [
                'channels' => ['email'],
            ])
            ->assertRedirect($showUrl);

        $invitation->refresh();

        $this->assertSame(
            ContactPermissionInvitation::STATUS_SENT,
            $invitation->status,
        );
        $this->assertNull($invitation->accepted_at);
        $this->assertSame(0, MessageConsent::query()->count());
        $this->assertSame(
            1,
            PermissionInvitationTestHumanVerificationProvider::$verifyCalls,
        );
    }

    public function test_verified_permission_invitation_post_accepts_and_reuses_surface_grant(): void
    {
        $firstInvitation = $this->invitation();

        $this->post(route('messaging.permission-invitations.store', [
            'token' => $firstInvitation->token,
        ]), [
            'channels' => ['email'],
            'cf-turnstile-response' => PermissionInvitationTestHumanVerificationProvider::VALID_TOKEN,
        ])->assertRedirect(route('messaging.permission-invitations.show', [
            'token' => $firstInvitation->token,
        ]));

        $firstInvitation->refresh();

        $this->assertSame(
            ContactPermissionInvitation::STATUS_ACCEPTED,
            $firstInvitation->status,
        );
        $this->assertSame(
            1,
            PermissionInvitationTestHumanVerificationProvider::$verifyCalls,
        );

        $secondInvitation = $this->invitation();

        $this->post(route('messaging.permission-invitations.store', [
            'token' => $secondInvitation->token,
        ]), [
            'channels' => ['email'],
        ])->assertRedirect(route('messaging.permission-invitations.show', [
            'token' => $secondInvitation->token,
        ]));

        $secondInvitation->refresh();

        $this->assertSame(
            ContactPermissionInvitation::STATUS_ACCEPTED,
            $secondInvitation->status,
        );
        $this->assertSame(
            1,
            PermissionInvitationTestHumanVerificationProvider::$verifyCalls,
        );
        $this->assertSame(2, MessageConsent::query()->count());
    }

    public function test_only_permission_invitation_mutation_gets_messaging_human_verification_middleware(): void
    {
        $protectedRoute = Route::getRoutes()->getByName(
            'messaging.permission-invitations.store',
        );

        $this->assertNotNull($protectedRoute);
        $this->assertContains(
            'public-human:messaging_permissions',
            $protectedRoute->gatherMiddleware(),
        );

        foreach ([
            'messaging.permission-invitations.show',
            'messaging.email.unsubscribe.store',
            'messaging.email.transactional-opt-out.store',
            'messaging.cta.redirect.legacy',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Expected route [{$routeName}] to exist.");
            $this->assertNotContains(
                'public-human:messaging_permissions',
                $route->gatherMiddleware(),
                "Route [{$routeName}] must not receive Messaging permission human verification.",
            );
        }
    }

    private function invitation(): ContactPermissionInvitation
    {
        $contact = Contact::factory()->create([
            'email' => Str::lower(Str::random(12)).'@example.test',
            'source' => 'import',
            'phone' => null,
        ]);

        return ContactPermissionInvitation::query()->create([
            'contact_id' => $contact->id,
            'token' => Str::random(64),
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
            'status' => ContactPermissionInvitation::STATUS_SENT,
            'claimed_at' => now()->subMinutes(5),
            'sent_at' => now()->subMinutes(4),
            'meta' => [],
        ]);
    }
}

final class PermissionInvitationTestHumanVerificationProvider implements HumanVerificationProvider
{
    public const KEY = 'permission_invitation_test';
    public const VALID_TOKEN = 'valid-permission-invitation-token';

    public static int $verifyCalls = 0;

    public function key(): string
    {
        return self::KEY;
    }

    public function validateConfiguration(array $configuration): void
    {
        //
    }

    public function widget(
        string $action,
        array $configuration,
    ): HumanVerificationWidget {
        return new HumanVerificationWidget(
            provider: self::KEY,
            scriptUrl: 'https://example.test/security-check.js',
            siteKey: 'test-site-key',
            action: $action,
        );
    }

    public function verify(
        HumanVerificationRequest $request,
        array $configuration,
    ): HumanVerificationResult {
        self::$verifyCalls++;

        if ($request->token !== self::VALID_TOKEN) {
            return HumanVerificationResult::failed(
                provider: self::KEY,
                reason: HumanVerificationResult::REASON_INVALID_TOKEN,
                hostname: $request->expectedHostnames[0] ?? null,
                action: $request->expectedAction,
            );
        }

        return HumanVerificationResult::verified(
            provider: self::KEY,
            verifiedAt: CarbonImmutable::now('UTC'),
            challengeAt: CarbonImmutable::now('UTC')->subSecond(),
            hostname: $request->expectedHostnames[0],
            action: $request->expectedAction,
        );
    }
}