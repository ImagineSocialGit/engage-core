<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Import\CampaignLaunchTimingContactImportPostProcessor;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
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

class CampaignAutomaticEligibilityImportLaunchTest extends TestCase
{
    use RefreshDatabase;

    public function test_processing_import_can_resolve_automatic_eligibility_before_batch_launch_timing_is_applied(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-08-24 14:00:00 UTC');
        config()->set('client.timezone', 'America/New_York');

        $campaign = $this->automaticCampaign('candidate_campaign');

        $batch = ContactImportBatch::factory()->create([
            'status' => ContactImportBatch::STATUS_PROCESSING,
            'imported_at' => now()->subMinute(),
        ]);

        $contact = Contact::factory()->create([
            'contact_import_batch_id' => $batch->getKey(),
        ]);

        ContactTag::withoutEvents(
            fn () => ContactTag::query()->create([
                'contact_id' => $contact->getKey(),
                'tag' => 'Launch',
            ]),
        );

        $occurrence = ContactImportOccurrence::query()->create([
            'contact_import_batch_id' => $batch->getKey(),
            'contact_id' => $contact->getKey(),
            'row_number' => 2,
            'outcome' => ContactImportOccurrence::OUTCOME_CREATED,
            'identity_type' => 'email',
            'identity_value' => $contact->email,
            'row_fingerprint' => hash('sha256', $contact->email),
            'meta' => [],
        ]);

        $context = new ContactImportContext(
            contact: $contact,
            batch: $batch,
            occurrence: $occurrence,
            row: ['Email' => $contact->email],
            mapping: ['email' => 'Email'],
            profileKey: 'test_profile',
        );

        $processor = app(CampaignLaunchTimingContactImportPostProcessor::class);
        $config = $processor->withSubmittedInputs(
            config: [
                'campaign_key' => $campaign->key,
            ],
            submitted: [
                'first_message_at' => '2026-08-25T10:00',
            ],
        );

        $rowResult = $processor->handle($context, $config);

        $this->assertSame(
            ContactImportPostProcessResult::STATE_APPLIED,
            $rowResult->state,
        );

        $enrollment = $campaign->enrollments()
            ->with('messageChainEnrollment')
            ->where('contact_id', $contact->getKey())
            ->firstOrFail();

        $this->assertSame(
            MessageChainEnrollment::STATUS_ACTIVE,
            $enrollment->messageChainEnrollment?->status,
        );

        $this->assertTrue(
            $enrollment->messageChainEnrollment?->next_action_at?->equalTo(
                Carbon::parse('2026-08-26T14:00:00Z'),
            ) ?? false,
        );

        $finalization = $processor->finalizeBatch(
            batch: $batch,
            config: $config,
        );

        $this->assertSame(
            ContactImportPostProcessResult::STATE_APPLIED,
            $finalization->state,
        );
        $this->assertSame(1, $finalization->meta['enrollment_count']);
        $this->assertTrue(
            $enrollment->messageChainEnrollment?->fresh()->next_action_at?->equalTo(
                Carbon::parse('2026-08-25T14:00:00Z'),
            ) ?? false,
        );
    }

    private function automaticCampaign(string $key): Campaign
    {
        $template = MessageTemplate::query()->create([
            'key' => "fixture.{$key}.email",
            'name' => "{$key} email",
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);

        $templateVersion = MessageTemplateVersion::query()->create([
            'message_template_id' => $template->getKey(),
            'version' => 1,
            'subject' => 'Fixture subject',
            'content' => ['body' => 'Fixture body'],
            'renderer_key' => 'fixture',
            'renderer_version' => '1',
            'content_hash' => hash('sha256', $key),
        ]);

        $template->forceFill([
            'current_version_id' => $templateVersion->getKey(),
        ])->save();

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
                'offset_seconds' => 172800,
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
            'family_key' => 'consumer_nurture',
            'priority' => 10,
            'eligibility_filter' => ['tag' => ['Launch']],
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}