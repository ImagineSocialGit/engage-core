<?php

namespace Tests\Feature\Webinars;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebinarDevControllerEnvironmentGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_webinar_dev_and_smoke_route_is_inaccessible_in_production(): void
    {
        $this->app->detectEnvironment(
            fn (): string => 'production'
        );

        config()->set('app.env', 'production');
        config()->set('modules.enabled', [
            'webinars',
            'messaging',
        ]);

        $this->withoutMiddleware([
            ForceStagingAccess::class,
            PreventRequestForgery::class,
        ]);

        $user = User::factory()->create();

        $webinar = Webinar::factory()->create([
            'playback_url' => 'https://example.test/original-replay',
            'playback_passcode' => 'original',
            'meta' => [
                'guard_test' => 'unchanged',
            ],
        ]);

        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->create([
                'status' => 'registered',
                'attended_at' => null,
                'meta' => [
                    'guard_test' => 'unchanged',
                ],
            ]);

        $scheduledMessageCount = DB::table('scheduled_messages')->count();

        $requests = [
            [
                'get',
                'crm.webinar-registrations.dev.message-options.index',
                ['registration' => $registration],
            ],
            [
                'post',
                'crm.webinar-registrations.dev.messages.store',
                ['registration' => $registration],
            ],
            [
                'post',
                'crm.webinar-registrations.dev.messages.all.store',
                ['registration' => $registration],
            ],
            [
                'post',
                'crm.webinar-registrations.dev.join.store',
                ['registration' => $registration],
            ],
            [
                'post',
                'crm.webinar-registrations.dev.attended.store',
                ['registration' => $registration],
            ],
            [
                'post',
                'crm.webinar-registrations.dev.missed.store',
                ['registration' => $registration],
            ],
            [
                'post',
                'crm.webinar-registrations.dev.reset.store',
                ['registration' => $registration],
            ],
            [
                'post',
                'crm.webinars.dev.replay-url.store',
                ['webinar' => $webinar],
            ],
            [
                'delete',
                'crm.webinars.dev.replay-url.destroy',
                ['webinar' => $webinar],
            ],
            [
                'post',
                'crm.webinars.dev.follow-ups.store',
                ['webinar' => $webinar],
            ],
            [
                'post',
                'crm.webinar-registrations.smoke.attended.store',
                ['registration' => $registration],
            ],
            [
                'post',
                'crm.webinar-registrations.smoke.missed.store',
                ['registration' => $registration],
            ],
            [
                'post',
                'crm.webinar-registrations.smoke.reset.store',
                ['registration' => $registration],
            ],
            [
                'post',
                'crm.webinars.smoke.replay-url.store',
                ['webinar' => $webinar],
            ],
            [
                'delete',
                'crm.webinars.smoke.replay-url.destroy',
                ['webinar' => $webinar],
            ],
            [
                'post',
                'crm.webinars.smoke.follow-ups.store',
                ['webinar' => $webinar],
            ],
        ];

        $this->actingAs($user);

        foreach ($requests as [$method, $routeName, $parameters]) {
            $this->json(
                strtoupper($method),
                route($routeName, $parameters),
            )->assertNotFound();
        }

        $webinar->refresh();
        $registration->refresh();

        $this->assertSame(
            'https://example.test/original-replay',
            $webinar->playback_url
        );
        $this->assertSame('original', $webinar->playback_passcode);
        $this->assertSame(
            ['guard_test' => 'unchanged'],
            $webinar->meta
        );

        $this->assertSame('registered', $registration->status);
        $this->assertNull($registration->attended_at);
        $this->assertSame(
            ['guard_test' => 'unchanged'],
            $registration->meta
        );

        $this->assertSame(
            $scheduledMessageCount,
            DB::table('scheduled_messages')->count()
        );
    }
}