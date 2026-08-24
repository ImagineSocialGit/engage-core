<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Events\ContactFilterFactsChanged;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ContactFilterFactsChangedOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_source_and_subsource_changes_emit_transient_filter_fact_event_without_automation_ledger_history(): void
    {
        $contact = Contact::withoutEvents(fn () => Contact::factory()->create([
            'source' => 'original',
            'subsource' => 'original-subsource',
        ]));

        Event::fake([ContactFilterFactsChanged::class]);

        $contact->forceFill([
            'source' => 'website',
            'subsource' => 'landing-page',
        ])->save();

        Event::assertDispatched(
            ContactFilterFactsChanged::class,
            function (ContactFilterFactsChanged $event) use ($contact): bool {
                return $event->contactId === (int) $contact->getKey()
                    && $event->criterionKeys === ['source', 'subsource']
                    && data_get($event->changes, 'source.from') === 'original'
                    && data_get($event->changes, 'source.to') === 'website';
            },
        );

        $this->assertSame(0, AutomationEventOutboxEvent::query()->count());
    }

    public function test_contact_tag_create_and_delete_emit_transient_tag_fact_events_without_automation_ledger_history(): void
    {
        $contact = Contact::withoutEvents(fn () => Contact::factory()->create());

        Event::fake([ContactFilterFactsChanged::class]);

        $tag = ContactTag::query()->create([
            'contact_id' => $contact->getKey(),
            'tag' => 'VIP',
        ]);

        $tag->delete();

        Event::assertDispatchedTimes(ContactFilterFactsChanged::class, 2);

        $events = Event::dispatched(ContactFilterFactsChanged::class);

        $this->assertEquals(
            ['VIP'],
            data_get($events->first()[0]->changes, 'added'),
        );
        $this->assertEquals(
            ['VIP'],
            data_get($events->last()[0]->changes, 'removed'),
        );
        $this->assertSame(0, AutomationEventOutboxEvent::query()->count());
    }
}