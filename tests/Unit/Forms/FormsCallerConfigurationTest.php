<?php

namespace Tests\Unit\Forms;

use App\Modules\Forms\Deployment\FormsCallerConfiguration;
use App\Support\Deployment\Credentials\CoreCredentialCallerKey;
use App\Support\Deployment\Credentials\CoreInternalCallerRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FormsCallerConfigurationTest extends TestCase
{
    private function registry(): CoreInternalCallerRegistry
    {
        return CoreInternalCallerRegistry::decode(null, 'example_core', 'staging')
            ->withCaller('forms.external_intake', 'artist', 'artist_site', 'https://artist.example');
    }

    /** @return array<string, mixed> */
    private function authorization(): array
    {
        return ['artist_site' => [
            'source_platform' => 'artist',
            'source_origin' => 'https://artist.example',
            'source' => 'artist_site',
            'provider' => 'artist',
            'allowed_forms' => ['newsletter'],
            'domains' => ['artist.example'],
        ]];
    }

    public function test_registered_and_authorized_caller_receives_exact_binding_without_secret(): void
    {
        $settings = FormsCallerConfiguration::settings($this->registry(), $this->authorization());
        self::assertSame(['artist_site'], array_keys($settings));
        self::assertSame(
            CoreCredentialCallerKey::forCaller('forms.external_intake', 'artist', 'artist_site'),
            $settings['artist_site']['secret_env_key'],
        );
        self::assertSame(['newsletter'], $settings['artist_site']['allowed_forms']);
        self::assertArrayNotHasKey('secret', $settings['artist_site']);
    }

    public function test_policy_alone_never_enrolls_an_unregistered_caller(): void
    {
        self::assertSame([], FormsCallerConfiguration::settings(null, $this->authorization()));
    }

    public function test_origin_and_platform_cannot_be_rebound_by_policy(): void
    {
        foreach (['source_origin' => 'https://other.example', 'source_platform' => 'seo'] as $field => $wrong) {
            $policy = $this->authorization();
            $policy['artist_site'][$field] = $wrong;
            try {
                FormsCallerConfiguration::settings($this->registry(), $policy);
                self::fail('Conflicting registered caller must be rejected.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_missing_allowed_forms_or_domains_never_grants_blanket_access(): void
    {
        foreach (['allowed_forms', 'domains'] as $field) {
            $policy = $this->authorization();
            $policy['artist_site'][$field] = [];
            try {
                FormsCallerConfiguration::settings($this->registry(), $policy);
                self::fail('Empty authorization must be rejected.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_policy_cannot_supply_inline_secret_or_environment_redirect(): void
    {
        foreach (['secret' => str_repeat('X', 64), 'secret_env_key' => 'OTHER_SECRET'] as $field => $value) {
            $policy = $this->authorization();
            $policy['artist_site'][$field] = $value;
            try {
                FormsCallerConfiguration::settings($this->registry(), $policy);
                self::fail('Policy may never supply signing material or secret name.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }
}
