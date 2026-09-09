<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PublicSchedulingHumanVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_public_booking_post_reuses_the_shared_scheduling_verification_surface(): void
    {
        $this->registerPublicSurface('https://schedule.test');

        foreach ([
            'scheduling.public.services.prepare',
            'scheduling.public.services.offers.store',
            'scheduling.public.offers.verification.issue',
            'scheduling.public.offers.verification.verify',
            'scheduling.public.offers.verification.resend',
            'scheduling.public.offers.hold',
            'scheduling.public.holds.complete',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Expected route [{$routeName}] to be registered.");
            $this->assertContains(
                'public-human:scheduling',
                $route->gatherMiddleware(),
                "Expected route [{$routeName}] to require the shared scheduling verification grant.",
            );
        }
    }

    public function test_public_booking_page_mounts_the_verification_ui_only_when_enabled(): void
    {
        $this->registerPublicSurface('https://schedule.test');
        config()->set('human_verification.enabled', true);
        config()->set('human_verification.surfaces.scheduling', [
            'enabled' => true,
            'action' => 'scheduling',
        ]);
        config()->set('human_verification.providers.turnstile.site_key', 'test-site-key');
        config()->set('human_verification.providers.turnstile.secret_key', 'test-secret-key');

        $this->get('https://schedule.test/')
            ->assertOk()
            ->assertSee('data-public-human-verification', false)
            ->assertSee('test-site-key');

        config()->set('human_verification.enabled', false);

        $this->get('https://schedule.test/')
            ->assertOk()
            ->assertDontSee('data-public-human-verification', false);
    }

    private function registerPublicSurface(string $url): void
    {
        $parts = parse_url($url);
        $scheme = is_string($parts['scheme'] ?? null)
            ? strtolower($parts['scheme'])
            : null;
        $host = is_string($parts['host'] ?? null)
            ? strtolower($parts['host'])
            : null;

        $this->assertNotNull($scheme);
        $this->assertNotNull($host);

        config()->set('modules.enabled', [
            ...config('modules.enabled', []),
            'scheduling',
        ]);
        config()->set('scheduling.public', [
            'enabled' => true,
            'url' => rtrim($url, '/'),
            'host' => $host,
            'scheme' => $scheme,
            'availability_max_days' => 31,
        ]);

        app()->register(
            SchedulingModuleServiceProvider::class,
            force: true,
        );

        Route::getRoutes()->refreshNameLookups();
    }
}