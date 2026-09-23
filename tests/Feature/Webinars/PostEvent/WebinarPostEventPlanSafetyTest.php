<?php

namespace Tests\Feature\Webinars\PostEvent;

use App\Modules\Messaging\Services\ScheduledMessageMetaCanonicalizer;
use App\Modules\Webinars\Actions\PostEvent\RunWebinarPostEventPlanAction;
use App\Modules\Webinars\Contracts\WebinarPostEventSendCondition;
use App\Modules\Webinars\Jobs\PostEvent\ProcessWebinarPostEventPlanRuleJob;
use App\Modules\Webinars\Messaging\WebinarPostEventReusableMessageTemplateAuthoringContributor;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Services\WebinarPostEventPlanService;
use App\Modules\Webinars\Services\WebinarPostEventSendConditionRegistry;
use App\Modules\Webinars\TokenContracts\WebinarTokenContextProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebinarPostEventPlanSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_configured_plan_stays_a_draft_until_enabled_in_admin(): void
    {
        Carbon::setTestNow('2026-09-23 12:00:00 UTC');
        Config::set('webinars.post_event_plans.va-homebuyer-game-plan', [
            'enabled' => true,
            'sms_enabled' => true,
            'email_enabled' => true,
            'sms_delay_minutes' => 10,
            'email_time' => '09:00',
            'templates' => [],
        ]);

        $series = WebinarSeries::factory()->create(['slug' => 'va-homebuyer-game-plan']);
        $occurrence = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
        ]);
        $plans = app(WebinarPostEventPlanService::class);

        $this->assertFalse($plans->forSeries($series)['enabled']);
        $this->assertNull($plans->activeFor($occurrence));
    }

    public function test_unconfigured_webinar_type_can_author_a_disabled_plan(): void
    {
        $series = WebinarSeries::factory()->create();
        $plans = app(WebinarPostEventPlanService::class);

        $this->assertFalse($plans->forSeries($series)['enabled']);
        $this->assertSame([], $plans->rules($plans->forSeries($series)));
    }

    public function test_new_webinar_follow_up_templates_use_the_plan_dispatch_context(): void
    {
        $options = iterator_to_array(app(WebinarPostEventReusableMessageTemplateAuthoringContributor::class)->options());

        $this->assertCount(2, $options);
        $this->assertSame('webinar_post_event_plan', $options[0]->context->dispatchKey);
        $this->assertSame('webinar_post_event_plan', $options[1]->context->dispatchKey);
    }

    public function test_activation_applies_to_future_occurrences_of_the_canonical_series_only(): void
    {
        Carbon::setTestNow('2026-09-23 12:00:00 UTC');
        Config::set('webinars.post_event_plans.va-homebuyer-game-plan', [
            'enabled' => false,
            'templates' => [],
        ]);

        $series = WebinarSeries::factory()->create([
            'slug' => 'va-homebuyer-game-plan',
            'meta' => ['post_event_plan' => [
                'enabled' => true,
                'activated_at' => now()->toIso8601String(),
            ]],
        ]);
        $older = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->subHours(3),
            'ends_at' => now()->subHours(2),
        ]);
        $upcoming = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
        ]);
        $plans = app(WebinarPostEventPlanService::class);

        $this->assertNull($plans->activeFor($older));
        $this->assertNotNull($plans->activeFor($upcoming));
    }

    public function test_scheduled_message_metadata_keeps_its_activation_guard(): void
    {
        $meta = app(ScheduledMessageMetaCanonicalizer::class)->forPersistence([
            'post_event' => [
                'type' => 'plan',
                'activation' => '2026-09-23T12:00:00+00:00',
                'template_key' => 'email.transactional.webinar.va_plan_missed_no_recording',
                'rule_key' => 'missed_fallback_email',
                'revision' => 2,
            ],
        ]);

        $this->assertSame('plan', data_get($meta, 'post_event.type'));
        $this->assertSame('2026-09-23T12:00:00+00:00', data_get($meta, 'post_event.activation'));
        $this->assertSame('missed_fallback_email', data_get($meta, 'post_event.rule_key'));
        $this->assertSame(2, data_get($meta, 'post_event.revision'));
    }

    public function test_plan_templates_have_a_distinct_authoring_context(): void
    {
        $contexts = collect(app(WebinarTokenContextProvider::class)->contexts())
            ->keyBy('key');

        $this->assertTrue($contexts->has('webinar_post_event_plan'));
        $this->assertContains(
            'webinar_playback_url',
            $contexts->get('webinar_post_event_plan')->sourceTokens,
        );
    }

    public function test_existing_client_settings_become_four_rules_with_alternate_email_templates(): void
    {
        $plan = [
            'sms_enabled' => true,
            'email_enabled' => true,
            'sms_delay_minutes' => 10,
            'email_time' => '09:00',
            'templates' => [
                'sms' => ['attended' => 'sms.attended', 'missed' => 'sms.missed'],
                'email' => [
                    'recording' => ['attended' => 'email.attended.replay', 'missed' => 'email.missed.replay'],
                    'fallback' => ['attended' => 'email.attended.fallback', 'missed' => 'email.missed.fallback'],
                ],
            ],
        ];
        $plans = app(WebinarPostEventPlanService::class);
        $rules = $plans->rules($plan);

        $this->assertCount(4, $rules);
        $this->assertSame('webinar.attendance_reconciled', $rules['attended_sms']['trigger']);
        $this->assertSame(10, $rules['missed_sms']['delay_value']);
        $this->assertSame('recording_exists', $rules['attended_email']['send_condition']);
        $this->assertSame('email.attended.fallback', $rules['attended_email']['alternate_template_key']);
        $this->assertSame('email.missed.fallback', $rules['missed_email']['alternate_template_key']);
        $this->assertSame('09:00', $rules['missed_email']['send_time']);
    }

    public function test_send_condition_is_extensible_and_failure_selects_one_alternate_or_skips(): void
    {
        $condition = new class implements WebinarPostEventSendCondition {
            public function key(): string { return 'custom.example'; }
            public function label(): string { return 'Custom condition'; }
            public function matches(WebinarRegistration $registration, string $activation, string $dueAt): bool
            {
                return false;
            }
        };

        $registry = new WebinarPostEventSendConditionRegistry([$condition]);
        $this->assertTrue($registry->has('custom.example'));
        $this->assertFalse($registry->matches('custom.example', WebinarRegistration::factory()->make(), 'activation', 'due'));

        $plans = app(WebinarPostEventPlanService::class);
        $rule = [
            'template_key' => 'email.primary',
            'alternate_template_key' => 'email.alternate',
            'on_condition_failure' => 'alternate',
        ];
        $this->assertSame('email.primary', $plans->selectedTemplateKey($rule, true));
        $this->assertSame('email.alternate', $plans->selectedTemplateKey($rule, false));
        $rule['on_condition_failure'] = 'skip';
        $this->assertNull($plans->selectedTemplateKey($rule, false));
    }

    public function test_day_based_message_timing_uses_client_local_clock(): void
    {
        Config::set('client.timezone', 'America/Chicago');
        $action = app(RunWebinarPostEventPlanAction::class);

        $due = $action->dueAt(Carbon::parse('2026-09-24 00:00:00 UTC'), [
            'delay_unit' => 'days', 'delay_value' => 1, 'send_time' => '09:00',
        ]);

        $this->assertSame('2026-09-24T14:00:00+00:00', $due->toIso8601String());
    }

    public function test_authoritative_attendance_queues_each_rule_once(): void
    {
        Carbon::setTestNow('2026-09-23 12:00:00 UTC');
        Queue::fake();
        Config::set('webinars.post_event_plans.va-homebuyer-game-plan', ['enabled' => false]);
        $series = WebinarSeries::factory()->create([
            'slug' => 'va-homebuyer-game-plan',
            'meta' => ['post_event_plan' => [
                'enabled' => true,
                'activated_at' => now()->subHours(2)->toIso8601String(),
                'rules' => ['text_follow_up' => [
                    'enabled' => true,
                    'channel' => 'sms',
                    'template_key' => 'sms.example',
                    'trigger' => 'webinar.attendance_reconciled',
                    'outcome' => 'attended',
                    'send_condition' => 'always',
                    'on_condition_failure' => 'skip',
                    'alternate_template_key' => null,
                    'delay_unit' => 'minutes',
                    'delay_value' => 10,
                    'send_time' => null,
                    'revision' => 1,
                ], 'recording_follow_up' => [
                    'enabled' => true,
                    'channel' => 'email',
                    'template_key' => 'email.example',
                    'trigger' => 'webinar.recording_completed',
                    'outcome' => 'any',
                    'send_condition' => 'recording_exists',
                    'on_condition_failure' => 'skip',
                    'alternate_template_key' => null,
                    'delay_unit' => 'minutes',
                    'delay_value' => 0,
                    'send_time' => null,
                    'revision' => 1,
                ]],
            ]],
        ]);
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'meta' => ['normalized' => ['post_event' => [
                'attendance_recorded_at' => now()->toIso8601String(),
            ]]],
        ]);
        WebinarRegistration::factory()->create(['webinar_id' => $webinar->getKey()]);
        $runner = app(RunWebinarPostEventPlanAction::class);

        $runner->execute($webinar, 'webinar.ended');
        $runner->execute($webinar, 'webinar.ended');

        Queue::assertPushed(ProcessWebinarPostEventPlanRuleJob::class, 1);

        $runner->execute($webinar, 'webinar.recording_completed');
        $runner->execute($webinar, 'webinar.recording_completed');

        Queue::assertPushed(ProcessWebinarPostEventPlanRuleJob::class, 2);
    }

    public function test_editing_a_draft_rule_keeps_activation_off_and_changes_its_revision(): void
    {
        Config::set('webinars.post_event_plans.va-homebuyer-game-plan', ['enabled' => false]);
        $series = WebinarSeries::factory()->create([
            'slug' => 'va-homebuyer-game-plan',
            'meta' => ['post_event_plan' => [
                'enabled' => false,
                'rules' => ['follow_up' => [
                    'enabled' => false,
                    'channel' => 'sms',
                    'template_key' => 'sms.example',
                    'trigger' => 'webinar.attendance_reconciled',
                    'outcome' => 'attended',
                    'send_condition' => 'always',
                    'on_condition_failure' => 'skip',
                    'alternate_template_key' => null,
                    'delay_unit' => 'minutes',
                    'delay_value' => 10,
                    'send_time' => null,
                    'revision' => 1,
                ]],
            ]],
        ]);
        $plans = app(WebinarPostEventPlanService::class);
        $saved = $plans->saveRule($series, 'follow_up', [
            'delay_unit' => 'days',
            'delay_value' => 2,
            'send_time' => '09:00',
        ]);
        $plan = $plans->forSeries($saved);

        $this->assertFalse($plan['enabled']);
        $this->assertSame(2, $plan['rules']['follow_up']['revision']);
        $this->assertSame(2, $plan['rules']['follow_up']['delay_value']);
    }
}