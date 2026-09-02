<?php

namespace Tests\Feature\FlowRoutes;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Campaigns\Automation\CampaignAnnualTouchAutomationTriggerAuthoringContributor;
use App\Modules\Campaigns\Jobs\EmitDueAnnualTouchAutomationEventsJob;
use App\Modules\Core\Automation\CoreAutomationTriggerAuthoringContributor;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Modules\InboundMessaging\Automation\InboundReplyAutomationTriggerAuthoringContributor;
use App\Modules\InboundMessaging\Models\InboundReplyIntent;
use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Support\AutomationTriggers\AutomationTriggerAuthoringRegistry;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRouteTriggerAuthoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_exposes_only_currently_available_module_contributed_triggers(): void
    {
        ContactStatus::query()->create([
            'key' => 'lead',
            'name' => 'Lead',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $keys = app(AutomationTriggerAuthoringRegistry::class)->availableKeys();

        $this->assertContains(CoreAutomationTriggerAuthoringContributor::CONTACT_STATUS, $keys);
        $this->assertContains(CoreAutomationTriggerAuthoringContributor::CONTACT_CREATED, $keys);
        $this->assertContains(CampaignAnnualTouchAutomationTriggerAuthoringContributor::KEY, $keys);
        $this->assertNotContains(InboundReplyAutomationTriggerAuthoringContributor::KEY, $keys);
    }

    public function test_important_date_route_uses_registered_contact_date_and_daily_event_is_idempotent(): void
    {
        config()->set('client.timezone', 'UTC');
        Carbon::setTestNow('2026-08-31 00:05:00 UTC');

        $contact = Contact::query()->create([
            'first_name' => 'Jamie',
            'email' => 'jamie@example.test',
            'birthday' => '2021-08-31',
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(
            'http://crm.'.config('app.root_domain').'/flow-routes',
            [
                'name' => 'Birthday follow-up',
                'trigger_authoring_key' => CampaignAnnualTouchAutomationTriggerAuthoringContributor::KEY,
                'annual_date_source_key' => 'birthday',
            ],
        );

        $route = FlowRoute::query()->sole();

        $response->assertRedirect(route('crm.flow-routes.index', [
            'edit_route' => $route->getKey(),
        ]));
        $this->assertSame('campaign_touch.annual_date_due', $route->trigger_key);
        $this->assertSame(
            'core.contact.birthday',
            data_get($route->meta, 'definition.entry_conditions.0.value'),
        );

        $job = new EmitDueAnnualTouchAutomationEventsJob('2026-08-31');
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $event = AutomationEventOutboxEvent::query()
            ->where('event_key', 'campaign_touch.annual_date_due')
            ->sole();

        $this->assertSame($contact->getKey(), $event->contact_id);
        $this->assertSame('core.contact.birthday', data_get($event->payload, 'annual_date.source_key'));
        $this->assertSame(5, data_get($event->payload, 'annual_date.occurrence_number'));
        $this->assertSame('5th', data_get($event->payload, 'annual_date.occurrence_ordinal'));

        Carbon::setTestNow();
    }

    public function test_reply_outcome_route_is_authored_with_runtime_event_conditions_and_stays_unassigned(): void
    {
        $profile = InboundReplyProfile::query()->create([
            'key' => 'cold_lead_nurture',
            'label' => 'Cold lead nurture replies',
            'description' => null,
            'is_active' => true,
            'source' => 'test',
            'is_customized' => false,
        ]);
        InboundReplyIntent::query()->create([
            'inbound_reply_profile_id' => $profile->getKey(),
            'key' => 'high_intent',
            'label' => 'High Intent',
            'description' => null,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/flow-routes?create=1')
            ->assertOk()
            ->assertViewHas('createRouteTriggers', function (array $triggers): bool {
                $replyTrigger = collect($triggers)->firstWhere(
                    'key',
                    InboundReplyAutomationTriggerAuthoringContributor::KEY,
                );

                if (! is_array($replyTrigger)) {
                    return false;
                }

                $replyOutcomeField = collect($replyTrigger['fields'] ?? [])
                    ->firstWhere('name', 'reply_outcome');

                return is_array($replyOutcomeField)
                    && collect($replyOutcomeField['options'] ?? [])
                        ->contains(fn (mixed $option): bool => is_array($option)
                            && ($option['value'] ?? null) === 'cold_lead_nurture|high_intent');
            })
            ->assertSee('data-flow-route-create-trigger', false);

        $response = $this->actingAs($user)->post(
            'http://crm.'.config('app.root_domain').'/flow-routes',
            [
                'name' => 'Cold lead hand raiser',
                'trigger_authoring_key' => InboundReplyAutomationTriggerAuthoringContributor::KEY,
                'reply_outcome' => 'cold_lead_nurture|high_intent',
            ],
        );

        $route = FlowRoute::query()->sole();

        $response->assertRedirect(route('crm.flow-routes.index', [
            'edit_route' => $route->getKey(),
        ]));
        $this->assertSame(FlowRoute::TRIGGER_AUTOMATION_EVENT, $route->trigger_type);
        $this->assertSame('inbound_message.normal_reply', $route->trigger_key);
        $this->assertNull($route->contact_status_id);
        $this->assertSame(
            'cold_lead_nurture',
            data_get($route->meta, 'definition.entry_conditions.0.value'),
        );
        $this->assertSame(
            'high_intent',
            data_get($route->meta, 'definition.entry_conditions.1.value'),
        );
        $this->assertSame(0, FlowRouteTriggerBinding::query()->count());
    }

    public function test_hidden_trigger_fields_are_disabled_and_only_active_fields_are_required(): void
    {
        ContactStatus::query()->create([
            'key' => 'lead',
            'name' => 'Lead',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs(User::factory()->create())
            ->get('http://crm.'.config('app.root_domain').'/flow-routes?create=1')
            ->assertOk()
            ->assertSee('x-model="createTriggerValues.contact_status_id"', false)
            ->assertSee('x-bind:disabled="createTriggerKey !==', false)
            ->assertSee('x-bind:required="createTriggerKey ===', false)
            ->assertDontSee('createTriggerValues[', false);
    }
}