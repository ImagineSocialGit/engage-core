<?php

namespace Tests\Feature\Portal;

use App\Modules\Core\Models\Contact;
use App\Modules\Portal\Actions\CreatePortalInvitationAction;
use App\Modules\Portal\Actions\MarkPortalInvitationSentAction;
use App\Modules\Portal\Contracts\PortalDashboardPanelProvider;
use App\Modules\Portal\Contracts\PortalNavigationProvider;
use App\Modules\Portal\Data\PortalDashboardPanel;
use App\Modules\Portal\Data\PortalNavigationItem;
use App\Modules\Portal\Models\PortalUser;
use App\Modules\Portal\Providers\PortalModuleServiceProvider;
use App\Modules\Portal\Services\PortalDashboardPanelRegistry;
use App\Modules\Portal\Services\PortalNavigationRegistry;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PortalCustomerSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePortalRuntime();
    }

    public function test_portal_routes_use_the_configured_portal_host_and_are_separate_from_crm_login(): void
    {
        $portalHost = parse_url((string) config('app.portal_url'), PHP_URL_HOST);
        $crmHost = parse_url((string) config('app.crm_url'), PHP_URL_HOST);

        $this->assertSame($portalHost, parse_url(route('portal.login'), PHP_URL_HOST));
        $this->assertNotSame('login', Route::getRoutes()->getByName('portal.login')?->getName());

        if (is_string($crmHost) && $crmHost !== '') {
            $this->assertNotSame($crmHost, $portalHost);
        }
    }

    public function test_guest_login_and_invitation_activation_surface_are_available_when_portal_is_enabled(): void
    {
        $this->get(route('portal.login'))->assertOk()->assertViewIs('portal.auth.login');

        $contact = Contact::factory()->create();
        $issued = app(CreatePortalInvitationAction::class)->handle(
            contact: $contact,
            email: 'customer@example.test',
            channel: 'email',
        );
        $invitation = app(MarkPortalInvitationSentAction::class)->handle($issued->invitation);

        $this->get(route('portal.invitations.accept', [
            'invitation' => $invitation,
            'token' => $issued->token,
        ]))->assertOk()->assertViewIs('portal.invitations.accept');
    }

    public function test_portal_login_uses_portal_guard_and_authenticated_shell_does_not_use_internal_web_identity(): void
    {
        $portalUser = PortalUser::factory()->active()->withPassword('portal-password')->create([
            'email' => 'portal@example.test',
        ]);

        $this->post(route('portal.login.store'), [
            'email' => 'portal@example.test',
            'password' => 'portal-password',
        ])->assertRedirect(route('portal.home'));

        $this->assertTrue(Auth::guard('portal')->check());
        $this->assertFalse(Auth::guard('web')->check());

        $this->get(route('portal.home'))->assertOk()->assertViewIs('portal.home');
        $this->get(route('portal.account.show'))->assertOk()->assertViewIs('portal.account.show');

        $this->patch(route('portal.account.update'), [
            'name' => 'Updated Customer',
            'phone' => '6155550101',
        ])->assertRedirect(route('portal.account.show'));

        $this->assertSame('Updated Customer', $portalUser->fresh()->name);

        $this->post(route('portal.logout'))->assertRedirect(route('portal.login'));
        $this->assertFalse(Auth::guard('portal')->check());
    }

    public function test_unverified_accounts_do_not_receive_verified_only_navigation_or_dashboard_contributions(): void
    {
        $this->app->bind(TestPortalNavigationProvider::class, fn () => new TestPortalNavigationProvider());
        $this->app->tag(TestPortalNavigationProvider::class, PortalNavigationRegistry::TAG);
        $this->app->bind(TestPortalDashboardProvider::class, fn () => new TestPortalDashboardProvider());
        $this->app->tag(TestPortalDashboardProvider::class, PortalDashboardPanelRegistry::TAG);
        $this->app->forgetInstance(PortalNavigationRegistry::class);
        $this->app->forgetInstance(PortalDashboardPanelRegistry::class);

        $unverified = PortalUser::factory()->active()->withPassword()->create(['email_verified_at' => null]);
        $verified = PortalUser::factory()->active()->withPassword()->create();

        $unverifiedNavigation = app(PortalNavigationRegistry::class)->forUser($unverified);
        $verifiedNavigation = app(PortalNavigationRegistry::class)->forUser($verified);
        $unverifiedPanels = app(PortalDashboardPanelRegistry::class)->forUser($unverified);
        $verifiedPanels = app(PortalDashboardPanelRegistry::class)->forUser($verified);

        $this->assertFalse(collect($unverifiedNavigation)->contains(fn (PortalNavigationItem $item): bool => $item->key === 'fixture'));
        $this->assertTrue(collect($verifiedNavigation)->contains(fn (PortalNavigationItem $item): bool => $item->key === 'fixture'));
        $this->assertSame([], $unverifiedPanels);
        $this->assertCount(1, $verifiedPanels);
    }

    private function enablePortalRuntime(): void
    {
        $enabled = config('modules.enabled', []);
        $enabled = is_array($enabled) ? $enabled : [];
        config()->set('modules.enabled', array_values(array_unique([...$enabled, 'portal'])));
        $this->app->forgetInstance(ModuleManager::class);

        $provider = new PortalModuleServiceProvider($this->app);
        $provider->register();
        $provider->boot();
        Auth::forgetGuards();
    }
}

final class TestPortalNavigationProvider implements PortalNavigationProvider
{
    public function definitions(PortalUser $user): iterable
    {
        yield new PortalNavigationItem('fixture', 'Fixture', 'portal.home');
    }
}

final class TestPortalDashboardProvider implements PortalDashboardPanelProvider
{
    public function definitions(PortalUser $user): iterable
    {
        yield new PortalDashboardPanel('fixture', 'portal.home');
    }
}