<?php

namespace Tests\Unit\Deployment;

use App\Support\Deployment\Credentials\CoreCredentialRecipientInspector;
use App\Support\Deployment\Credentials\CoreCredentialCallerKey;
use PHPUnit\Framework\TestCase;

final class CoreCredentialRecipientInspectorTest extends TestCase
{
    private const SECRET = 'very-private-first-party-secret-material-1234567890';

    public function test_selected_donations_caller_reports_configured_but_never_authorizes_issuance(): void
    {
        $key = $this->key('nonprofit_primary');
        $report = $this->inspect(
            'donations.external_checkout', 'seo', 'nonprofit_primary',
            'https://nonprofit.example',
            $this->plan('donations'),
            ['enabled' => true, 'clients' => [
                'nonprofit_primary' => ['secret_env_key' => $key, 'site_origin' => 'https://nonprofit.example'],
                'nonprofit_secondary' => ['secret_env_key' => $this->key('nonprofit_secondary'), 'site_origin' => 'https://another.example'],
            ]],
            'staging',
            [$key => str_repeat('S', 64)],
        );

        self::assertSame('configured_unverified', $report['status']);
        self::assertTrue($report['caller_slot_present']);
        self::assertTrue($report['source_origin_match']);
        self::assertFalse($report['issuance_allowed']);
        self::assertFalse($report['authorization_verified']);
        self::assertFalse($report['credential_match_verified']);
        self::assertStringNotContainsString(self::SECRET, json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function test_installed_but_inactive_donations_needs_no_pair(): void
    {
        $report = $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'https://nonprofit.example', $this->plan('donations'),
            ['enabled' => false, 'clients' => []], 'staging');

        self::assertSame('not_required', $report['status']);
        self::assertFalse($report['capability_selected']);
    }

    public function test_absent_module_is_not_selected_even_with_configured_caller(): void
    {
        $plan = $this->plan('donations');
        $plan['module_selection']['selected_modules'] = ['core'];
        $plan['enabled_modules'] = ['core'];

        $report = $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'https://nonprofit.example', $plan,
            ['enabled' => true, 'clients' => ['test_site' => ['secret_env_key' => $this->key('test_site'), 'site_origin' => 'https://nonprofit.example']]],
            'staging');

        self::assertSame('not_required', $report['status']);
    }

    public function test_newly_selected_caller_without_slot_is_needs_provisioning_not_authorized(): void
    {
        $report = $this->inspect('donations.external_checkout', 'seo', 'new_site',
            'https://nonprofit.example', $this->plan('donations'),
            ['enabled' => true, 'clients' => []], 'staging');

        self::assertSame('needs_provisioning', $report['status']);
        self::assertFalse($report['caller_slot_present']);
        self::assertFalse($report['writes_allowed']);
    }

    public function test_existing_slot_without_secret_requires_provisioning(): void
    {
        $report = $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'https://nonprofit.example', $this->plan('donations'),
            ['enabled' => true, 'clients' => ['test_site' => ['secret_env_key' => $this->key('test_site'), 'site_origin' => 'https://nonprofit.example']]],
            'staging');

