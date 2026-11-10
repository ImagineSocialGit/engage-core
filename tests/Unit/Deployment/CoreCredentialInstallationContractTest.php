<?php

namespace Tests\Unit\Deployment;

use App\Support\Deployment\Credentials\CoreCredentialInstallationContract;
use PHPUnit\Framework\TestCase;

final class CoreCredentialInstallationContractTest extends TestCase
{
    private const SECRET = 'valid-existing-credential-material-long-enough-to-be-accepted';

    public function test_separate_callers_have_stable_distinct_client_scoped_secret_keys(): void
    {
        $contract = new CoreCredentialInstallationContract();
        $one = $contract->keyFor('forms.external_intake', 'seo', 'site_one');
        $two = $contract->keyFor('forms.external_intake', 'seo', 'site.two');
        $three = $contract->keyFor('forms.external_intake', 'artist', 'site_one');
        self::assertSame($one, $contract->keyFor('forms.external_intake', 'seo', 'site_one'));
        self::assertCount(3, array_unique([$one, $two, $three]));
        self::assertMatchesRegularExpression('/\AFORMS_EXTERNAL_INTAKE_CALLER_[A-F0-9]{32}_SECRET\z/D', $one);
    }

    public function test_donations_uses_its_own_namespace_and_caller_identity(): void
    {
        $contract = new CoreCredentialInstallationContract();
        $one = $contract->keyFor('donations.external_checkout', 'seo', 'site_one');
        self::assertMatchesRegularExpression('/\ADONATIONS_EXTERNAL_CHECKOUT_CALLER_[A-F0-9]{32}_SIGNING_SECRET\z/D', $one);
        self::assertNotSame($one, $contract->keyFor('forms.external_intake', 'seo', 'site_one'));
    }

    public function test_unknown_or_incompatible_purpose_never_derives_an_assignment(): void
    {
        $contract = new CoreCredentialInstallationContract();
        self::assertNull($contract->keyFor('donations.external_checkout', 'artist', 'site_one'));
        self::assertNull($contract->keyFor('stripe.api', 'seo', 'site_one'));
        self::assertNull($contract->keyFor('forms.external_intake', 'unknown', 'site_one'));
        self::assertNull($contract->keyFor('forms.external_intake', 'seo', 'bad\nsite'));
    }

    public function test_missing_caller_slot_is_not_authorized_even_when_capability_is_selected(): void
    {
        $result = $this->inspect('donations.external_checkout', ['enabled' => true, 'clients' => []]);
        $this->assertBlocked($result, 'caller_metadata_not_declared');
        self::assertNull($result['candidate_client_environment_key']);
    }

    public function test_enabled_but_unbound_caller_must_wait_for_owner_adapter(): void
    {
        $result = $this->inspect('donations.external_checkout', $this->donationsSlot());
        $this->assertBlocked($result, 'owner_runtime_binding_not_declared');
        self::assertFalse($result['delivery_allowed']);
        self::assertFalse($result['runtime_binding_verified']);
    }

    public function test_declared_per_caller_binding_is_a_candidate_not_permission_to_write(): void
    {
        $key = (new CoreCredentialInstallationContract())->keyFor('donations.external_checkout', 'seo', 'site_one');
        $config = $this->donationsSlot();
        $config['clients']['site_one']['secret_env_key'] = $key;
        $result = $this->inspect('donations.external_checkout', $config);
        self::assertSame('pending_owner_runtime_support', $result['status']);
        self::assertSame($key, $result['candidate_client_environment_key']);
        self::assertTrue($result['owner_binding_declared']);
        self::assertFalse($result['delivery_allowed']);
        self::assertFalse($result['credential_equality_verified']);
    }

    public function test_wrong_binding_key_cannot_redirect_secret_to_another_slot(): void
    {
        $config = $this->donationsSlot();
        $config['clients']['site_one']['secret_env_key'] = (new CoreCredentialInstallationContract())->keyFor(
            'donations.external_checkout', 'seo', 'another_site'
        );
        $this->assertBlocked($this->inspect('donations.external_checkout', $config), 'owner_runtime_binding_mismatch');
    }

    public function test_existing_legacy_secret_is_preserved_without_forcing_new_environment_key(): void
    {
        $config = $this->donationsSlot();
        $config['clients']['site_one']['secret'] = self::SECRET;
        $result = $this->inspect('donations.external_checkout', $config);
        self::assertSame('existing_secret_unverified', $result['status']);
        self::assertNull($result['candidate_client_environment_key']);
        self::assertFalse($result['credential_equality_verified']);
    }

    public function test_existing_legacy_secret_and_new_binding_cannot_be_combined_silently(): void
    {
        $config = $this->donationsSlot();
        $config['clients']['site_one']['secret'] = self::SECRET;
        $config['clients']['site_one']['secret_env_key'] = (new CoreCredentialInstallationContract())->keyFor(
            'donations.external_checkout', 'seo', 'site_one'
        );
        $this->assertBlocked($this->inspect('donations.external_checkout', $config), 'existing_secret_binding_requires_reconciliation');
    }

    public function test_present_persisted_value_is_unverified_not_safe_to_replace(): void
    {
        $key = (new CoreCredentialInstallationContract())->keyFor('donations.external_checkout', 'seo', 'site_one');
        $config = $this->donationsSlot();
        $config['clients']['site_one']['secret_env_key'] = $key;
        $result = $this->inspect('donations.external_checkout', $config, [$key => str_repeat('A', 64)]);
        self::assertSame('existing_secret_unverified', $result['status']);
        self::assertFalse($result['runtime_binding_verified']);
    }

