<?php

namespace Tests\Feature\Forms;

use App\Modules\Forms\Data\HostedFormContextReference;
use App\Modules\Forms\Services\HostedFormContextService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HostedFormsPublicOriginTest extends TestCase
{
    private string|false $previousFormsAppUrl;

    protected function setUp(): void
    {
        $this->previousFormsAppUrl = getenv('FORMS_APP_URL');
        $this->setFormsAppUrl('https://signup.example.test');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->previousFormsAppUrl === false) {
            putenv('FORMS_APP_URL');
            unset($_ENV['FORMS_APP_URL'], $_SERVER['FORMS_APP_URL']);

            return;
        }

        $this->setFormsAppUrl($this->previousFormsAppUrl);
    }

    public function test_hosted_form_routes_and_signed_context_urls_use_configured_origin_host(): void
    {
        $route = Route::getRoutes()->getByName('forms.public.show');

        $this->assertNotNull($route);
        $this->assertSame('signup.example.test', config('forms.public.host'));
        $this->assertSame('signup.example.test', $route->getDomain());

        $signedUrl = app(HostedFormContextService::class)->signedUrl(
            'event_signup',
            new HostedFormContextReference(
                key: 'event',
                reference: 42,
            ),
        );

        $this->assertSame(
            'signup.example.test',
            parse_url($signedUrl, PHP_URL_HOST),
        );
        $this->assertSame('/event-signup', parse_url($signedUrl, PHP_URL_PATH));

        parse_str((string) parse_url($signedUrl, PHP_URL_QUERY), $query);

        $this->assertSame('event', $query['context'] ?? null);
        $this->assertSame('42', $query['context_ref'] ?? null);
        $this->assertArrayHasKey('signature', $query);
    }

    private function setFormsAppUrl(string $value): void
    {
        putenv('FORMS_APP_URL='.$value);
        $_ENV['FORMS_APP_URL'] = $value;
        $_SERVER['FORMS_APP_URL'] = $value;
    }
}