        self::assertSame('needs_provisioning', $report['status']);
        self::assertTrue($report['caller_slot_present']);
        self::assertFalse($report['secret_present']);
    }

    public function test_multi_caller_does_not_require_single_caller_env_shorthand(): void
    {
        $plan = $this->plan('donations');
        foreach ($plan['environment_requirements'] as &$requirement) {
            $requirement['requirement'] = 'optional';
        }
        unset($requirement);

        $report = $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'https://nonprofit.example', $plan,
            ['enabled' => true, 'clients' => ['test_site' => ['secret_env_key' => $this->key('test_site'), 'site_origin' => 'https://nonprofit.example']]],
            'staging', [$this->key('test_site') => str_repeat('S', 64)]);

        self::assertSame('configured_unverified', $report['status']);
    }

    public function test_wrong_donations_origin_fails_without_echoing_it(): void
    {
        $report = $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'https://nonprofit.example', $this->plan('donations'),
            ['enabled' => true, 'clients' => ['test_site' => ['secret_env_key' => $this->key('test_site'), 'site_origin' => 'https://unrelated.example']]],
            'staging');

        self::assertSame('blocked', $report['status']);
        self::assertContains('caller_origin_mismatch', $report['findings']);
        self::assertStringNotContainsString('unrelated.example', json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function test_donations_declared_key_must_be_present_in_selected_client_environment(): void
    {
        $key = $this->key('test_site');
        $config = ['enabled' => true, 'clients' => [
            'test_site' => ['site_origin' => 'https://nonprofit.example', 'secret_env_key' => $key],
        ]];
        $missing = $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'https://nonprofit.example', $this->plan('donations'), $config, 'staging');
        self::assertSame('needs_provisioning', $missing['status']);
        self::assertFalse($missing['secret_present']);

        $present = $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'https://nonprofit.example', $this->plan('donations'), $config, 'staging',
            [$key => str_repeat('A', 64)]);
        self::assertSame('configured_unverified', $present['status']);
        self::assertTrue($present['secret_present']);
        self::assertFalse($present['credential_match_verified']);
    }

    public function test_donations_rejects_inline_binding_conflict_wrong_scope_and_invalid_value(): void
    {
        $key = $this->key('test_site');
        $config = ['enabled' => true, 'clients' => [
            'test_site' => ['site_origin' => 'https://nonprofit.example', 'secret_env_key' => $key],
        ]];
        $plan = $this->plan('donations');

        $wrongScope = $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'https://nonprofit.example', $plan, $config, 'staging', [], [$key => str_repeat('A', 64)]);
        self::assertContains('caller_secret_wrong_scope', $wrongScope['findings']);

        $bad = $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'https://nonprofit.example', $plan, $config, 'staging', [$key => 'injected-secret=bad']);
        self::assertContains('caller_secret_invalid', $bad['findings']);
        self::assertStringNotContainsString('injected-secret', json_encode($bad, JSON_THROW_ON_ERROR));

        $config['clients']['test_site']['secret'] = str_repeat('Z', 64);
        $inline = $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'https://nonprofit.example', $plan, $config, 'staging', [$key => str_repeat('A', 64)]);
        self::assertContains('unsupported_inline_signing_secret', $inline['findings']);
    }

    public function test_invalid_binding_or_corrupt_env_blocks_without_exposing_values(): void
    {
        $key = $this->key('test_site');
        $config = ['enabled' => true, 'clients' => [
            'test_site' => ['site_origin' => 'https://nonprofit.example', 'secret_env_key' => $this->key('other_site')],
        ]];
        $wrong = $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'https://nonprofit.example', $this->plan('donations'), $config, 'staging');
        self::assertContains('caller_secret_binding_invalid', $wrong['findings']);

        $config['clients']['test_site']['secret_env_key'] = $key;
        $broken = $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'https://nonprofit.example', $this->plan('donations'), $config, 'staging',
            [$key => str_repeat('A', 64)], [], [['code' => 'duplicate_environment_key', 'key' => $key]]);
        self::assertSame('blocked', $broken['status']);
        self::assertContains('environment_file_integrity_invalid', $broken['findings']);
        self::assertStringNotContainsString($key, json_encode($broken, JSON_THROW_ON_ERROR));
    }

    public function test_forms_recipient_keeps_source_identity_distinct_from_authorized_peer(): void
    {
        $report = $this->inspect('forms.external_intake', 'artist', 'artist_one',
            'https://artist.example', $this->plan('forms'),
            ['enabled' => true, 'clients' => ['artist_one' => [
                'source' => 'artist-site', 'provider' => 'artist_one',
                'allowed_forms' => ['newsletter_signup'], 'secret' => self::SECRET,
                'domains' => [],
            ]]], 'staging');

        self::assertSame('configured_unverified', $report['status']);
        self::assertNull($report['source_origin_match']);
        self::assertFalse($report['peer_verified']);
        self::assertFalse($report['authorization_verified']);
    }

    public function test_forms_metadata_missing_blocks_even_with_valid_secret(): void
    {
        $report = $this->inspect('forms.external_intake', 'seo', 'site_one',
            'https://nonprofit.example', $this->plan('forms'),
            ['enabled' => true, 'clients' => ['site_one' => ['secret' => self::SECRET]]], 'staging');

        self::assertSame('blocked', $report['status']);
        self::assertContains('caller_metadata_invalid', $report['findings']);
    }

    public function test_module_owner_scope_and_selected_definition_checks_fail_closed(): void
    {
        $plan = $this->plan('donations');
        $plan['environment_requirements'][0]['scope'] = 'root';
        self::assertSame('blocked', $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'https://nonprofit.example', $plan, ['enabled' => true, 'clients' => []], 'staging')['status']);

        $plan = $this->plan('donations');
        $plan['module_selection']['missing_module_definitions'] = ['donations'];
        self::assertSame('blocked', $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'https://nonprofit.example', $plan, ['enabled' => true, 'clients' => []], 'staging')['status']);

        $plan = $this->plan('donations');
        $plan['environment_integrity_findings'] = [['code' => 'malformed_environment_file']];
        self::assertSame('blocked', $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'https://nonprofit.example', $plan, ['enabled' => true, 'clients' => []], 'staging')['status']);
    }

    public function test_environment_identity_and_scheme_are_enforced(): void
    {
        $report = $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'http://nonprofit.example', $this->plan('donations'),
            ['enabled' => true, 'clients' => []], 'production');
        self::assertSame('blocked', $report['status']);
        self::assertContains('invalid_source_origin', $report['findings']);

        $report = $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'http://nonprofit.example', $this->plan('donations'),
            ['enabled' => true, 'clients' => ['test_site' => [
                'secret_env_key' => $this->key('test_site'),
                'site_origin' => 'http://nonprofit.example',
            ]]], 'local');
        self::assertSame('needs_provisioning', $report['status']);
    }

    public function test_unrecognized_pair_and_untrusted_ids_are_redacted_and_blocked(): void
    {
        $report = $this->inspect('donations.external_checkout', 'artist', 'secret-\nvalue',
            'https://nonprofit.example', $this->plan('donations'),
            ['enabled' => true, 'clients' => []], 'staging');

        self::assertSame('blocked', $report['status']);
        self::assertNull($report['caller_id']);
        self::assertFalse($report['issuance_allowed']);
    }

    public function test_dependency_resolved_module_is_eligible_when_capability_is_selected(): void
    {
        $plan = $this->plan('forms');
        $plan['module_selection']['selected_modules'] = ['core', 'some_module'];
        $plan['module_selection']['known_modules'][] = 'some_module';
        $report = $this->inspect('forms.external_intake', 'seo', 'site_one',
            'https://nonprofit.example', $plan,
            ['enabled' => true, 'clients' => []], 'staging');

        self::assertSame('needs_provisioning', $report['status']);
    }

    public function test_mismatched_target_identity_blocks_without_provisioning(): void
    {
        $plan = $this->plan('donations');
        $plan['client_key'] = 'some-other-core';
        $report = $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'https://nonprofit.example', $plan,
            ['enabled' => true, 'clients' => []], 'staging');

        self::assertSame('blocked', $report['status']);
        self::assertContains('target_identity_mismatch', $report['findings']);
        self::assertFalse($report['writes_allowed']);
    }

    public function test_inspection_does_not_mutate_configuration(): void
    {
        $config = ['enabled' => true, 'clients' => ['test_site' => [
            'secret' => self::SECRET, 'site_origin' => 'https://nonprofit.example',
        ]]];
        $before = serialize($config);

        $this->inspect('donations.external_checkout', 'seo', 'test_site',
            'https://nonprofit.example', $this->plan('donations'), $config, 'staging');

        self::assertSame($before, serialize($config));
    }

    /** @return array<string, mixed> */
    private function inspect(
        string $purpose,
        string $source,
        string $caller,
        string $origin,
        array $plan,
        mixed $config,
        string $environment,
        array $clientEnvironment = [],
        array $rootEnvironment = [],
        array $issues = [],
    ): array {
        $plan['environment'] = $environment;

        return (new CoreCredentialRecipientInspector())->inspect(
            purpose: $purpose,
            sourcePlatform: $source,
            callerId: $caller,
            sourceOrigin: $origin,
            expectedClient: 'example-core',
            expectedEnvironment: $environment,
            deploymentPlan: $plan,
            capabilityConfig: $config,
            persistedClientEnvironment: $clientEnvironment,
            persistedRootEnvironment: $rootEnvironment,
            environmentIssues: $issues,
        );
    }

    private function key(string $caller): string
    {
        return (string) CoreCredentialCallerKey::forCaller('donations.external_checkout', 'seo', $caller);
    }

    /** @return array<string, mixed> */
    private function plan(string $module): array
    {
        $owner = $module === 'donations' ? 'donations' : 'forms';
        $keys = $module === 'donations'
            ? ['DONATIONS_EXTERNAL_CHECKOUT_CLIENT_ID', 'DONATIONS_EXTERNAL_CHECKOUT_SIGNING_SECRET']
            : ['FORMS_EXTERNAL_INTAKE_CLIENT_ID', 'FORMS_EXTERNAL_INTAKE_CLIENT_SECRET'];

        return [
            'client_key' => 'example-core',
            'environment' => 'staging',
            'enabled_modules' => ['core', $module],
            'covered_owners' => ['core', $owner],
            'module_selection' => [
                'selected_modules' => ['core', $module],
                'known_modules' => ['core', $module],
                'missing_module_definitions' => [],
            ],
            'environment_integrity_findings' => [],
            'environment_requirements' => [
                ['key' => $keys[0], 'scope' => 'client', 'owner' => $owner, 'secret' => false, 'requirement' => 'required'],
                ['key' => $keys[1], 'scope' => 'client', 'owner' => $owner, 'secret' => true, 'requirement' => 'required'],
            ],
        ];
    }
}