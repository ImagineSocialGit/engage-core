<?php

namespace Tests\Unit\Deployment;

use App\Support\Deployment\Credentials\CoreEndpointOriginInspector;
use PHPUnit\Framework\TestCase;

final class CoreEndpointOriginInspectorTest extends TestCase
{
    public function test_local_origin_is_derived_without_any_proposed_core_origin(): void
    {
        $result = $this->inspect();

        self::assertSame('configured_unverified', $result['status']);
        self::assertSame('http://webhooks.example.test', $result['configured_webhook_origin']);
        self::assertSame('effective_and_persisted_selected_client', $result['origin_evidence']);
        self::assertTrue($result['core_configuration_consistent']);
        self::assertFalse($result['webhook_routes_verified']);
        self::assertFalse($result['network_routing_verified']);
        self::assertFalse($result['runtime_attested']);
        self::assertFalse($result['issuance_allowed']);
        self::assertFalse($result['writes_allowed']);
    }

    public function test_deployed_https_core_origin_is_supported(): void
    {
        $env = $this->clientEnvironment();
        $env['ROOT_DOMAIN'] = 'example.org';
        $env['APP_URL'] = 'https://crm.example.org';
        $env['CRM_APP_URL'] = 'https://crm.example.org';

        $result = $this->inspect('staging', $this->plan('staging'), $env,
            'example.org', 'https://crm.example.org');

        self::assertSame('configured_unverified', $result['status']);
        self::assertSame('https://webhooks.example.org', $result['configured_webhook_origin']);
    }

    public function test_app_url_can_be_another_authorized_core_host_under_same_root(): void
    {
        $env = $this->clientEnvironment();
        $env['APP_URL'] = 'http://app.example.test';
        $report = $this->inspect('local', null, $env, 'example.test', $env['APP_URL']);
        self::assertSame('configured_unverified', $report['status']);
        self::assertSame('http://webhooks.example.test', $report['configured_webhook_origin']);
    }

    public function test_missing_unrelated_signing_secret_does_not_prevent_origin_inspection(): void
    {
        $plan = $this->plan();
        $plan['ready'] = false;
        $plan['environment_requirements'][] = [
            'key' => 'DONATIONS_EXTERNAL_CHECKOUT_SIGNING_SECRET',
            'scope' => 'client', 'owner' => 'donations',
            'secret' => true, 'status' => 'missing', 'persisted' => false,
            'requirement' => 'required',
        ];

        self::assertSame('configured_unverified', $this->inspect('local', $plan)['status']);
    }

    public function test_wrong_selected_client_or_environment_blocks_without_origin(): void
    {
        $plan = $this->plan();
        $plan['client_key'] = 'other-core';
        $this->assertBlocked($this->inspect('local', $plan), 'target_identity_mismatch');

        $this->assertBlocked($this->inspect('staging', $this->plan()), 'target_identity_mismatch');
    }

    public function test_invalid_environment_and_untrusted_client_are_redacted(): void
    {
        $result = $this->inspect('invalid', $this->plan(), null, null, null, 'bad\nclient');
        $this->assertBlocked($result, 'target_identity_mismatch');
        self::assertNull($result['client_key']);
        self::assertNull($result['environment']);
    }

    public function test_missing_or_misowned_core_requirement_blocks(): void
    {
        $plan = $this->plan();
        array_pop($plan['environment_requirements']);
        $this->assertBlocked($this->inspect('local', $plan), 'core_origin_contract_unresolved');

        foreach (['scope' => 'root', 'owner' => 'some_other_package', 'secret' => true,
            'status' => 'missing', 'persisted' => false, 'requirement' => 'optional'] as $field => $wrong) {
            $plan = $this->plan();
            $plan['environment_requirements'][0][$field] = $wrong;
            $this->assertBlocked($this->inspect('local', $plan), 'core_origin_contract_unresolved');
        }
    }

    public function test_duplicate_origin_requirement_never_counts_as_valid(): void
    {
        $plan = $this->plan();
        $plan['environment_requirements'][] = $plan['environment_requirements'][0];
        $this->assertBlocked($this->inspect('local', $plan), 'core_origin_contract_unresolved');
    }

    public function test_environment_integrity_and_missing_module_metadata_block(): void
    {
        $plan = $this->plan();
        $plan['environment_integrity_findings'] = [['code' => 'wrong_environment_scope']];
        $this->assertBlocked($this->inspect('local', $plan), 'deployment_integrity_unresolved');

        $plan = $this->plan();
        $plan['module_selection']['missing_module_definitions'] = ['donations'];
        $this->assertBlocked($this->inspect('local', $plan), 'deployment_integrity_unresolved');

        $plan = $this->plan();
        unset($plan['module_selection']['known_modules']);
        $this->assertBlocked($this->inspect('local', $plan), 'deployment_integrity_unresolved');
    }

    public function test_no_root_domain_in_selected_client_environment_blocks(): void
    {
        $env = $this->clientEnvironment();
        unset($env['ROOT_DOMAIN']);
        $this->assertBlocked($this->inspect('local', null, $env), 'effective_root_domain_mismatch');
    }

    public function test_wrong_effective_config_or_stale_cache_blocks(): void
    {
        $this->assertBlocked($this->inspect('local', null, null, 'another.test'), 'effective_root_domain_mismatch');
        $this->assertBlocked($this->inspect('local', null, null, null, 'http://crm.another.test'),
            'effective_application_origin_mismatch');
    }

