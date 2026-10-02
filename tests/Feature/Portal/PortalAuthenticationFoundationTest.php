<?php

namespace Tests\Feature\Portal;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Portal\Actions\AcceptPortalInvitationAction;
use App\Modules\Portal\Actions\AuthenticatePortalUserAction;
use App\Modules\Portal\Actions\CreatePortalInvitationAction;
use App\Modules\Portal\Actions\CreatePortalUserAction;
use App\Modules\Portal\Actions\LinkPortalUserToContactAction;
use App\Modules\Portal\Actions\MarkPortalInvitationSentAction;
use App\Modules\Portal\Auth\PortalUserProvider;
use App\Modules\Portal\Models\PortalContactLink;
use App\Modules\Portal\Models\PortalInvitation;
use App\Modules\Portal\Models\PortalUser;
use App\Modules\Portal\Providers\PortalModuleServiceProvider;
use App\Modules\Portal\Services\PortalAuthContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

final class PortalAuthenticationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_runtime_auth_is_separate_from_internal_web_auth(): void
    {
        $webGuard = config('auth.guards.web');
        $defaults = config('auth.defaults');

        $this->bootPortalRuntime();

        $this->assertSame($webGuard, config('auth.guards.web'));
        $this->assertSame($defaults, config('auth.defaults'));
        $this->assertSame(
            PortalModuleServiceProvider::USER_PROVIDER,
            config('auth.guards.portal.provider'),
        );
        $this->assertSame(
            'portal_password_reset_tokens',
            config('auth.passwords.portal_users.table'),
        );
        $this->assertInstanceOf(
            PortalUserProvider::class,
            Auth::createUserProvider(PortalModuleServiceProvider::USER_PROVIDER),
        );
    }

    public function test_portal_guard_authenticates_only_active_portal_accounts(): void
    {
        $this->bootPortalRuntime();

        $email = 'shared@example.test';

        User::factory()->create([
            'email' => $email,
            'password' => Hash::make('internal-password'),
        ]);

        $portalUser = PortalUser::factory()
            ->active()
            ->withPassword('portal-password')
            ->create([
                'email' => $email,
            ]);

        $this->assertTrue(Auth::guard('portal')->validate([
            'email' => ' SHARED@EXAMPLE.TEST ',
            'password' => 'portal-password',
        ]));
        $this->assertFalse(Auth::guard('portal')->validate([
            'email' => $email,
            'password' => 'internal-password',
        ]));
        $this->assertTrue(Auth::guard('web')->validate([
            'email' => $email,
            'password' => 'internal-password',
        ]));
        $this->assertFalse(Auth::guard('web')->validate([
            'email' => $email,
            'password' => 'portal-password',
        ]));

        foreach ([
            PortalUser::factory()->withPassword('password')->create(),
            PortalUser::factory()->active()->suspended()->withPassword('password')->create(),
            PortalUser::factory()->active()->disabled()->withPassword('password')->create(),
        ] as $blockedUser) {
            $this->assertFalse(Auth::guard('portal')->validate([
                'email' => $blockedUser->email,
                'password' => 'password',
            ]));
        }

        $this->assertTrue($portalUser->isAuthenticatable());
    }

    public function test_account_creation_and_contact_linking_use_explicit_separate_identities(): void
    {
        $contact = Contact::factory()->create();

        $user = app(CreatePortalUserAction::class)->handle(
            name: '  Customer Example  ',
            email: ' Customer@Example.TEST ',
            password: 'customer-password',
            contact: $contact,
        );

        $this->assertSame('Customer Example', $user->name);
        $this->assertSame('customer@example.test', $user->email);
        $this->assertSame(PortalUser::STATUS_ACTIVE, $user->status);
        $this->assertTrue(Hash::check('customer-password', $user->password));
        $this->assertNull($user->email_verified_at);

        $link = $user->contactLinks()->sole();

        $this->assertTrue($link->contact->is($contact));
        $this->assertSame(PortalContactLink::RELATIONSHIP_SELF, $link->relationship);
        $this->assertTrue($link->is_primary);

        app(LinkPortalUserToContactAction::class)->handle(
            portalUser: $user,
            contact: $contact,
            relationship: PortalContactLink::RELATIONSHIP_SELF,
        );

        $this->assertSame(1, $user->contactLinks()->count());

        $this->expectException(DomainException::class);

        app(CreatePortalUserAction::class)->handle(
            name: 'Duplicate',
            email: 'CUSTOMER@example.test',
            password: 'another-password',
        );
    }

    public function test_invitation_token_lifecycle_activates_account_and_links_contact(): void
    {
        $contact = Contact::factory()->create();

        $issued = app(CreatePortalInvitationAction::class)->handle(
            contact: $contact,
            email: ' Guardian@Example.TEST ',
            channel: 'email',
            relationship: PortalContactLink::RELATIONSHIP_GUARDIAN,
        );

        $this->assertSame(
            PortalInvitation::STATUS_PENDING,
            $issued->invitation->status,
        );
        $this->assertNotSame(
            $issued->token,
            $issued->invitation->token_hash,
        );
        $this->assertSame(
            hash('sha256', $issued->token),
            $issued->invitation->token_hash,
        );

        $sent = app(MarkPortalInvitationSentAction::class)->handle(
            $issued->invitation,
        );

        $this->assertSame(PortalInvitation::STATUS_SENT, $sent->status);

        try {
            app(AcceptPortalInvitationAction::class)->handle(
                invitation: $sent,
                token: 'wrong-token',
                name: 'Guardian Example',
                password: 'guardian-password',
            );

            $this->fail('Wrong invitation token should have been rejected.');
        } catch (DomainException) {
            $this->assertSame(
                PortalInvitation::STATUS_SENT,
                $sent->fresh()->status,
            );
        }

        $user = app(AcceptPortalInvitationAction::class)->handle(
            invitation: $sent,
            token: $issued->token,
            name: 'Guardian Example',
            password: 'guardian-password',
            acceptedIp: '127.0.0.1',
            acceptedUserAgent: 'Portal foundation test',
        );

        $this->assertSame('guardian@example.test', $user->email);
        $this->assertSame(PortalUser::STATUS_ACTIVE, $user->status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('guardian-password', $user->password));

        $link = $user->contactLinks()->sole();

        $this->assertTrue($link->contact->is($contact));
        $this->assertSame(
            PortalContactLink::RELATIONSHIP_GUARDIAN,
            $link->relationship,
        );

        $accepted = $sent->fresh();

        $this->assertSame(PortalInvitation::STATUS_ACCEPTED, $accepted->status);
        $this->assertSame($user->getKey(), $accepted->portal_user_id);
        $this->assertNotNull($accepted->accepted_at);

        $this->expectException(DomainException::class);

        app(AcceptPortalInvitationAction::class)->handle(
            invitation: $accepted,
            token: $issued->token,
            name: 'Guardian Example',
            password: 'guardian-password',
        );
    }

    public function test_portal_password_reset_tokens_are_isolated_from_internal_users(): void
    {
        $this->bootPortalRuntime();

        $user = PortalUser::factory()
            ->active()
            ->withPassword('portal-password')
            ->create();

        $token = Password::broker(
            PortalModuleServiceProvider::PASSWORD_BROKER,
        )->createToken($user);

        $this->assertIsString($token);
        $this->assertNotSame('', $token);
        $this->assertSame(
            1,
            DB::table('portal_password_reset_tokens')
                ->where('email', $user->email)
                ->count(),
        );

        if (DB::getSchemaBuilder()->hasTable('password_reset_tokens')) {
            $this->assertSame(
                0,
                DB::table('password_reset_tokens')
                    ->where('email', $user->email)
                    ->count(),
            );
        }
    }

    public function test_authenticate_action_records_login_and_exposes_portal_context(): void
    {
        $this->bootPortalRuntime();

        $contact = Contact::factory()->create();
        $user = PortalUser::factory()
            ->active()
            ->withPassword('portal-password')
            ->create([
                'email' => 'login@example.test',
                'last_login_at' => null,
            ]);

        app(LinkPortalUserToContactAction::class)->handle(
            portalUser: $user,
            contact: $contact,
        );

        $authenticated = app(AuthenticatePortalUserAction::class)->handle(
            email: ' LOGIN@EXAMPLE.TEST ',
            password: 'portal-password',
        );

        $this->assertTrue($authenticated?->is($user) ?? false);
        $this->assertNotNull($authenticated?->last_login_at);

        $context = app(PortalAuthContext::class);

        $this->assertTrue($context->requireUser()->is($user));
        $this->assertSame(
            [(int) $contact->getKey()],
            $context->activeContactIds(),
        );
    }

    private function bootPortalRuntime(): void
    {
        $provider = new PortalModuleServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        Auth::forgetGuards();
    }
}