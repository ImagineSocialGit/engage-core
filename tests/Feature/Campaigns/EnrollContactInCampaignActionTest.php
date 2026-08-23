<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\EnrollContactInCampaignAction;
use App\Modules\Campaigns\Exceptions\CampaignUnavailableForEnrollmentException;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Jobs\ProcessMessageChainEnrollmentJob;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Services\MessageChainExecutionContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EnrollContactInCampaignActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_starts_the_selected_message_chain_and_preserves_campaign_attribution(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-18 12:00:00', 'America/Chicago'));

        [$campaign, $chain, $version] = $this->campaignWithChain('cold_lead_nurture');
        $contact = Contact::factory()->create(['email' => 'lead@example.com']);
        $source = Contact::factory()->create(['email' => 'source@example.com']);

        $enrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
            source: $source,
            payload: [
                'offer_code' => 'VA-2026',
                'runtime_context' => ['event_key' => 'lead.imported'],
            ],
            meta: ['source' => 'test'],
            startContext: [
                'market' => 'tampa',
                'payload' => ['existing' => 'kept'],
            ],
        );

        $chainEnrollment = $enrollment->messageChainEnrollment;

        $this->assertInstanceOf(MessageChainEnrollment::class, $chainEnrollment);
        $this->assertSame((int) $chainEnrollment->getKey(), (int) $enrollment->message_chain_enrollment_id);
        $this->assertSame((int) $version->getKey(), (int) $chainEnrollment->message_chain_version_id);
        $this->assertSame(MessageChainEnrollment::STATUS_ACTIVE, $chainEnrollment->status);
        $this->assertSame($contact->getMorphClass(), $chainEnrollment->recipient_type);
        $this->assertSame((int) $contact->getKey(), (int) $chainEnrollment->recipient_id);
        $this->assertSame($enrollment->getMorphClass(), $chainEnrollment->context_type);
        $this->assertSame((int) $enrollment->getKey(), (int) $chainEnrollment->context_id);
        $this->assertSame($campaign->getMorphClass(), $chainEnrollment->origin_type);
        $this->assertSame((int) $campaign->getKey(), (int) $chainEnrollment->origin_id);
        $this->assertSame(EnrollContactInCampaignAction::SURFACE, $chainEnrollment->surface);
        $this->assertSame(
            "campaign:{$campaign->getKey()}:enrollment:{$enrollment->getKey()}",
            $enrollment->dedupe_key,
        );
        $this->assertSame($enrollment->dedupe_key, $chainEnrollment->dedupe_key);
        $this->assertSame($source->getMorphClass(), $enrollment->source_type);
        $this->assertSame((int) $source->getKey(), (int) $enrollment->source_id);
        $this->assertDatabaseCount('scheduled_messages', 0);

        $expectedNextActionAt = $chainEnrollment->started_at?->copy()->addHours(2);
        $this->assertNotNull($expectedNextActionAt);
        $this->assertTrue(
            $chainEnrollment->next_action_at?->equalTo($expectedNextActionAt) ?? false,
        );

        $context = app(MessageChainExecutionContextResolver::class)->resolve(
            $chainEnrollment->fresh(),
        );

        $this->assertSame('tampa', data_get($context, 'market'));
        $this->assertSame('kept', data_get($context, 'payload.existing'));
        $this->assertSame('kept', data_get($context, 'existing'));
        $this->assertSame('VA-2026', data_get($context, 'offer_code'));
        $this->assertSame('lead.imported', data_get($context, 'runtime_context.event_key'));
        $this->assertSame($campaign->key, data_get($context, 'campaign.key'));
        $this->assertSame($enrollment->getKey(), data_get($context, 'campaign_enrollment.id'));
        $this->assertSame($contact->getKey(), data_get($context, 'contact.id'));
        $this->assertSame($chain->getKey(), $campaign->message_chain_id);
    }

    public function test_campaign_key_is_authoritative_even_when_legacy_message_segments_do_not_match(): void
    {
        Queue::fake();

        [$campaign] = $this->campaignWithChain('canonical_identity');
        $contact = Contact::factory()->create();

        $enrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
            channel: 'sms',
            purpose: 'transactional',
            scope: 'some_other_scope',
            eagerProcess: false,
        );

        $this->assertSame((int) $campaign->getKey(), (int) $enrollment->campaign_id);
        $this->assertSame('canonical_identity', $enrollment->campaign_key);
        $this->assertDatabaseCount('campaign_enrollments', 1);
    }

    public function test_non_empty_enrollment_exit_conditions_are_rejected_instead_of_silently_ignored(): void
    {
        Queue::fake();

        [$campaign] = $this->campaignWithChain('legacy_exit_conditions');
        $contact = Contact::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Configure exit conditions on the selected MessageChainVersion');

        app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
            exitConditions: [
                ['field' => 'contact.status', 'operator' => 'equals', 'value' => 'engaged'],
            ],
        );
    }

    public function test_it_returns_existing_open_message_chain_enrollment_without_duplicate_start(): void
    {
        Queue::fake();

        [$campaign] = $this->campaignWithChain('existing_open');
        $contact = Contact::factory()->create();

        $first = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
        );

        $first->messageChainEnrollment->forceFill([
            'status' => MessageChainEnrollment::STATUS_PAUSED,
            'paused_at' => now(),
        ])->save();

        $second = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
        );

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('campaign_enrollments', 1);
        $this->assertDatabaseCount('message_chain_enrollments', 1);
    }

    public function test_terminal_message_chain_enrollment_allows_a_new_campaign_enrollment(): void
    {
        Queue::fake();

        [$campaign] = $this->campaignWithChain('reenrollment');
        $contact = Contact::factory()->create();

        $first = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
        );

        $first->messageChainEnrollment->forceFill([
            'status' => MessageChainEnrollment::STATUS_COMPLETED,
            'current_message_chain_step_id' => null,
            'next_action_at' => null,
            'completed_at' => now(),
        ])->save();

        $second = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
        );

        $this->assertFalse($first->is($second));
        $this->assertNotSame($first->dedupe_key, $second->dedupe_key);
        $this->assertDatabaseCount('campaign_enrollments', 2);
        $this->assertDatabaseCount('message_chain_enrollments', 2);
    }


    public function test_higher_priority_campaign_supersedes_open_lower_priority_family_enrollment(): void
    {
        Queue::fake();

        [$lower] = $this->campaignWithChain(
            key: 'family_lower',
            familyKey: 'consumer_nurture',
            priority: 10,
        );
        [$higher] = $this->campaignWithChain(
            key: 'family_higher',
            familyKey: 'consumer_nurture',
            priority: 20,
        );
        $contact = Contact::factory()->create();

        $lowerEnrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $lower->key,
        );

        $higherEnrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $higher->key,
        );

        $this->assertSame(
            MessageChainEnrollment::STATUS_CANCELLED,
            $lowerEnrollment->messageChainEnrollment->refresh()->status,
        );
        $this->assertSame(
            MessageChainEnrollment::STATUS_ACTIVE,
            $higherEnrollment->messageChainEnrollment->status,
        );
        $this->assertSame(
            'campaign_family_superseded',
            data_get($lowerEnrollment->refresh()->meta, 'lifecycle.last_cancellation.reason'),
        );
        $this->assertSame(
            'consumer_nurture',
            data_get($higherEnrollment->meta, 'campaign_family.family_key'),
        );
        $this->assertSame(
            'family_lower',
            data_get($higherEnrollment->meta, 'campaign_family.superseded.0.campaign_key'),
        );
        $this->assertDatabaseCount('campaign_enrollments', 2);
    }

    public function test_lower_priority_campaign_is_blocked_by_open_higher_priority_family_enrollment(): void
    {
        Queue::fake();

        [$higher] = $this->campaignWithChain(
            key: 'family_blocker',
            familyKey: 'consumer_nurture',
            priority: 50,
        );
        [$lower] = $this->campaignWithChain(
            key: 'family_candidate',
            familyKey: 'consumer_nurture',
            priority: 10,
        );
        $contact = Contact::factory()->create();

        $incumbent = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $higher->key,
        );

        try {
            app(EnrollContactInCampaignAction::class)->handle(
                contact: $contact,
                campaignKey: $lower->key,
            );
            $this->fail('Expected lower-priority family enrollment to be blocked.');
        } catch (CampaignUnavailableForEnrollmentException $exception) {
            $this->assertSame(
                CampaignUnavailableForEnrollmentException::REASON_FAMILY_BLOCKED,
                $exception->reason,
            );
            $this->assertSame('consumer_nurture', $exception->familyKey);
            $this->assertSame(10, $exception->campaignPriority);
            $this->assertSame($higher->key, $exception->blockingCampaignKey);
            $this->assertSame(50, $exception->blockingPriority);
            $this->assertSame((int) $incumbent->getKey(), $exception->blockingEnrollmentId);
        }

        $this->assertSame(
            MessageChainEnrollment::STATUS_ACTIVE,
            $incumbent->messageChainEnrollment->refresh()->status,
        );
        $this->assertDatabaseCount('campaign_enrollments', 1);
    }

    public function test_equal_priority_family_campaign_keeps_the_existing_incumbent(): void
    {
        Queue::fake();

        [$first] = $this->campaignWithChain(
            key: 'family_equal_first',
            familyKey: 'realtor_nurture',
            priority: 20,
        );
        [$second] = $this->campaignWithChain(
            key: 'family_equal_second',
            familyKey: 'realtor_nurture',
            priority: 20,
        );
        $contact = Contact::factory()->create();

        app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $first->key,
        );

        $this->expectException(CampaignUnavailableForEnrollmentException::class);
        $this->expectExceptionMessage('is not lower than candidate priority');

        try {
            app(EnrollContactInCampaignAction::class)->handle(
                contact: $contact,
                campaignKey: $second->key,
            );
        } finally {
            $this->assertDatabaseCount('campaign_enrollments', 1);
        }
    }

    public function test_campaigns_without_a_family_remain_independently_enrollable(): void
    {
        Queue::fake();

        [$first] = $this->campaignWithChain('independent_one');
        [$second] = $this->campaignWithChain('independent_two');
        $contact = Contact::factory()->create();

        app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $first->key,
        );
        app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $second->key,
        );

        $this->assertDatabaseCount('campaign_enrollments', 2);
        $this->assertDatabaseCount('message_chain_enrollments', 2);
    }

    public function test_inactive_campaign_is_rejected_before_chain_start(): void
    {
        Queue::fake();

        [$campaign] = $this->campaignWithChain(
            key: 'inactive_campaign',
            campaignStatus: Campaign::STATUS_INACTIVE,
        );

        try {
            app(EnrollContactInCampaignAction::class)->handle(
                contact: Contact::factory()->create(),
                campaignKey: $campaign->key,
            );
            $this->fail('Expected inactive Campaign enrollment to be rejected.');
        } catch (CampaignUnavailableForEnrollmentException $exception) {
            $this->assertSame(
                CampaignUnavailableForEnrollmentException::REASON_INACTIVE,
                $exception->reason,
            );
        }

        $this->assertDatabaseCount('campaign_enrollments', 0);
        $this->assertDatabaseCount('message_chain_enrollments', 0);
    }

    public function test_active_campaign_with_inactive_selected_chain_rolls_back_wrapper_creation(): void
    {
        Queue::fake();

        [$campaign] = $this->campaignWithChain(
            key: 'inactive_selected_chain',
            chainStatus: MessageChain::STATUS_INACTIVE,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not active');

        try {
            app(EnrollContactInCampaignAction::class)->handle(
                contact: Contact::factory()->create(),
                campaignKey: $campaign->key,
            );
        } finally {
            $this->assertDatabaseCount('campaign_enrollments', 0);
            $this->assertDatabaseCount('message_chain_enrollments', 0);
        }
    }

    public function test_active_campaign_without_selected_chain_rolls_back_wrapper_creation(): void
    {
        Queue::fake();

        $campaign = Campaign::query()->create([
            'key' => 'unbound_campaign',
            'name' => 'Unbound Campaign',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign_test',
            'status' => Campaign::STATUS_ACTIVE,
            'meta' => [],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has no selected MessageChain');

        try {
            app(EnrollContactInCampaignAction::class)->handle(
                contact: Contact::factory()->create(),
                campaignKey: $campaign->key,
            );
        } finally {
            $this->assertDatabaseCount('campaign_enrollments', 0);
            $this->assertDatabaseCount('message_chain_enrollments', 0);
        }
    }

    public function test_chain_with_no_active_step_completes_in_messaging_without_duplicate_campaign_progression_state(): void
    {
        Queue::fake();

        [$campaign] = $this->campaignWithChain(
            key: 'no_active_steps',
            stepActive: false,
        );

        $enrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: Contact::factory()->create(),
            campaignKey: $campaign->key,
        );

        $this->assertSame(MessageChainEnrollment::STATUS_COMPLETED, $enrollment->messageChainEnrollment->status);
        $this->assertSame(MessageChainEnrollment::STATUS_COMPLETED, $enrollment->runtimeStatus());
        $this->assertDatabaseMissing('campaign_enrollments', [
            'id' => $enrollment->getKey(),
            'message_chain_enrollment_id' => null,
        ]);
    }

    public function test_unlinked_wrapper_does_not_claim_runtime_openness(): void
    {
        Queue::fake();

        [$campaign] = $this->campaignWithChain('atomic_bridge_guard');
        $contact = Contact::factory()->create();

        $unlinked = CampaignEnrollment::query()->create([
            'contact_id' => $contact->getKey(),
            'campaign_id' => $campaign->getKey(),
            'campaign_key' => $campaign->key,
            'started_at' => now(),
        ]);

        $linked = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
        );

        $this->assertNull($unlinked->runtimeStatus());
        $this->assertFalse($unlinked->isOpen());
        $this->assertNotNull($linked->message_chain_enrollment_id);
        $this->assertTrue($linked->isOpen());
        $this->assertDatabaseCount('campaign_enrollments', 2);
        $this->assertDatabaseCount('message_chain_enrollments', 1);
    }

    /**
     * @return array{0: Campaign, 1: MessageChain, 2: \App\Modules\Messaging\Models\MessageChainVersion}
     */
    public function test_explicit_entry_key_is_idempotent_after_terminal_completion(): void
    {
        Queue::fake();

        [$campaign] = $this->campaignWithChain('stable_entry');
        $contact = Contact::factory()->create();

        $first = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
            entryKey: 'contact_import:stacey_cold_leads:cold_lead_nurture',
            eagerProcess: false,
        );

        $first->messageChainEnrollment->forceFill([
            'status' => MessageChainEnrollment::STATUS_COMPLETED,
            'current_message_chain_step_id' => null,
            'next_action_at' => null,
            'completed_at' => now(),
        ])->save();

        $second = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
            entryKey: 'contact_import:stacey_cold_leads:cold_lead_nurture',
            eagerProcess: false,
        );

        $this->assertTrue($first->is($second));
        $this->assertStringStartsWith('campaign_entry:', (string) $first->dedupe_key);
        $this->assertSame(
            'contact_import:stacey_cold_leads:cold_lead_nurture',
            data_get($first->start_context, 'entry_key'),
        );
        $this->assertDatabaseCount('campaign_enrollments', 1);
        $this->assertDatabaseCount('message_chain_enrollments', 1);
    }

    public function test_non_eager_entry_persists_due_chain_without_immediate_progression_job(): void
    {
        Queue::fake();

        [$campaign] = $this->campaignWithChain('bounded_import_entry');
        $contact = Contact::factory()->create();

        $enrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
            entryKey: 'contact_import:bounded_profile:bounded_import_entry',
            eagerProcess: false,
        );

        $this->assertSame(MessageChainEnrollment::STATUS_ACTIVE, $enrollment->runtimeStatus());
        $this->assertNotNull($enrollment->messageChainEnrollment?->next_action_at);
        Queue::assertNotPushed(ProcessMessageChainEnrollmentJob::class);
    }

    private function campaignWithChain(
        string $key,
        string $campaignStatus = Campaign::STATUS_ACTIVE,
        string $chainStatus = MessageChain::STATUS_ACTIVE,
        bool $stepActive = true,
        ?string $familyKey = null,
        int $priority = 0,
    ): array {
        $templateVersion = $this->templateVersion("fixture.{$key}.email");

        $chain = MessageChain::query()->create([
            'key' => "campaign.{$key}",
            'name' => "{$key} chain",
            'status' => $chainStatus,
            'source' => 'test',
            'is_customized' => false,
        ]);

        $version = app(PublishMessageChainVersionAction::class)->handle(
            messageChain: $chain,
            steps: [[
                'key' => 'step_1',
                'name' => 'Step 1',
                'sort_order' => 10,
                'timing_type' => MessageChainStep::TIMING_DELAY,
                'offset_seconds' => 7200,
                'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE,
                'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
                'conditions' => [],
                'is_active' => $stepActive,
                'variants' => [[
                    'key' => 'email',
                    'sort_order' => 10,
                    'message_template_version_id' => $templateVersion->getKey(),
                    'channel' => 'email',
                    'purpose' => 'marketing',
                    'scope' => 'campaign_test',
                    'message_type' => "{$key}_step_1",
                    'queue' => 'marketing',
                    'dependency_policy' => [],
                    'conditions' => [],
                    'is_active' => true,
                ]],
            ]],
        );

        $campaign = Campaign::query()->create([
            'key' => $key,
            'name' => "{$key} campaign",
            'message_chain_id' => $chain->getKey(),
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign_test',
            'status' => $campaignStatus,
            'family_key' => $familyKey,
            'priority' => $priority,
            'meta' => [],
        ]);

        return [$campaign, $chain->refresh(), $version];
    }

    private function templateVersion(string $key): MessageTemplateVersion
    {
        $template = MessageTemplate::query()->create([
            'key' => $key,
            'name' => $key,
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);

        $version = MessageTemplateVersion::query()->create([
            'message_template_id' => $template->getKey(),
            'version' => 1,
            'subject' => 'Fixture subject',
            'content' => ['body' => 'Fixture body'],
            'renderer_key' => 'fixture',
            'renderer_version' => '1',
            'content_hash' => hash('sha256', $key),
        ]);

        $template->forceFill([
            'current_version_id' => $version->getKey(),
        ])->save();

        return $version;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}