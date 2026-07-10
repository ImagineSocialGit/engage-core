<?php

namespace Tests\Feature\Presets;

use App\Modules\Campaigns\Actions\SyncCampaignPresetsAction;
use App\Modules\Core\Actions\ContactStatuses\SyncContactStatusPresetsAction;
use App\Modules\FlowRoutes\Actions\SyncFlowRoutePresetsAction;
use App\Modules\Tasks\Actions\SyncTaskPresetsAction;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
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
}
