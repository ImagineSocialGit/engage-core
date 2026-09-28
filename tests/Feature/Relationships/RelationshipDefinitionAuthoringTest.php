<?php

namespace Tests\Feature\Relationships;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Relationships\Actions\UpsertContactRelationshipAction;
use App\Modules\Relationships\Import\Treatments\RelationshipStageImportTreatmentTarget;
use App\Modules\Relationships\Providers\RelationshipsModuleServiceProvider;
use App\Modules\Relationships\Services\RelationshipDefinitionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationshipDefinitionAuthoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['relationships']);
        config()->set('relationships.types', [
            'realtor' => [
                'singular' => 'Realtor',
                'plural' => 'Realtors',
                'visible' => true,
                'sort_order' => 10,
                'stages' => ['cold' => 'Cold'],
            ],
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);
        $this->app->register(RelationshipsModuleServiceProvider::class);
    }

    public function test_stage_authored_for_existing_config_type_is_available_to_contact_progression(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('crm.relationships.definitions.stages.store', 'realtor'), [
                'key' => 'follow_up',
                'label' => 'Follow Up',
                'sort_order' => 20,
                'active' => 1,
            ])
            ->assertRedirect(route('crm.relationships.definitions.index'));

        $definitions = app(RelationshipDefinitionRegistry::class)->get('realtor');
        $this->assertSame('Cold', $definitions['stages']['cold']['label']);
        $this->assertSame('Follow Up', $definitions['stages']['follow_up']['label']);
        $this->assertContains(
            'realtor::follow_up',
            array_column(app(RelationshipStageImportTreatmentTarget::class)->definition()->options, 'value'),
        );

        $contact = Contact::factory()->create();
        $relationship = app(UpsertContactRelationshipAction::class)->handle(
            contact: $contact,
            relationshipKey: 'realtor',
            stageKey: 'follow_up',
        );

        $this->assertSame('follow_up', $relationship->stage_key);

        $this->actingAs(User::factory()->create())
            ->get(route('crm.contacts.show', $contact))
            ->assertOk()
            ->assertViewHas('usesRelationshipStage', true)
            ->assertViewHas('showContactStatusProgression', false);
    }

    public function test_database_type_and_stage_remain_available_after_new_registry_resolution(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('crm.relationships.definitions.types.store'), [
            'key' => 'collaborator',
            'singular' => 'Collaborator',
            'plural' => 'Collaborators',
            'visible' => 1,
            'sort_order' => 15,
        ])->assertRedirect();

        $this->actingAs($user)->post(route('crm.relationships.definitions.stages.store', 'collaborator'), [
            'key' => 'partner',
            'label' => 'Partner',
            'sort_order' => 10,
            'active' => 1,
        ])->assertRedirect();

        $this->assertSame(
            'Partner',
            (new RelationshipDefinitionRegistry())->get('collaborator')['stages']['partner']['label'],
        );

        $this->actingAs($user)->post(route('crm.relationships.definitions.types.store'), [
            'key' => 'realtor',
            'singular' => 'Duplicate',
            'plural' => 'Duplicates',
            'visible' => 1,
            'sort_order' => 0,
        ])->assertSessionHasErrors('key');
    }

    public function test_editing_existing_stage_keeps_its_key_and_can_disable_new_import_destinations(): void
    {
        $this->actingAs(User::factory()->create())
            ->put(route('crm.relationships.definitions.stages.update', ['realtor', 'cold']), [
                'label' => 'Not Taking New Contacts',
                'sort_order' => 90,
                'active' => 0,
            ])->assertRedirect();

        $stage = app(RelationshipDefinitionRegistry::class)->get('realtor')['stages']['cold'];
        $this->assertSame('cold', $stage['key']);
        $this->assertFalse($stage['active']);
        $this->assertNotContains(
            'realtor::cold',
            array_column(app(RelationshipStageImportTreatmentTarget::class)->definition()->options, 'value'),
        );
    }
}