<?php

namespace Tests\Feature\Presets;

use App\Modules\Campaigns\Actions\SyncCampaignPresetsAction;
use App\Modules\Core\Actions\ContactStatuses\SyncContactStatusPresetsAction;
use App\Modules\FlowRoutes\Actions\SyncFlowRoutePresetsAction;
use App\Modules\Tasks\Actions\SyncTaskPresetsAction;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PresetCompositionSyncCutoverTest extends TestCase
{
    public function test_package_driven_sync_actions_accept_resolved_domain_composition(): void
    {
        config()->set('presets.packages.cutover_test', [
            'groups' => [
                'contact_statuses' => [],
                'tasks' => [],
                'campaigns' => [],
                'flow_routes' => [],
            ],
        ]);

        $resolver = app(PresetCompositionResolver::class);

        $contactResult = app(SyncContactStatusPresetsAction::class)->handle(
            $resolver->resolve('cutover_test', PresetDomain::ContactStatuses),
        );
        $taskResult = app(SyncTaskPresetsAction::class)->handle(
            $resolver->resolve('cutover_test', PresetDomain::Tasks),
        );
        $campaignResult = app(SyncCampaignPresetsAction::class)->handle(
            $resolver->resolve('cutover_test', PresetDomain::Campaigns),
        );
        $flowRouteResult = app(SyncFlowRoutePresetsAction::class)->handle(
            $resolver->resolve('cutover_test', PresetDomain::FlowRoutes),
        );

        $this->assertSame(0, $contactResult['created']);
        $this->assertSame(0, $taskResult->created);
        $this->assertSame(0, $campaignResult->campaignsCreated);
        $this->assertSame(0, $flowRouteResult->created['flow_routes'] ?? 0);
    }

    public function test_missing_preset_reports_available_packages_and_missing_client_preset_config(): void
    {
        $clientRoot = storage_path('framework/testing/preset-diagnostics/missing-client-config');

        File::deleteDirectory($clientRoot);
        File::ensureDirectoryExists($clientRoot);

        config()->set('client.key', 'missing-client-config');
        config()->set('client.config_path', $clientRoot);
        config()->set('presets.packages', [
            'basic' => [],
            'messaging' => [],
            'automated_messaging' => [],
        ]);

        $this->artisan('presets:sync', ['preset' => 'mortgage'])
            ->expectsOutputToContain('Preset package [mortgage] does not exist.')
            ->expectsOutputToContain('Available preset packages')
            ->expectsOutputToContain('  - basic')
            ->expectsOutputToContain('  - messaging')
            ->expectsOutputToContain('  - automated_messaging')
            ->expectsOutputToContain('Client preset config')
            ->expectsOutputToContain('Client: missing-client-config')
            ->expectsOutputToContain('Expected path: '.$clientRoot.DIRECTORY_SEPARATOR.'presets.php')
            ->expectsOutputToContain('Exists: no')
            ->assertExitCode(1);

        File::deleteDirectory($clientRoot);
    }

    public function test_missing_preset_reports_when_client_preset_config_exists(): void
    {
        $clientRoot = storage_path('framework/testing/preset-diagnostics/existing-client-config');

        File::deleteDirectory($clientRoot);
        File::ensureDirectoryExists($clientRoot);
        File::put(
            $clientRoot.DIRECTORY_SEPARATOR.'presets.php',
            "<?php\n\nreturn ['packages' => []];\n",
        );

        config()->set('client.key', 'existing-client-config');
        config()->set('client.config_path', $clientRoot);
        config()->set('presets.packages', [
            'basic' => [],
            'messaging' => [],
        ]);

        $this->artisan('presets:sync', ['preset' => 'mortgage'])
            ->expectsOutputToContain('Preset package [mortgage] does not exist.')
            ->expectsOutputToContain('Available preset packages')
            ->expectsOutputToContain('  - basic')
            ->expectsOutputToContain('  - messaging')
            ->expectsOutputToContain('Client preset config')
            ->expectsOutputToContain('Client: existing-client-config')
            ->expectsOutputToContain('Expected path: '.$clientRoot.DIRECTORY_SEPARATOR.'presets.php')
            ->expectsOutputToContain('Exists: yes')
            ->assertExitCode(1);

        File::deleteDirectory($clientRoot);
    }
}