    public function test_invalid_existing_value_blocks_without_exposing_it(): void
    {
        $key = (new CoreCredentialInstallationContract())->keyFor('donations.external_checkout', 'seo', 'site_one');
        $config = $this->donationsSlot();
        $config['clients']['site_one']['secret_env_key'] = $key;
        $result = $this->inspect('donations.external_checkout', $config, [$key => 'secret-injection=value']);
        $this->assertBlocked($result, 'existing_internal_credential_invalid');
        self::assertStringNotContainsString('secret-injection', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_different_selected_environment_does_not_authorize_same_key_material(): void
    {
        $config = $this->donationsSlot();
        $plan = $this->plan('donations', 'staging');
        $this->assertBlocked($this->inspect('donations.external_checkout', $config, [], $plan, 'production'), 'target_identity_mismatch');
    }

    public function test_offline_only_donations_does_not_request_signing_material(): void
    {
        $result = $this->inspect('donations.external_checkout', ['enabled' => false, 'clients' => []]);
        self::assertSame('not_required', $result['status']);
        self::assertNull($result['candidate_client_environment_key']);
    }

    public function test_invalid_selected_client_metadata_blocks_without_new_key(): void
    {
        $plan = $this->plan('donations');
        $plan['module_selection']['missing_module_definitions'] = ['donations'];
        $result = $this->inspect('donations.external_checkout', $this->donationsSlot(), [], $plan);
        $this->assertBlocked($result, 'deployment_evidence_incomplete');
    }

    public function test_forms_multi_caller_binding_does_not_touch_other_slots(): void
    {
        $contract = new CoreCredentialInstallationContract();
        $key = $contract->keyFor('forms.external_intake', 'seo', 'site_one');
        $result = $this->inspect('forms.external_intake', [
            'enabled' => true,
            'clients' => [
                'site_one' => ['secret' => null, 'secret_env_key' => $key, 'source' => 'seo-site', 'provider' => 'site_one', 'allowed_forms' => ['contact']],
                'site_two' => ['secret' => self::SECRET, 'source' => 'seo-site', 'provider' => 'site_two', 'allowed_forms' => ['newsletter']],
            ],
        ]);
        self::assertSame('pending_owner_runtime_support', $result['status']);
        self::assertSame($key, $result['candidate_client_environment_key']);
    }

    public function test_forms_missing_metadata_blocks_despite_valid_secret_reference(): void
    {
        $key = (new CoreCredentialInstallationContract())->keyFor('forms.external_intake', 'seo', 'site_one');
        $this->assertBlocked($this->inspect('forms.external_intake', [
            'enabled' => true,
            'clients' => ['site_one' => ['secret' => null, 'secret_env_key' => $key]],
        ]), 'caller_metadata_invalid');
    }

    public function test_unknown_purpose_cannot_become_a_secret_assignment(): void
    {
        $result = $this->inspect('provider_api_key', $this->donationsSlot());
        self::assertSame('blocked', $result['status']);
        self::assertNull($result['candidate_client_environment_key']);
        self::assertFalse($result['writes_allowed']);
    }

    public function test_public_report_does_not_contain_secret_values_or_local_paths(): void
    {
        $config = $this->donationsSlot();
        $config['clients']['site_one']['secret'] = self::SECRET;
        $report = $this->inspect('donations.external_checkout', $config);
        $json = json_encode($report, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::SECRET, $json);
        self::assertStringNotContainsString('/var/www/', $json);
        self::assertFalse($report['writes_allowed']);
    }

    /** @return array<string,mixed> */
    private function donationsSlot(): array
    {
        return ['enabled' => true, 'clients' => [
            'site_one' => ['secret' => null, 'site_origin' => 'https://nonprofit.example'],
        ]];
    }

    /** @param array<string, mixed> $config @param array<string, mixed> $env @param array<string, mixed>|null $plan */
    private function inspect(string $purpose, array $config, array $env = [], ?array $plan = null, string $environment = 'staging'): array
    {
        $plan ??= $this->plan($purpose === 'forms.external_intake' ? 'forms' : 'donations', $environment);
        return (new CoreCredentialInstallationContract())->inspect(
            purpose: $purpose,
            sourcePlatform: 'seo',
            callerId: 'site_one',
            sourceOrigin: 'https://nonprofit.example',
            expectedClient: 'example-core',
            expectedEnvironment: $environment,
            deploymentPlan: $plan,
            capabilityConfig: $config,
            persistedClientEnvironment: $env,
        );
    }

    /** @return array<string,mixed> */
    private function plan(string $module, string $environment = 'staging'): array
    {
        $keys = $module === 'donations'
            ? ['DONATIONS_EXTERNAL_CHECKOUT_CLIENT_ID', 'DONATIONS_EXTERNAL_CHECKOUT_SIGNING_SECRET']
            : ['FORMS_EXTERNAL_INTAKE_CLIENT_ID', 'FORMS_EXTERNAL_INTAKE_CLIENT_SECRET'];
        return [
            'client_key' => 'example-core',
            'environment' => $environment,
            'enabled_modules' => ['core', $module],
            'covered_owners' => ['core', $module],
            'module_selection' => [
                'selected_modules' => ['core', $module],
                'known_modules' => ['core', $module],
                'missing_module_definitions' => [],
            ],
            'environment_integrity_findings' => [],
            'environment_requirements' => [
                ['key' => $keys[0], 'scope' => 'client', 'owner' => $module, 'secret' => false, 'requirement' => 'optional'],
                ['key' => $keys[1], 'scope' => 'client', 'owner' => $module, 'secret' => true, 'requirement' => 'optional'],
            ],
        ];
    }

    private function assertBlocked(array $report, string $finding): void
    {
        self::assertSame('blocked', $report['status']);
        self::assertContains($finding, $report['findings']);
        self::assertFalse($report['writes_allowed']);
    }
}