<?php

namespace Tests\Feature\Relationships;

use App\Modules\Core\Events\ContactFilterFactsChanged;
use App\Modules\Core\Models\Contact;
use App\Modules\Relationships\Actions\ChangeContactRelationshipStageAction;
use App\Modules\Relationships\Actions\UpsertContactRelationshipAction;
use App\Modules\Relationships\Providers\RelationshipsModuleServiceProvider;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RelationshipEligibilityFactOutboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('relationships.types', [
            'realtor' => [
                'singular' => 'Realtor',
                'plural' => 'Realtors',
                'visible' => true,
                'sort_order' => 10,
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
                ],
            ],
        ]);

        $this->app->register(RelationshipsModuleServiceProvider::class);
    }

    public function test_relationship_create_and_stage_change_emit_transient_relationship_fact_events_without_automation_ledger_history(): void
    {
        $contact = Contact::withoutEvents(fn () => Contact::factory()->create());

        Event::fake([ContactFilterFactsChanged::class]);

        app(UpsertContactRelationshipAction::class)->handle(
            contact: $contact,
            relationshipKey: 'realtor',
            stageKey: 'target_agent',
        );

        app(ChangeContactRelationshipStageAction::class)->handle(
            contact: $contact,
            relationshipKey: 'realtor',
            stageKey: 'engaged_agent',
        );

        Event::assertDispatchedTimes(ContactFilterFactsChanged::class, 2);

        $events = Event::dispatched(ContactFilterFactsChanged::class);

        $this->assertEquals(
            ['relationship'],
            $events->first()[0]->criterionKeys,
        );
        $this->assertSame(
            'relationships',
            data_get($events->first()[0]->meta, 'source_module'),
        );
        $this->assertSame(
            'target_agent',
            data_get($events->last()[0]->changes, 'stage_key.from'),
        );
        $this->assertSame(
            'engaged_agent',
            data_get($events->last()[0]->changes, 'stage_key.to'),
        );
        $this->assertSame(0, AutomationEventOutboxEvent::query()->count());
    }
}