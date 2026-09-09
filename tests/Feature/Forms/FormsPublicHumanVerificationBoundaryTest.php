<?php

namespace Tests\Feature\Forms;

use App\Modules\Forms\Http\Middleware\AuthenticateExternalFormIntake;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

final class FormsPublicHumanVerificationBoundaryTest extends TestCase
{
    public function test_external_forms_transport_remains_machine_authenticated_instead_of_browser_challenged(): void
    {
        foreach ([
            'webhooks.forms.show',
            'webhooks.forms.submissions.store',
        ] as $routeName) {
            $route = RouteFacade::getRoutes()->getByName($routeName);

            $this->assertInstanceOf(Route::class, $route);

            $middleware = $route->gatherMiddleware();

            $this->assertContains('module:forms', $middleware);
            $this->assertContains(AuthenticateExternalFormIntake::class, $middleware);
            $this->assertFalse(collect($middleware)->contains(
                static fn (mixed $entry): bool => is_string($entry)
                    && str_starts_with($entry, 'public-human'),
            ));
        }
    }
}