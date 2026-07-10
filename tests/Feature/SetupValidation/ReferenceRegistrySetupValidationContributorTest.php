<?php

namespace Tests\Feature\SetupValidation;

use App\Support\SetupValidation\Contributors\ReferenceRegistrySetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ReferenceRegistrySetupValidationContributorTest extends TestCase
{
    public function test_it_warns_for_stale_active_registry_keys_and_undocumented_runtime_keys(): void
    {
        Config::set('reference.keys.point_types', [
            'noop',
            '__stale_point_type__',
        ]);

        $findings = $this->findings();
        $codes = array_column($findings, 'code');

        $this->assertContains('app.reference.stale_unused_key', $codes);
        $this->assertContains('app.reference.runtime_key_undocumented', $codes);

        $this->assertSame(
            [SetupValidationFinding::SEVERITY_WARNING],
            array_values(array_unique(array_column($findings, 'severity'))),
        );
    }

    public function test_future_registry_entries_are_not_treated_as_stale_active_keys(): void
    {
        Config::set('reference.keys.campaign_keys', [
            '__future_campaign__' => [
                'description' => 'Future campaign',
                'status' => 'future',
            ],
        ]);

        Config::set('presets.modules.webinars.campaigns.definitions', []);

        $stale = array_values(array_filter(
            $this->findings(),
            fn (array $finding): bool => $finding['code'] === 'app.reference.stale_unused_key'
                && ($finding['context']['key'] ?? null) === '__future_campaign__',
        ));

        $this->assertSame([], $stale);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findings(): array
    {
        return array_map(
            fn (SetupValidationFinding $finding): array => $finding->toArray(),
            iterator_to_array(
                app(ReferenceRegistrySetupValidationContributor::class)->findings(),
                false,
            ),
        );
    }
}
