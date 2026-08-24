<?php

namespace Tests\Feature\Forms;

use App\Modules\Forms\Providers\FormsModuleServiceProvider;
use App\Modules\Forms\Services\ExternalFormIntakeClientResolver;
use App\Modules\Forms\Services\ExternalFormIntakeSecretPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Tests\TestCase;

class IssueExternalFormIntakeSecretCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(FormsModuleServiceProvider::class, force: true);
    }

    public function test_it_issues_matching_core_and_artist_sites_credentials_when_current_secret_is_blank(): void
    {
        $configured = [
            'engage_sites' => $this->clientSettings(secret: ''),
        ];
        config()->set('forms.external_intake.clients', $configured);

        $exitCode = Artisan::call('forms:external-intake:issue-secret');
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString(
            'FORMS_EXTERNAL_INTAKE_CLIENT_ID=engage_sites',
            $output,
        );
        $this->assertStringContainsString(
            'ENGAGE_CORE_CLIENT_KEY=engage_sites',
            $output,
        );
        $this->assertSame(1, preg_match(
            '/FORMS_EXTERNAL_INTAKE_CLIENT_SECRET=([A-Za-z0-9_-]{64})/',
            $output,
            $matches,
        ));
        $this->assertStringContainsString(
            'ENGAGE_CORE_SIGNING_SECRET='.$matches[1],
            $output,
        );
        $this->assertSame($configured, config('forms.external_intake.clients'));
    }

    public function test_it_accepts_an_explicit_client_when_multiple_clients_are_configured(): void
    {
        config()->set('forms.external_intake.clients', [
            'engage_sites' => $this->clientSettings(secret: ''),
            'engage_seo' => $this->clientSettings(secret: null),
        ]);

        $exitCode = Artisan::call('forms:external-intake:issue-secret', [
            'client' => 'engage_seo',
        ]);
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString(
            'FORMS_EXTERNAL_INTAKE_CLIENT_ID=engage_seo',
            $output,
        );
        $this->assertStringContainsString(
            'ENGAGE_CORE_CLIENT_KEY=engage_seo',
            $output,
        );
    }

    public function test_it_requires_a_client_argument_when_multiple_clients_are_configured(): void
    {
        config()->set('forms.external_intake.clients', [
            'engage_sites' => $this->clientSettings(secret: ''),
            'engage_seo' => $this->clientSettings(secret: ''),
        ]);

        $exitCode = Artisan::call('forms:external-intake:issue-secret');

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(
            'Specify a client ID. Configured external Forms intake clients: engage_sites, engage_seo.',
            Artisan::output(),
        );
    }

    public function test_it_rejects_an_unknown_explicit_client(): void
    {
        config()->set('forms.external_intake.clients', [
            'engage_sites' => $this->clientSettings(secret: ''),
        ]);

        $exitCode = Artisan::call('forms:external-intake:issue-secret', [
            'client' => 'missing_client',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString(
            'External Forms intake client [missing_client] is not configured.',
            Artisan::output(),
        );
    }

    public function test_secret_policy_and_runtime_resolver_share_the_same_bounds(): void
    {
        $policy = app(ExternalFormIntakeSecretPolicy::class);
        $minimum = str_repeat('a', ExternalFormIntakeSecretPolicy::MIN_BYTES);
        $maximum = str_repeat('b', ExternalFormIntakeSecretPolicy::MAX_BYTES);

        $this->assertSame(
            $minimum,
            $policy->validatedSecret($minimum, 'engage_sites'),
        );
        $this->assertSame(
            $maximum,
            $policy->validatedSecret($maximum, 'engage_sites'),
        );

        config()->set('forms.external_intake.clients', [
            'engage_sites' => $this->clientSettings(secret: $minimum),
        ]);

        $this->assertSame(
            'engage_sites',
            app(ExternalFormIntakeClientResolver::class)
                ->find('engage_sites')
                ?->id,
        );

        $this->expectException(InvalidArgumentException::class);
        $policy->validatedSecret(
            str_repeat('c', ExternalFormIntakeSecretPolicy::MAX_BYTES + 1),
            'engage_sites',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function clientSettings(mixed $secret): array
    {
        return [
            'secret' => $secret,
            'source' => 'engage_sites',
            'provider' => 'engage_sites',
            'allowed_forms' => ['artist_updates'],
        ];
    }
}