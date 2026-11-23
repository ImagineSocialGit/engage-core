<?php

namespace Tests\Feature\Forms;

use App\Modules\Forms\Deployment\FormsCredentialRuntimeContributor;
use App\Modules\Forms\Services\ExternalFormIntakeClientResolver;
use App\Modules\Forms\Services\ExternalFormIntakeSigningCredentialResolver;
use App\Support\Deployment\Credentials\CoreCredentialCallerKey;
use Tests\TestCase;

final class FormsCredentialRuntimeContributorTest extends TestCase
{
    private ?string $credentialFile = null;

    protected function tearDown(): void
    {
        if ($this->credentialFile !== null && is_file($this->credentialFile)) {
            unlink($this->credentialFile);
        }
        parent::tearDown();
    }

    public function test_runtime_binding_proves_the_same_secret_as_http_authentication(): void
    {
        $secret = str_repeat('Q', 64);
        $key = CoreCredentialCallerKey::forCaller('forms.external_intake', 'artist', 'artist_site');
        $this->credentialFile = tempnam(sys_get_temp_dir(), 'forms-proof-');
        self::assertNotFalse($this->credentialFile);
        file_put_contents($this->credentialFile, $key.'='.$secret."\n");
        config()->set('client.env_path', $this->credentialFile);
        $slot = [
            'source_platform' => 'artist',
            'source_origin' => 'https://artist.example',
            'secret_env_key' => $key,
            'source' => 'artist_site',
            'provider' => 'artist',
            'allowed_forms' => ['newsletter'],
            'domains' => ['artist.example'],
        ];
        config()->set('forms.external_intake.enabled', true);
        config()->set('forms.external_intake.clients', ['artist_site' => $slot]);

        $contributor = app(FormsCredentialRuntimeContributor::class);
        $bindings = iterator_to_array($contributor->runtimeBindings(), false);
        self::assertCount(1, $bindings);
        self::assertSame($key, $bindings[0]->secretEnvironmentKey);
        self::assertSame($secret, $bindings[0]->resolveSecret());
        $client = app(ExternalFormIntakeClientResolver::class)->find('artist_site');
        self::assertNotNull($client);
        $signature = hash_hmac('sha256', 'message', $secret);
        self::assertTrue($client->verifies('message', $signature));
        self::assertFalse($client->allowsForm('unapproved_form'));

        file_put_contents($this->credentialFile, $key.'='.str_repeat('X', 64)."\n");
        self::assertSame(str_repeat('X', 64), $bindings[0]->resolveSecret());
        self::assertFalse(app(ExternalFormIntakeClientResolver::class)->find('artist_site')->verifies('message', $signature));
    }

    public function test_inline_secret_cannot_be_used_as_a_runtime_binding(): void
    {
        $key = CoreCredentialCallerKey::forCaller('forms.external_intake', 'seo', 'site_one');
        $settings = [
            'source_platform' => 'seo',
            'secret_env_key' => $key,
            'secret' => str_repeat('S', 64),
        ];
        self::assertFalse(app(ExternalFormIntakeSigningCredentialResolver::class)
            ->hasValidBinding('site_one', $settings));
    }
}
