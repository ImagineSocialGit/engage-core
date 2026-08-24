<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\ApplyAutomaticCampaignEligibilityAction;
use App\Modules\Campaigns\Actions\CancelCampaignEnrollmentAction;
use App\Modules\Campaigns\Actions\EnrollContactInCampaignAction;
use App\Modules\Campaigns\Actions\PauseCampaignEnrollmentAction;
use App\Modules\Campaigns\Actions\ReconcileCampaignEligibilityAction;
use App\Modules\Campaigns\Data\CampaignEligibilityLifecycleResult;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignEligibilityLifecycleRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_contact_is_enrolled_once_and_unchanged_eligibility_does_not_duplicate(): void
    {
        Queue::fake();

        [$campaign] = $this->automaticCampaignWithChain('auto_once');
        $contact = $this->taggedContact('VIP');

        $first = app(ApplyAutomaticCampaignEligibilityAction::class)->handle(
            $campaign,
            $contact,
        );
        $second = app(ApplyAutomaticCampaignEligibilityAction::class)->handle(
            $campaign,
            $contact,
        );

        $this->assertSame(CampaignEligibilityLifecycleResult::ENROLLED, $first->action);
        $this->assertSame(CampaignEligibilityLifecycleResult::EXISTING_OPEN_ENROLLMENT, $second->action);
        $this->assertSame(1, $first->evaluation?->eligibilityCycle);
        $this->assertDatabaseCount('campaign_enrollments', 1);
        $this->assertDatabaseCount('campaign_eligibility_states', 1);
    }

    public function test_cancel_on_ineligible_and_reentry_never_does_not_restart_on_later_cycle(): void
    {
        Queue::fake();

        [$campaign] = $this->automaticCampaignWithChain(
            key: 'cancel_never',
            ineligibleBehavior: Campaign::INELIGIBLE_CANCEL,
            reentryPolicy: Campaign::REENTRY_NEVER,
        );
        $contact = $this->taggedContact('VIP');

        $enrolled = app(ApplyAutomaticCampaignEligibilityAction::class)->handle($campaign, $contact);
        $this->removeTag($contact, 'VIP');
        $cancelled = app(ApplyAutomaticCampaignEligibilityAction::class)->handle($campaign, $contact);
        $this->addTag($contact, 'VIP');
        $blocked = app(ApplyAutomaticCampaignEligibilityAction::class)->handle($campaign, $contact);

        $this->assertSame(CampaignEligibilityLifecycleResult::ENROLLED, $enrolled->action);
        $this->assertSame(CampaignEligibilityLifecycleResult::CANCELLED, $cancelled->action);
        $this->assertSame(CampaignEligibilityLifecycleResult::REENTRY_BLOCKED, $blocked->action);
        $this->assertSame(2, $blocked->evaluation?->eligibilityCycle);
        $this->assertDatabaseCount('campaign_enrollments', 1);
        $this->assertSame(
            MessageChainEnrollment::STATUS_CANCELLED,
            $enrolled->enrollment?->messageChainEnrollment?->refresh()->status,
        );
    }

    public function test_reentry_when_eligible_again_creates_new_enrollment_only_after_real_second_cycle(): void
    {
        Queue::fake();

        [$campaign] = $this->automaticCampaignWithChain(
            key: 'reentry_again',
            ineligibleBehavior: Campaign::INELIGIBLE_CANCEL,
            reentryPolicy: Campaign::REENTRY_WHEN_ELIGIBLE_AGAIN,
        );
        $contact = $this->taggedContact('VIP');

        $first = app(ApplyAutomaticCampaignEligibilityAction::class)->handle($campaign, $contact);
        $this->removeTag($contact, 'VIP');
        app(ApplyAutomaticCampaignEligibilityAction::class)->handle($campaign, $contact);
        $this->addTag($contact, 'VIP');
        $second = app(ApplyAutomaticCampaignEligibilityAction::class)->handle($campaign, $contact);

        $this->assertSame(CampaignEligibilityLifecycleResult::ENROLLED, $first->action);
        $this->assertSame(CampaignEligibilityLifecycleResult::ENROLLED, $second->action);
        $this->assertSame(2, $second->evaluation?->eligibilityCycle);
        $this->assertDatabaseCount('campaign_enrollments', 2);
    }

    public function test_pause_on_ineligible_resumes_same_enrollment_when_eligibility_returns(): void
    {
        Queue::fake();

        [$campaign] = $this->automaticCampaignWithChain(
            key: 'pause_resume_eligibility',
            ineligibleBehavior: Campaign::INELIGIBLE_PAUSE,
        );
        $contact = $this->taggedContact('VIP');

        $started = app(ApplyAutomaticCampaignEligibilityAction::class)->handle($campaign, $contact);
        $this->removeTag($contact, 'VIP');
        $paused = app(ApplyAutomaticCampaignEligibilityAction::class)->handle($campaign, $contact);
        $this->addTag($contact, 'VIP');
        $resumed = app(ApplyAutomaticCampaignEligibilityAction::class)->handle($campaign, $contact);

        $this->assertSame(CampaignEligibilityLifecycleResult::PAUSED, $paused->action);
        $this->assertSame(CampaignEligibilityLifecycleResult::RESUMED, $resumed->action);
        $this->assertSame($started->enrollment?->getKey(), $resumed->enrollment?->getKey());
        $this->assertSame(MessageChainEnrollment::STATUS_ACTIVE, $resumed->enrollment?->runtimeStatus());
        $this->assertDatabaseCount('campaign_enrollments', 1);
    }

    public function test_eligibility_does_not_resume_a_campaign_that_was_paused_for_another_reason(): void
    {
        Queue::fake();

        [$campaign] = $this->automaticCampaignWithChain(
            key: 'manual_pause_guard',
            ineligibleBehavior: Campaign::INELIGIBLE_PAUSE,
        );
        $contact = $this->taggedContact('VIP');

        $started = app(ApplyAutomaticCampaignEligibilityAction::class)->handle($campaign, $contact);

        app(PauseCampaignEnrollmentAction::class)->pauseEnrollment(
            enrollment: $started->enrollment,
            reason: 'manual_human_hold',
        );

        $this->removeTag($contact, 'VIP');
        app(ApplyAutomaticCampaignEligibilityAction::class)->handle($campaign, $contact);
        $this->addTag($contact, 'VIP');
        $result = app(ApplyAutomaticCampaignEligibilityAction::class)->handle($campaign, $contact);

        $this->assertSame(CampaignEligibilityLifecycleResult::EXISTING_OPEN_ENROLLMENT, $result->action);
        $this->assertSame(MessageChainEnrollment::STATUS_PAUSED, $result->enrollment?->runtimeStatus());
        $this->assertSame(
            'manual_human_hold',
            data_get($result->enrollment?->meta, 'lifecycle.last_pause.reason'),
        );
    }

    public function test_existing_history_does_not_restart_on_first_automatic_evaluation(): void
    {
        Queue::fake();

        [$campaign] = $this->automaticCampaignWithChain(
            key: 'migration_history_guard',
            reentryPolicy: Campaign::REENTRY_WHEN_ELIGIBLE_AGAIN,
        );
        $contact = $this->taggedContact('VIP');

        $historical = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
        );
        app(CancelCampaignEnrollmentAction::class)->cancelEnrollment(
            enrollment: $historical,
            reason: 'historical_fixture',
        );

        $result = app(ApplyAutomaticCampaignEligibilityAction::class)->handle(
            $campaign,
            $contact,
        );

        $this->assertSame(CampaignEligibilityLifecycleResult::REENTRY_BLOCKED, $result->action);
        $this->assertSame(1, $result->evaluation?->eligibilityCycle);
        $this->assertDatabaseCount('campaign_enrollments', 1);
    }

    public function test_family_blocked_eligibility_retries_while_still_eligible_if_candidate_has_never_enrolled(): void
    {
        Queue::fake();

        [$blocker] = $this->automaticCampaignWithChain(
            key: 'family_blocker',
            familyKey: 'consumer_nurture',
            priority: 50,
            enrollmentMode: Campaign::ENROLLMENT_MODE_MANUAL,
            eligibilityFilter: [],
        );
        [$candidate] = $this->automaticCampaignWithChain(
            key: 'family_candidate',
            familyKey: 'consumer_nurture',
            priority: 10,
        );
        $contact = $this->taggedContact('VIP');

        $blockerEnrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $blocker->key,
        );

        $blocked = app(ApplyAutomaticCampaignEligibilityAction::class)->handle(
            $candidate,
            $contact,
        );

        $this->assertSame(CampaignEligibilityLifecycleResult::FAMILY_BLOCKED, $blocked->action);
        $this->assertSame(1, $blocked->evaluation?->eligibilityCycle);
        $this->assertDatabaseMissing('campaign_enrollments', [
            'campaign_id' => $candidate->getKey(),
            'contact_id' => $contact->getKey(),
        ]);

        app(CancelCampaignEnrollmentAction::class)->cancelEnrollment(
            enrollment: $blockerEnrollment,
            reason: 'fixture_blocker_removed',
        );

        $retried = app(ApplyAutomaticCampaignEligibilityAction::class)->handle(
            $candidate,
            $contact,
        );

        $this->assertSame(CampaignEligibilityLifecycleResult::ENROLLED, $retried->action);
        $this->assertSame(1, $retried->evaluation?->eligibilityCycle);
        $this->assertDatabaseHas('campaign_enrollments', [
            'campaign_id' => $candidate->getKey(),
            'contact_id' => $contact->getKey(),
        ]);
    }

    public function test_ineligible_continue_leaves_open_enrollment_running(): void
    {
        Queue::fake();

        [$campaign] = $this->automaticCampaignWithChain(
            key: 'continue_running',
            ineligibleBehavior: Campaign::INELIGIBLE_CONTINUE,
        );
        $contact = $this->taggedContact('VIP');

        $started = app(ApplyAutomaticCampaignEligibilityAction::class)->handle($campaign, $contact);
        $this->removeTag($contact, 'VIP');
        $continued = app(ApplyAutomaticCampaignEligibilityAction::class)->handle($campaign, $contact);

        $this->assertSame(CampaignEligibilityLifecycleResult::CONTINUED, $continued->action);
        $this->assertSame($started->enrollment?->getKey(), $continued->enrollment?->getKey());
        $this->assertSame(MessageChainEnrollment::STATUS_ACTIVE, $continued->enrollment?->runtimeStatus());
    }

    public function test_manual_campaign_is_untouched_by_automatic_eligibility_runtime(): void
    {
        Queue::fake();

        [$campaign] = $this->automaticCampaignWithChain(
            key: 'manual_untouched',
            enrollmentMode: Campaign::ENROLLMENT_MODE_MANUAL,
            eligibilityFilter: [],
        );
        $contact = $this->taggedContact('VIP');

        $result = app(ApplyAutomaticCampaignEligibilityAction::class)->handle(
            $campaign,
            $contact,
        );

        $this->assertSame(CampaignEligibilityLifecycleResult::SKIPPED_MANUAL, $result->action);
        $this->assertNull($result->evaluation);
        $this->assertDatabaseCount('campaign_eligibility_states', 0);
        $this->assertDatabaseCount('campaign_enrollments', 0);
    }

    public function test_campaign_reconciliation_processes_every_contact_and_reports_actions(): void
    {
        Queue::fake();

        [$campaign] = $this->automaticCampaignWithChain('campaign_reconcile');
        $eligible = $this->taggedContact('VIP');
        $ineligible = Contact::factory()->create();

        $summary = app(ReconcileCampaignEligibilityAction::class)->handle(
            campaign: $campaign,
            chunkSize: 1,
        );

        $this->assertSame(2, $summary['contacts_processed']);
        $this->assertSame(2, $summary['evaluated']);
        $this->assertSame(1, $summary['actions'][CampaignEligibilityLifecycleResult::ENROLLED] ?? 0);
        $this->assertSame(1, $summary['actions'][CampaignEligibilityLifecycleResult::NO_OPEN_ENROLLMENT] ?? 0);
        $this->assertDatabaseHas('campaign_enrollments', [
            'campaign_id' => $campaign->getKey(),
            'contact_id' => $eligible->getKey(),
        ]);
        $this->assertDatabaseMissing('campaign_enrollments', [
            'campaign_id' => $campaign->getKey(),
            'contact_id' => $ineligible->getKey(),
        ]);
    }

    /**
     * @param array<string, array<int, string>>|null $eligibilityFilter
     * @return array{0: Campaign, 1: MessageChain}
     */
    private function automaticCampaignWithChain(
        string $key,
        string $ineligibleBehavior = Campaign::INELIGIBLE_CANCEL,
        string $reentryPolicy = Campaign::REENTRY_NEVER,
        ?string $familyKey = null,
        int $priority = 0,
        string $enrollmentMode = Campaign::ENROLLMENT_MODE_AUTOMATIC,
        ?array $eligibilityFilter = null,
    ): array {
        $templateVersion = $this->templateVersion("fixture.{$key}.email");

        $chain = MessageChain::query()->create([
            'key' => "campaign.{$key}",
            'name' => "{$key} chain",
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);

        app(PublishMessageChainVersionAction::class)->handle(
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
                'is_active' => true,
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
            'family_key' => $familyKey,
            'priority' => $priority,
            'eligibility_filter' => $eligibilityFilter ?? ['tag' => ['VIP']],
            'enrollment_mode' => $enrollmentMode,
            'reentry_policy' => $reentryPolicy,
            'ineligible_behavior' => $ineligibleBehavior,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign_test',
            'status' => Campaign::STATUS_ACTIVE,
            'meta' => [],
        ]);

        return [$campaign, $chain->refresh()];
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

    private function taggedContact(string $tag): Contact
    {
        $contact = Contact::factory()->create();
        $this->addTag($contact, $tag);

        return $contact;
    }

    private function addTag(Contact $contact, string $tag): void
    {
        ContactTag::withoutEvents(
            fn () => ContactTag::query()->firstOrCreate([
                'contact_id' => $contact->getKey(),
                'tag' => $tag,
            ]),
        );
    }

    private function removeTag(Contact $contact, string $tag): void
    {
        ContactTag::query()
            ->where('contact_id', $contact->getKey())
            ->where('tag', $tag)
            ->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}