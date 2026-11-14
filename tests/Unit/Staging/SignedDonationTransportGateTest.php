<?php

namespace Tests\Unit\Staging;

use App\Http\Middleware\ForceStagingAccess;
use App\Support\Staging\StagingAccess;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

/** Backend route/method/host boundary; provider authentication stays package-owned. */
final class SignedDonationTransportGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(static fn (): string => 'staging');
        config()->set('app.env', 'staging');
        config()->set('app.root_domain', 'staging.example.org');
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        config()->set('staging.user', 'operator');
        config()->set('staging.password_hash', password_hash('test-only-password', PASSWORD_BCRYPT, ['cost' => 4]));
        config()->set('session.secure', true);
        config()->set('session.http_only', true);
        config()->set('session.same_site', 'lax');
    }

    public function test_reviewed_transport_routes_reach_their_package_authentication(): void
    {
        foreach ([
            ['POST', 'webhooks.donations.external.checkout'],
            ['GET', 'webhooks.donations.external.status'],
            ['HEAD', 'webhooks.donations.external.status'],
        ] as [$method, $name]) {
            $response = $this->handle($method, $name, 'webhooks.staging.example.org');
            self::assertSame(204, $response->getStatusCode());
        }
    }

    public function test_checkout_exemption_does_not_apply_on_the_operator_host(): void
    {
        $response = $this->handle('POST', 'webhooks.donations.external.checkout', 'app.staging.example.org');
        self::assertSame(403, $response->getStatusCode());
    }

    public function test_status_exemption_does_not_allow_state_changing_methods(): void
    {
        $response = $this->handle('POST', 'webhooks.donations.external.status', 'webhooks.staging.example.org');
        self::assertSame(403, $response->getStatusCode());
    }

    public function test_new_unreviewed_package_routes_remain_gated(): void
    {
        $response = $this->handle('POST', 'webhooks.donations.unreviewed', 'webhooks.staging.example.org');
        self::assertSame(403, $response->getStatusCode());
    }

    private function handle(string $method, string $name, string $host): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create('https://'.$host.'/transport', $method);
        $route = (new Route([$method], '/transport', static fn () => null))->name($name);
        $request->setRouteResolver(static fn () => $route);
        $request->setLaravelSession($this->app->make('session')->driver('array'));

        return (new ForceStagingAccess(new StagingAccess()))->handle(
            $request,
            static fn () => response('', 204),
        );
    }
}