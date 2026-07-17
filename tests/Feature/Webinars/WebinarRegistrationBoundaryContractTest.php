<?php

namespace Tests\Feature\Webinars;

use Tests\TestCase;

class WebinarRegistrationBoundaryContractTest extends TestCase
{
    public function test_waitlist_and_registration_use_separate_named_limiters(): void
    {
        $routes = file_get_contents(base_path('routes/webinar.php'));

        $this->assertIsString($routes);
        $this->assertStringContainsString("throttle:webinar-waitlist", $routes);
        $this->assertStringContainsString("throttle:webinar-registration", $routes);
    }

    public function test_rate_limit_identity_material_is_hashed_and_scoped(): void
    {
        $provider = file_get_contents(base_path(
            'app/Modules/Webinars/Providers/WebinarsModuleServiceProvider.php',
        ));

        $this->assertIsString($provider);
        $this->assertStringContainsString('hash_hmac(', $provider);
        $this->assertStringContainsString("'webinar:%s:%s:email:%s'", $provider);
        $this->assertStringContainsString("'webinar:%s:%s:phone:%s'", $provider);
        $this->assertStringContainsString("'webinar-public:ip:'", $provider);
    }

    public function test_final_rendered_webinar_html_is_not_cached(): void
    {
        $controller = file_get_contents(base_path(
            'app/Modules/Webinars/Controllers/Public/WebinarRegistrationController.php',
        ));

        $this->assertIsString($controller);
        $this->assertStringNotContainsString('Cache::remember(', $controller);
        $this->assertStringNotContainsString('webinarLandingPage(', $controller);
    }
}
