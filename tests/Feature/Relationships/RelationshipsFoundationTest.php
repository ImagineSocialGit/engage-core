<?php

namespace Tests\Feature\Relationships;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\SiteSetting;
use App\Modules\Relationships\Actions\UpsertContactRelationshipAction;
use App\Modules\Relationships\Models\ContactRelationship;
use App\Modules\Relationships\Providers\RelationshipsModuleServiceProvider;
use App\Modules\Relationships\Services\RelationshipDefinitionRegistry;
use App\Modules\Relationships\Services\RelationshipWorkspaceResolver;
use App\Modules\Relationships\Validation\RelationshipsSetupValidationContributor;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class RelationshipsFoundationTest extends TestCase
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
                    'nurture' => [
                        'label' => 'Nurture',
                        'sort_order' => 20,
                        'active' => true,
                    ],
                    'past_client' => [
                        'label' => 'Past Client',
                        'sort_order' => 90,
                        'active' => true,
                    ],
                ],
            ],
            'realtor' => [
                'singular' => 'Realtor',
                'plural' => 'Realtors',
                'visible' => true,
                'sort_order' => 20,
                'stages' => [
                    'target_agent' => 'Target Agent',
                    'strategic_partner' => 'Strategic Partner',
                ],
            ],
        ]);
        config()->set('relationships.default_relationship', 'consumer');
    }

    public function test_relationships_is_a_known_optional_universal_module_and_mortgage_depends_on_it(): void
    {
        $modules = app(ModuleManager::class);

        $this->assertTrue($modules->known('relationships'));
        $this->assertFalse($modules->enabled('relationships'));
        $this->assertEquals(['core'], $modules->dependencies('relationships'));
        $this->assertContains(
            RelationshipsModuleServiceProvider::class,
            $modules->providers('relationships'),
        );
        $this->assertEquals(['relationships'], $modules->dependencies('mortgage'));
    }

    public function test_one_contact_can_hold_multiple_independent_business_relationships(): void
    {
        $contact = Contact::factory()->create();
        $action = app(UpsertContactRelationshipAction::class);

        $consumer = $action->handle(
            contact: $contact,
            relationshipKey: 'consumer',
            stageKey: 'past_client',
            source: 'database',
        );

        $realtor = $action->handle(
            contact: $contact,
            relationshipKey: 'realtor',
            stageKey: 'strategic_partner',
            source: 'realtor_referral',
        );

        $this->assertNotSame($consumer->id, $realtor->id);
        $this->assertSame(2, ContactRelationship::query()
            ->where('contact_id', $contact->id)
            ->count());
        $this->assertDatabaseHas('contact_relationships', [
            'contact_id' => $contact->id,
            'relationship_key' => 'consumer',
            'stage_key' => 'past_client',
            'source' => 'database',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('contact_relationships', [
            'contact_id' => $contact->id,
            'relationship_key' => 'realtor',
            'stage_key' => 'strategic_partner',
            'source' => 'realtor_referral',
            'is_active' => true,
        ]);
    }

    public function test_upsert_updates_the_same_relationship_and_tracks_inactive_state(): void
    {
        $contact = Contact::factory()->create();
        $action = app(UpsertContactRelationshipAction::class);

        $first = $action->handle(
            contact: $contact,
            relationshipKey: 'realtor',
            stageKey: 'target_agent',
        );
        $startedAt = $first->started_at;

        $updated = $action->handle(
            contact: $contact,
            relationshipKey: 'realtor',
            stageKey: 'strategic_partner',
            active: false,
        );

        $this->assertSame($first->id, $updated->id);
        $this->assertSame('strategic_partner', $updated->stage_key);
        $this->assertFalse($updated->is_active);
        $this->assertNotNull($updated->ended_at);
        $this->assertTrue($updated->started_at?->equalTo($startedAt) ?? false);
        $this->assertSame(1, ContactRelationship::query()->count());
    }

    public function test_unknown_relationship_stage_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Unknown stage [not_a_stage] for Contact relationship [consumer].',
        );

        app(UpsertContactRelationshipAction::class)->handle(
            contact: Contact::factory()->create(),
            relationshipKey: 'consumer',
            stageKey: 'not_a_stage',
        );
    }

    public function test_workspace_default_prefers_valid_site_setting_then_config_fallback(): void
    {
        $resolver = app(RelationshipWorkspaceResolver::class);

        $this->assertSame('consumer', $resolver->defaultRelationshipKey());
        $this->assertEquals(['consumer', 'realtor'], array_keys($resolver->workspaces()));

        SiteSetting::query()->updateOrCreate(
            ['key' => 'crm.contacts.default_relationship'],
            ['value' => 'realtor'],
        );

        $this->assertSame('realtor', $resolver->defaultRelationshipKey());
    }

    public function test_setup_validation_rejects_stale_stored_default_relationship(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['key' => 'crm.contacts.default_relationship'],
            ['value' => 'vendor'],
        );

        $findings = iterator_to_array(
            app(RelationshipsSetupValidationContributor::class)->findings(),
        );

        $this->assertCount(1, $findings);
        $this->assertSame(
            'relationships.stored_default_relationship_unknown',
            $findings[0]->code,
        );
    }

    public function test_definition_registry_rejects_non_snake_case_keys(): void
    {
        config()->set('relationships.types', [
            'Realtor Partner' => [
                'singular' => 'Realtor',
                'plural' => 'Realtors',
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Relationship type keys must use lowercase snake_case.',
        );

        app(RelationshipDefinitionRegistry::class)->all();
    }
    public function test_definition_registry_rejects_unknown_fields(): void
    {
        config()->set('relationships.types', [
            'consumer' => [
                'singular' => 'Lead',
                'plural' => 'Leads',
                'invented_setting' => true,
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'relationships.types.consumer] contains unknown field(s): invented_setting.',
        );

        app(RelationshipDefinitionRegistry::class)->all();
    }

}