<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\ReconcileAutomaticCampaignEligibilityAction;
use App\Modules\Campaigns\Jobs\ReconcileContactCampaignEligibilityJob;
use App\Modules\Campaigns\Listeners\ReconcileCampaignEligibilityFromContactFilterFactsChanged;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Events\ContactFilterFactsChanged;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Support\AutomationEvents\Models\AutomationEventConsumerReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignEligibilityFactReevaluationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fact_event_queues_targeted_reconciliation_and_job_evaluates_only_dependent_campaigns(): void
    {
        Queue::fake();

        $tagCampaign = $this->automaticCampaignWithChain(
            key: 'tag_dependent',
            eligibilityFilter: ['tag' => ['VIP']],
        );
        $sourceCampaign = $this->automaticCampaignWithChain(
            key: 'source_dependent',
            eligibilityFilter: ['source' => ['website']],
        );
        $contact = Contact::withoutEvents(fn () => Contact::factory()->create([
            'source' => 'crm',
        ]));

        ContactTag::withoutEvents(fn () => ContactTag::query()->create([
            'contact_id' => $contact->getKey(),
            'tag' => 'VIP',
        ]));

        $event = new ContactFilterFactsChanged(
            contactId: (int) $contact->getKey(),
            criterionKeys: ['tag'],
            source: 'test.synthetic',
            changes: [
                'added' => ['VIP'],
            ],
        );

        app(ReconcileCampaignEligibilityFromContactFilterFactsChanged::class)->handle($event);

        Queue::assertPushed(
            ReconcileContactCampaignEligibilityJob::class,
            function (ReconcileContactCampaignEligibilityJob $job) use ($contact): bool {
                return $job->contactId === (int) $contact->getKey()
                    && $job->criterionKeys === ['tag']
                    && $job->queue === 'campaigns';
            },
        );
        Queue::assertPushed(ReconcileContactCampaignEligibilityJob::class, 1);

        $job = new ReconcileContactCampaignEligibilityJob(
            contactId: (int) $contact->getKey(),
            criterionKeys: ['tag'],
            occurredAt: $event->occurredAt->toIso8601String(),
        );

        app()->call([$job, 'handle']);

        $this->assertDatabaseHas('campaign_enrollments', [
            'campaign_id' => $tagCampaign->getKey(),
            'contact_id' => $contact->getKey(),
        ]);
        $this->assertDatabaseMissing('campaign_eligibility_states', [
            'campaign_id' => $sourceCampaign->getKey(),
            'contact_id' => $contact->getKey(),
        ]);
        $this->assertDatabaseCount('campaign_enrollments', 1);
        $this->assertSame(0, AutomationEventConsumerReceipt::query()->count());
    }

    public function test_fact_event_queues_nothing_when_no_automatic_campaign_depends_on_changed_criterion(): void
    {
        Queue::fake();

        $sourceCampaign = $this->automaticCampaignWithChain(
            key: 'source_only',
            eligibilityFilter: ['source' => ['website']],
        );
        $contact = Contact::withoutEvents(fn () => Contact::factory()->create([
            'source' => 'crm',
        ]));

        app(ReconcileCampaignEligibilityFromContactFilterFactsChanged::class)->handle(
            new ContactFilterFactsChanged(
                contactId: (int) $contact->getKey(),
                criterionKeys: ['tag'],
                source: 'test.synthetic',
                changes: [
                    'added' => ['VIP'],
                ],
            ),
        );

        Queue::assertNothingPushed();

        $this->assertDatabaseMissing('campaign_eligibility_states', [
            'campaign_id' => $sourceCampaign->getKey(),
            'contact_id' => $contact->getKey(),
        ]);
    }

    public function test_processing_import_contacts_are_deferred_until_periodic_reconciliation_can_safely_evaluate_them(): void
    {
        Queue::fake();

        $campaign = $this->automaticCampaignWithChain(
            key: 'import_deferred',
            eligibilityFilter: ['tag' => ['VIP']],
        );
        $batch = ContactImportBatch::factory()->create([
            'status' => ContactImportBatch::STATUS_PROCESSING,
        ]);
        $contact = Contact::withoutEvents(fn () => Contact::factory()->create([
            'contact_import_batch_id' => $batch->getKey(),
        ]));

        ContactTag::withoutEvents(fn () => ContactTag::query()->create([
            'contact_id' => $contact->getKey(),
            'tag' => 'VIP',
        ]));

        app(ReconcileCampaignEligibilityFromContactFilterFactsChanged::class)->handle(
            new ContactFilterFactsChanged(
                contactId: (int) $contact->getKey(),
                criterionKeys: ['tag'],
                source: 'test.synthetic',
                changes: [
                    'added' => ['VIP'],
                ],
            ),
        );

        Queue::assertNothingPushed();

        $this->assertDatabaseMissing('campaign_eligibility_states', [
            'campaign_id' => $campaign->getKey(),
            'contact_id' => $contact->getKey(),
        ]);
        $this->assertDatabaseMissing('campaign_enrollments', [
            'campaign_id' => $campaign->getKey(),
            'contact_id' => $contact->getKey(),
        ]);

        $batch->forceFill([
            'status' => ContactImportBatch::STATUS_COMPLETED,
        ])->save();

        $summary = app(ReconcileAutomaticCampaignEligibilityAction::class)->handle(
            chunkSize: 1,
        );

        $this->assertSame(1, $summary['campaigns_processed']);
        $this->assertSame(1, $summary['contacts_processed']);
        $this->assertSame(0, $summary['contacts_skipped']);
        $this->assertDatabaseHas('campaign_enrollments', [
            'campaign_id' => $campaign->getKey(),
            'contact_id' => $contact->getKey(),
        ]);
    }

    /** @param array<string, array<int, string>> $eligibilityFilter */
    private function automaticCampaignWithChain(
        string $key,
        array $eligibilityFilter,
    ): Campaign {
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

        return Campaign::query()->create([
            'key' => $key,
            'name' => "{$key} campaign",
            'message_chain_id' => $chain->getKey(),
            'priority' => 0,
            'eligibility_filter' => $eligibilityFilter,
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_AUTOMATIC,
            'reentry_policy' => Campaign::REENTRY_NEVER,
            'ineligible_behavior' => Campaign::INELIGIBLE_CANCEL,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign_test',
            'status' => Campaign::STATUS_ACTIVE,
            'meta' => [],
        ]);
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
}