<?php

namespace Tests\Feature\Relationships;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Relationships\Actions\UpsertContactRelationshipAction;
use App\Modules\Relationships\Providers\RelationshipsModuleServiceProvider;
use App\Modules\Relationships\Services\ContactShow\ContactRelationshipsShowDataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactRelationshipWorkspaceTest extends TestCase
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
                'stages' => [],
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
                    'strategic_partner' => [
                        'label' => 'Strategic Partner',
                        'sort_order' => 20,
                        'active' => true,
                    ],
                    'inactive_agent' => [
                        'label' => 'Inactive Agent',
                        'sort_order' => 90,
                        'active' => false,
                    ],
                ],
            ],
        ]);
        config()->set('relationships.default_relationship', 'consumer');
        config()->set('modules.enabled', [
            'relationships',
            'workflow',
            'tasks',
        ]);

        $this->app->register(RelationshipsModuleServiceProvider::class);
    }

    public function test_realtor_context_uses_relationship_stage_instead_of_contact_status(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'meta' => [
                'import' => [
                    'original_status' => 'Legacy consumer status',
                ],
            ],
        ]);

        app(UpsertContactRelationshipAction::class)->handle(
            contact: $contact,
            relationshipKey: 'realtor',
            stageKey: 'target_agent',
            source: 'agent_list',
        );

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get(route('crm.contacts.show', $contact))
            ->assertOk()
            ->assertSee('data-contact-business-context="realtor"', false)
            ->assertSee('data-contact-progression="relationship_stage"', false)
            ->assertSee('data-relationship-stage-form', false)
            ->assertDontSee('data-contact-status-form', false)
            ->assertDontSee('data-contact-status-import-evidence', false);
    }

    public function test_stage_update_uses_relationship_owned_action_for_configured_lifecycle_stages(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $relationship = app(UpsertContactRelationshipAction::class)->handle(
            contact: $contact,
            relationshipKey: 'realtor',
            stageKey: 'target_agent',
        );

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->patch(route('crm.contacts.relationships.stage.update', [
                $contact,
                $relationship,
            ]), [
                'stage_key' => 'strategic_partner',
            ])
            ->assertRedirect(route('crm.contacts.show', $contact));

        $this->assertSame(
            'strategic_partner',
            $relationship->refresh()->stage_key,
        );

        $this->actingAs($user)
            ->from(route('crm.contacts.show', $contact))
            ->patch(route('crm.contacts.relationships.stage.update', [
                $contact,
                $relationship,
            ]), [
                'stage_key' => 'inactive_agent',
            ])
            ->assertRedirect(route('crm.contacts.show', $contact));

        $this->assertSame(
            'inactive_agent',
            $relationship->refresh()->stage_key,
        );

        $this->actingAs($user)
            ->from(route('crm.contacts.show', $contact))
            ->patch(route('crm.contacts.relationships.stage.update', [
                $contact,
                $relationship,
            ]), [
                'stage_key' => 'not_configured',
            ])
            ->assertSessionHasErrors('stage_key');

        $this->assertSame(
            'inactive_agent',
            $relationship->refresh()->stage_key,
        );
    }

    public function test_default_business_context_prefers_consumer_and_does_not_force_realtor_stage_progression(): void
    {
        $contact = Contact::factory()->create();
        $upsert = app(UpsertContactRelationshipAction::class);

        $upsert->handle(
            contact: $contact,
            relationshipKey: 'consumer',
            stageKey: null,
        );
        $upsert->handle(
            contact: $contact,
            relationshipKey: 'realtor',
            stageKey: 'target_agent',
        );

        $data = app(ContactRelationshipsShowDataProvider::class)->dataFor($contact);

        $this->assertSame(
            'consumer',
            data_get($data, 'contactBusinessContext.primary.key'),
        );
        $this->assertSame(
            'contact_status',
            data_get($data, 'contactBusinessContext.primary.progression_mode'),
        );
        $this->assertCount(
            2,
            data_get($data, 'contactBusinessContext.relationships'),
        );
    }
}