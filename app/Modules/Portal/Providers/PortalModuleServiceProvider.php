<?php

namespace App\Modules\Portal\Providers;

use App\Modules\Portal\Auth\PortalUserProvider;
use App\Modules\Portal\Models\PortalUser;
use App\Modules\Portal\Services\PortalAuthContext;
use App\Modules\Portal\Services\PortalDashboardPanelRegistry;
use App\Modules\Portal\Services\PortalNavigationRegistry;
use App\Modules\Portal\Services\PortalRouteRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class PortalModuleServiceProvider extends ServiceProvider
{
    public const GUARD = 'portal';
    public const USER_PROVIDER = 'portal_users';
    public const PASSWORD_BROKER = 'portal_users';

    private const PROVIDER_DRIVER = 'portal_active';

    public function register(): void
    {
        $config = $this->app->make('config');

        $config->set('auth.guards.'.self::GUARD, [
            'driver' => 'session',
            'provider' => self::USER_PROVIDER,
        ]);

        $config->set('auth.providers.'.self::USER_PROVIDER, [
            'driver' => self::PROVIDER_DRIVER,
            'model' => PortalUser::class,
        ]);

        $config->set('auth.passwords.'.self::PASSWORD_BROKER, [
            'provider' => self::USER_PROVIDER,
            'table' => 'portal_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ]);

        $this->app->singleton(PortalAuthContext::class);
        $this->app->singleton(
            PortalDashboardPanelRegistry::class,
            fn ($app): PortalDashboardPanelRegistry => new PortalDashboardPanelRegistry(
                $app->tagged(PortalDashboardPanelRegistry::TAG),
            ),
        );
        $this->app->singleton(
            PortalNavigationRegistry::class,
            fn ($app): PortalNavigationRegistry => new PortalNavigationRegistry(
                $app->tagged(PortalNavigationRegistry::TAG),
            ),
        );
        $this->app->singleton(
            PortalRouteRegistry::class,
            fn ($app): PortalRouteRegistry => new PortalRouteRegistry(
                $app->tagged(PortalRouteRegistry::TAG),
            ),
        );
    }

    public function boot(): void
    {
        Auth::provider(
            self::PROVIDER_DRIVER,
            fn ($app, array $config): PortalUserProvider => new PortalUserProvider(
                $app->make('hash'),
            ),
        );
    }
}