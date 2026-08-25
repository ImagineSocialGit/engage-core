<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Authoring\FlowRoutesAutomationPointAuthoringContributor;
use App\Modules\FlowRoutes\Data\Points\ChangeStatusPointDefinition;
use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\PointHandlers\ChangeStatusPointHandler;
use App\Modules\FlowRoutes\Services\FlowRoutePresetDefinitionFactory;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRouteConditionalStatusChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_compact_preset_preserves_source_status_preconditions(): void
    {
        $preset = app(FlowRoutePresetDefinitionFactory::class)->fromArray(
            presetKey: 'testing',
            definitionKey: 'conditional_status_change',
            data: [
                'name' => 'Conditional Status Change',
                'points' => [
                    'move_to_engaged' => [
                        'type' => 'change_status',
                        'definition' => [
                            'contact_status_key' => 'engaged',
                            'from_contact_status_keys' => [
                                'prospect_nurture',
                                'prospect_new',
                            ],
                        ],
                    ],
                ],
            ],
        );

        $definition = ChangeStatusPointDefinition::from(
            $preset->points[0]->definition,
        );

        $this->assertTrue($definition->isValid());
        $this->assertEqualsCanonicalizing([
            'prospect_nurture',
            'prospect_new',
        ], $definition->fromContactStatusKeys);
    }

    public function test_status_change_uses_fresh_profile_and_skips_a_stale_reply(): void
    {
        $prospectNurture = $this->contactStatus('prospect_nurture', 'Prospect – Nurture');
        $applicationStarted = $this->contactStatus('application_started', 'Application Started');
        $engaged = $this->contactStatus('engaged', 'Engaged');
        $contact = Contact::factory()->create();
        $profile = ContactWorkflowProfile::query()->create([
            'contact_id' => $contact->getKey(),
            'contact_status_id' => $applicationStarted->getKey(),
            'last_status_changed_at' => now(),
            'meta' => [],
        ]);
        $context = $this->context(
            contact: $contact,
            profile: $profile,
            progressStatus: $prospectNurture,
            targetStatus: $engaged,
            fromStatusKeys: ['prospect_nurture'],
        );

        $result = app(ChangeStatusPointHandler::class)->handle($context);

        $this->assertSame(PointExecutionResult::STATUS_SKIPPED, $result->status);
        $this->assertSame('change_status_source_status_not_allowed', $result->reason);
        $this->assertSame('application_started', $result->meta['current_contact_status_key']);
        $this->assertEqualsCanonicalizing(
            ['prospect_nurture'],
            $result->meta['allowed_contact_status_keys'],
        );
        $this->assertDatabaseHas('contact_workflow_profiles', [
            'id' => $profile->getKey(),
            'contact_status_id' => $applicationStarted->getKey(),
        ]);
    }

    public function test_status_change_runs_when_current_profile_still_matches(): void
    {
        $prospectNurture = $this->contactStatus('prospect_nurture', 'Prospect – Nurture');
        $engaged = $this->contactStatus('engaged', 'Engaged');
        $contact = Contact::factory()->create();
        $profile = ContactWorkflowProfile::query()->create([
            'contact_id' => $contact->getKey(),
            'contact_status_id' => $prospectNurture->getKey(),
            'last_status_changed_at' => now(),
            'meta' => [],
        ]);
        $context = $this->context(
            contact: $contact,
            profile: $profile,
            progressStatus: $prospectNurture,
            targetStatus: $engaged,
            fromStatusKeys: ['prospect_nurture'],
        );

        $result = app(ChangeStatusPointHandler::class)->handle($context);

        $this->assertSame(PointExecutionResult::STATUS_COMPLETED, $result->status);
        $this->assertSame('workflow_status_changed', $result->reason);
        $this->assertDatabaseHas('contact_workflow_profiles', [
            'id' => $profile->getKey(),
            'contact_status_id' => $engaged->getKey(),
        ]);
    }

    public function test_status_editor_preserves_the_runtime_precondition(): void
    {
        $prospectNurture = $this->contactStatus('prospect_nurture', 'Prospect – Nurture');
        $engaged = $this->contactStatus('engaged', 'Engaged');
        $route = $this->route();
        $point = $this->point(
            route: $route,
            targetStatus: $engaged,
            fromStatusKeys: [$prospectNurture->key],
        );
        $context = new AutomationPointAuthoringContext(
            existingPointTypes: ['change_status'],
            container: $route,
            point: $point,
        );
        $contributor = app(FlowRoutesAutomationPointAuthoringContributor::class);

        $built = $contributor->buildDefinition(
            pointType: 'change_status',
            input: ['contact_status_key' => 'engaged'],
            context: $context,
        );
        $fields = $contributor->fields(
            pointType: 'change_status',
            definition: $point->definition,
            context: $context,
        );

        $this->assertEqualsCanonicalizing(
            ['prospect_nurture'],
            $built['from_contact_status_keys'],
        );
        $this->assertSame('change_status', $built['reason']);
        $this->assertSame('select', $fields[0]['type']);
        $this->assertSame('notice', $fields[1]['type']);
    }

    private function contactStatus(string $key, string $name): ContactStatus
    {
        return ContactStatus::query()->create([
            'key' => $key,
            'name' => $name,
            'is_core' => false,
            'is_active' => true,
            'sort_order' => 10,
            'meta' => [],
        ]);
    }

    private function route(): FlowRoute
    {
        return FlowRoute::query()->create([
            'key' => 'conditional-status-'.uniqid(),
            'name' => 'Conditional Status Route',
            'version' => 1,
            'is_current_version' => true,
            'trigger_type' => FlowRoute::TRIGGER_MANUAL,
            'trigger_key' => null,
            'is_active' => true,
            'is_customized' => false,
            'meta' => [],
        ]);
    }

    /** @param array<int, string> $fromStatusKeys */
    private function point(
        FlowRoute $route,
        ContactStatus $targetStatus,
        array $fromStatusKeys,
    ): FlowRoutePoint {
        return FlowRoutePoint::query()->create([
            'flow_route_id' => $route->getKey(),
            'key' => 'change-status-'.uniqid(),
            'type' => 'change_status',
            'name' => 'Change Status',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'definition' => [
                'contact_status_key' => $targetStatus->key,
                'from_contact_status_keys' => $fromStatusKeys,
                'reason' => 'change_status',
                'on_same_status' => 'skipped',
            ],
            'settings' => [],
            'cancel_conditions' => [],
            'is_customized' => false,
            'meta' => [],
        ]);
    }

    /** @param array<int, string> $fromStatusKeys */
    private function context(
        Contact $contact,
        ContactWorkflowProfile $profile,
        ContactStatus $progressStatus,
        ContactStatus $targetStatus,
        array $fromStatusKeys,
    ): PointExecutionContext {
        $route = $this->route();
        $point = $this->point($route, $targetStatus, $fromStatusKeys);
        $progress = ContactFlowRouteProgress::query()->create([
            'contact_id' => $contact->getKey(),
            'contact_status_id' => $progressStatus->getKey(),
            'contact_workflow_profile_id' => $profile->getKey(),
            'flow_route_id' => $route->getKey(),
            'current_flow_route_point_id' => $point->getKey(),
            'status' => ContactFlowRouteProgress::STATUS_ACTIVE,
            'started_at' => now(),
            'meta' => [],
        ]);

        return new PointExecutionContext(
            progress: $progress,
            flowRoutePoint: $point,
            definition: $point->definition,
            settings: $point->settings,
        );
    }
}