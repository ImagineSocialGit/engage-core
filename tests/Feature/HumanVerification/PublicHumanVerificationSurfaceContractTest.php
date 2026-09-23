<?php

namespace Tests\Feature\HumanVerification;

use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\View\Components\PublicSurface\HumanVerification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PublicHumanVerificationSurfaceContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerSchedulingPublicSurface();
    }

    public function test_required_public_mutations_use_the_shared_surface_middleware(): void
    {
        $required = [
            'webinars' => [
                'webinar.join.continue',
                'webinar.registration.cancellation.store',
                'webinar.waitlist.store',
                'webinar.registration.store',
                'webinar.registration.questions.store',
            ],
            'scheduling' => [
                'scheduling.public.services.prepare',
                'scheduling.public.services.offers.store',
                'scheduling.public.offers.verification.issue',
                'scheduling.public.offers.verification.verify',
                'scheduling.public.offers.verification.resend',
                'scheduling.public.offers.hold',
                'scheduling.public.holds.complete',
            ],
            'forms' => [
                'forms.public.store',
            ],
            'messaging_permissions' => [
                'messaging.permission-invitations.store',
            ],
        ];

        foreach ($required as $surface => $routeNames) {
            foreach ($routeNames as $routeName) {
                $route = Route::getRoutes()->getByName($routeName);

                $this->assertNotNull(
                    $route,
                    "Expected public route [{$routeName}] to exist.",
                );
                $this->assertContains(
                    "public-human:{$surface}",
                    $route->gatherMiddleware(),
                    "Public mutation [{$routeName}] must use the shared [{$surface}] human-verification surface.",
                );
            }
        }
    }

    public function test_shared_component_builds_config_from_the_surface_contract(): void
    {
        config()->set('human_verification.enabled', true);
        config()->set('human_verification.provider', 'turnstile');
        config()->set('human_verification.surfaces.forms', [
            'enabled' => true,
            'action' => 'forms',
        ]);
        config()->set(
            'human_verification.providers.turnstile.site_key',
            'shared-component-site-key',
        );
        config()->set(
            'human_verification.providers.turnstile.secret_key',
            'shared-component-secret-key',
        );

        $component = $this->app->makeWith(HumanVerification::class, [
            'surface' => 'forms',
            'excludedPathPrefixes' => ['/skip/', 'invalid', '/skip/'],
        ]);

        $config = $component->humanVerificationConfig;

        $this->assertNotNull($config);
        $this->assertTrue($component->shouldRender());
        $this->assertTrue($config['available']);
        $this->assertSame(
            'shared-component-site-key',
            $config['widget']['site_key'],
        );
        $this->assertSame('forms', $config['widget']['action']);
        $this->assertSame(['/skip/'], $config['excludedPathPrefixes']);
    }

    public function test_shared_component_does_not_render_for_a_disabled_surface(): void
    {
        config()->set('human_verification.enabled', false);

        $component = $this->app->makeWith(HumanVerification::class, [
            'surface' => 'forms',
        ]);

        $this->assertNull($component->humanVerificationConfig);
        $this->assertFalse($component->shouldRender());
    }

    private function registerSchedulingPublicSurface(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'scheduling',
        ])));
        config()->set('scheduling.public.enabled', true);
        config()->set('scheduling.public.url', 'https://booking.example.test');
        config()->set('scheduling.public.host', 'booking.example.test');
        config()->set('scheduling.public.scheme', 'https');

        $this->app->register(
            SchedulingModuleServiceProvider::class,
            force: true,
        );

        Route::getRoutes()->refreshNameLookups();
    }
}