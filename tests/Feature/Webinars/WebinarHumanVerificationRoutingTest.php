<?php

namespace Tests\Feature\Webinars;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class WebinarHumanVerificationRoutingTest extends TestCase
{
    public function test_public_webinar_mutations_require_the_shared_human_verification_surface(): void
    {
        foreach ([
            'webinar.join.continue',
            'webinar.registration.cancellation.store',
            'webinar.waitlist.store',
            'webinar.registration.store',
            'webinar.registration.questions.store',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Expected route [{$routeName}] to be registered.");
            $this->assertContains(
                'public-human:webinars',
                $route->gatherMiddleware(),
                "Expected route [{$routeName}] to require public human verification.",
            );
        }
    }

    public function test_machine_or_mailbox_provider_compatible_webinar_host_routes_are_not_challenged(): void
    {
        foreach ([
            'messaging.email.unsubscribe.store.legacy',
            'messaging.email.transactional-opt-out.store.legacy',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Expected route [{$routeName}] to be registered.");
            $this->assertNotContains('public-human:webinars', $route->gatherMiddleware());
        }
    }
}