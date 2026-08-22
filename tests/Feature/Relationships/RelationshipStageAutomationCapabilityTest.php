<?php

namespace Tests\Feature\Relationships;

use App\Modules\Core\Models\Contact;
use App\Modules\Relationships\Actions\UpsertContactRelationshipAction;
use App\Modules\Relationships\Automation\ChangeRelationshipStageAutomationActionHandler;
use App\Modules\Relationships\Automation\RelationshipStageAutomationPointAuthoringContributor;
use App\Modules\Relationships\Automation\RelationshipStageAutomationPointDefinitionContributor;
use App\Modules\Relationships\Capabilities\RelationshipsAutomationCapabilityContributor;
use App\Modules\Relationships\Models\ContactRelationship;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\AutomationCapabilities\Data\AutomationPointValidationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationshipStageAutomationCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('relationships.types', [
            'consumer' => [
                'singular' => 'Lead',
                'plural' => 'Leads',
                'visible' => true,
                'sort_order' => 10,
                'stages' => [
                    'nurture' => 'Nurture',
                ],
            ],
            'realtor' => [
                'singular' => 'Realtor',
                'plural' => 'Realtors',
                'visible' => true,
                'sort_order' => 20,
                'stages' => [
                    'target_agent' => [
                        'label' => 'Target Agent',
                        'sort_order' => 10,
                        'active' => true,
                    ],
                    'engaged_agent' => [
                        'label' => 'Engaged Agent',
                        'sort_order' => 20,
                        'active' => true,
                    ],
                    'legacy_agent' => [
                        'label' => 'Legacy Agent',
                        'sort_order' => 90,
                        'active' => false,
                    ],
                ],
            ],
        ]);
    }

    public function test_relationships_contributes_change_stage_capability_and_authoring_target(): void
    {
        $capability = collect(iterator_to_array(
            app(RelationshipsAutomationCapabilityContributor::class)->definitions(),
        ))->firstWhere('key', 'relationships.change_stage');

        $this->assertSame('change_relationship_stage', $capability?->pointType);
        $this->assertSame('relationships.change_stage', $capability?->actionKey);

        $authoring = app(RelationshipStageAutomationPointAuthoringContributor::class);
        $context = new AutomationPointAuthoringContext();

        $this->assertTrue($authoring->available('change_relationship_stage', $context));

        $fields = $authoring->fields('change_relationship_stage', [], $context);
        $options = collect($fields[0]['options']);

        $this->assertNotNull($options->firstWhere('value', 'realtor::engaged_agent'));
        $this->assertNull($options->firstWhere('value', 'realtor::legacy_agent'));

        $this->assertEquals([
            'relationship_key' => 'realtor',
            'stage_key' => 'engaged_agent',
            'on_missing_relationship' => 'skipped',
        ], $authoring->buildDefinition(
            'change_relationship_stage',
            ['relationship_stage_target' => 'realtor::engaged_agent'],
            $context,
        ));
    }

    public function test_handler_changes_only_an_existing_active_relationship(): void
    {
        $contact = Contact::factory()->create();
        $relationship = app(UpsertContactRelationshipAction::class)->handle(
            contact: $contact,
            relationshipKey: 'realtor',
            stageKey: 'target_agent',
            source: 'import',
        );

        $result = app(ChangeRelationshipStageAutomationActionHandler::class)->handle(
            new AutomationActionContext(
                input: [
                    'relationship_key' => 'realtor',
                    'stage_key' => 'engaged_agent',
                ],
                models: ['current_contact' => $contact],
            ),
        );

        $this->assertSame(AutomationActionResult::STATUS_COMPLETED, $result->status);
        $this->assertSame('relationship_stage_changed', $result->reason);
        $this->assertSame('engaged_agent', $relationship->refresh()->stage_key);
        $this->assertSame('import', $relationship->source);
        $this->assertTrue($relationship->is_active);
    }

    public function test_handler_skips_instead_of_creating_or_reactivating_missing_relationship(): void
    {
        $contact = Contact::factory()->create();

        $missing = app(ChangeRelationshipStageAutomationActionHandler::class)->handle(
            new AutomationActionContext(
                input: [
                    'relationship_key' => 'realtor',
                    'stage_key' => 'engaged_agent',
                ],
                models: ['current_contact' => $contact],
            ),
        );

        $this->assertSame(AutomationActionResult::STATUS_SKIPPED, $missing->status);
        $this->assertDatabaseMissing('contact_relationships', [
            'contact_id' => $contact->getKey(),
            'relationship_key' => 'realtor',
        ]);

        $inactive = app(UpsertContactRelationshipAction::class)->handle(
            contact: $contact,
            relationshipKey: 'realtor',
            stageKey: 'target_agent',
            active: false,
        );

        $result = app(ChangeRelationshipStageAutomationActionHandler::class)->handle(
            new AutomationActionContext(
                input: [
                    'relationship_key' => 'realtor',
                    'stage_key' => 'engaged_agent',
                ],
                models: ['current_contact' => $contact],
            ),
        );

        $this->assertSame(AutomationActionResult::STATUS_SKIPPED, $result->status);
        $this->assertFalse($inactive->refresh()->is_active);
        $this->assertSame('target_agent', $inactive->stage_key);
    }

    public function test_point_validation_rejects_an_inactive_target_stage(): void
    {
        $context = new AutomationPointValidationContext(
            containerKey: 'realtor_reply',
            pointKey: 'advance_realtor',
            pointType: 'change_relationship_stage',
            path: 'flow_route.points.advance_realtor',
        );

        $findings = iterator_to_array(
            app(RelationshipStageAutomationPointDefinitionContributor::class)->validate(
                pointType: 'change_relationship_stage',
                definition: [
                    'relationship_key' => 'realtor',
                    'stage_key' => 'legacy_agent',
                ],
                settings: [],
                context: $context,
            ),
            false,
        );

        $this->assertCount(1, $findings);
        $this->assertSame('flow_routes.relationship_stage_inactive', $findings[0]->code);
    }
}