    public function test_invalid_domain_and_unsafe_host_rejected(): void
    {
        foreach (['example..test', 'example.test.', '-example.test', 'UPPER.test', 'example_test',
            'localhost', 'example.test/extra', '127.0.0.1'] as $domain) {
            $env = $this->clientEnvironment();
            $env['ROOT_DOMAIN'] = $domain;
            $this->assertBlocked($this->inspect('local', null, $env, $domain), 'effective_root_domain_mismatch');
        }
    }

    public function test_reserved_test_domain_never_attests_staging(): void
    {
        $env = $this->clientEnvironment();
        $env['APP_URL'] = 'https://crm.example.test';
        $env['CRM_APP_URL'] = 'https://crm.example.test';
        $this->assertBlocked($this->inspect('staging', $this->plan('staging'), $env,
            'example.test', 'https://crm.example.test'), 'effective_root_domain_mismatch');
    }

    public function test_http_or_mixed_scheme_is_not_accepted_on_deployed_environment(): void
    {
        $env = $this->clientEnvironment();
        $env['ROOT_DOMAIN'] = 'example.org';
        $env['APP_URL'] = 'http://crm.example.org';
        $env['CRM_APP_URL'] = 'https://crm.example.org';
        $this->assertBlocked($this->inspect('production', $this->plan('production'), $env,
            'example.org', 'http://crm.example.org'), 'effective_application_origin_mismatch');
    }

    public function test_mismatched_crm_url_or_host_blocks(): void
    {
        $env = $this->clientEnvironment();
        $env['CRM_APP_URL'] = 'http://crm.other.test';
        $this->assertBlocked($this->inspect('local', null, $env), 'core_origin_host_or_scheme_mismatch');

        $env = $this->clientEnvironment();
        $env['APP_URL'] = 'https://crm.example.test';
        $this->assertBlocked($this->inspect('local', null, $env, null, $env['APP_URL']),
            'core_origin_host_or_scheme_mismatch');
    }

    public function test_url_credentials_paths_queries_fragments_or_ports_are_rejected(): void
    {
        foreach ([
            'http://user:password@crm.example.test',
            'http://crm.example.test/path',
            'http://crm.example.test?secret=value',
            'http://crm.example.test#fragment',
            'http://crm.example.test:8080',
            'http://crm.example.test/',
        ] as $url) {
            $env = $this->clientEnvironment();
            $env['CRM_APP_URL'] = $url;
            $this->assertBlocked($this->inspect('local', null, $env), 'effective_application_origin_mismatch');
        }
    }

    public function test_result_is_redacted_on_blocking_or_invalid_input(): void
    {
        $env = $this->clientEnvironment();
        $env['ROOT_DOMAIN'] = 'internal-private.test';
        $env['APP_URL'] = 'http://user:sensitive-password@crm.example.test';
        $result = $this->inspect('local', null, $env, 'example.test', $env['APP_URL']);
        $this->assertBlocked($result);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('internal-private.test', $encoded);
        self::assertStringNotContainsString('sensitive-password', $encoded);
        self::assertStringNotContainsString('http://crm.example.test', $encoded);
    }

    public function test_inspection_does_not_modify_deployment_plan_or_client_environment(): void
    {
        $plan = $this->plan();
        $env = $this->clientEnvironment();
        $original = serialize([$plan, $env]);
        $this->inspect('local', $plan, $env);
        self::assertSame($original, serialize([$plan, $env]));
    }

    /** @return array<string, mixed> */
    private function inspect(
        string $environment = 'local',
        ?array $plan = null,
        ?array $clientEnvironment = null,
        ?string $effectiveRootDomain = null,
        ?string $effectiveAppUrl = null,
        string $expectedClient = 'example-core',
    ): array {
        return (new CoreEndpointOriginInspector())->inspect(
            expectedClient: $expectedClient,
            expectedEnvironment: $environment,
            deploymentPlan: $plan ?? $this->plan(),
            clientEnvironment: $clientEnvironment ?? $this->clientEnvironment(),
            effectiveRootDomain: $effectiveRootDomain ?? 'example.test',
            effectiveAppUrl: $effectiveAppUrl ?? 'http://crm.example.test',
        );
    }

    /** @return array<string, mixed> */
    private function plan(string $environment = 'local'): array
    {
        return [
            'environment' => $environment,
            'client_key' => 'example-core',
            'ready' => true,
            'module_selection' => [
                'known_modules' => ['core', 'donations'],
                'selected_modules' => ['core', 'donations'],
                'missing_module_definitions' => [],
            ],
            'environment_integrity_findings' => [],
            'environment_requirements' => array_map(
                static fn (string $key): array => [
                    'key' => $key, 'scope' => 'client', 'owner' => 'core',
                    'secret' => false, 'requirement' => 'required',
                    'status' => 'ready', 'persisted' => true,
                ],
                ['ROOT_DOMAIN', 'APP_URL', 'CRM_APP_URL'],
            ),
        ];
    }

    /** @return array<string, string> */
    private function clientEnvironment(): array
    {
        return [
            'ROOT_DOMAIN' => 'example.test',
            'APP_URL' => 'http://crm.example.test',
            'CRM_APP_URL' => 'http://crm.example.test',
        ];
    }

    /** @param array<string, mixed> $result */
    private function assertBlocked(array $result, ?string $expectedFinding = null): void
    {
        self::assertSame('blocked', $result['status']);
        self::assertNull($result['configured_webhook_origin']);
        self::assertFalse($result['issuance_allowed']);
        self::assertFalse($result['webhook_routes_verified']);
        if ($expectedFinding !== null) {
            self::assertContains($expectedFinding, $result['findings']);
        }
    }
}