<?php

namespace Tests\Feature\Campaigns;

use App\Models\User;
use App\Modules\Campaigns\Actions\RecordPriorCampaignMessageReceiptAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignPriorMessageReceipt;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ProcessMessageChainEnrollmentAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\MessageChainStepBypassRegistry;
use App\Modules\Messaging\Services\ScheduledMessageGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignPriorMessageSkipTest extends TestCase
{
    use RefreshDatabase;

    public function test_prior_receipt_selectively_bypasses_a_or_b_and_preserves_other_messages(): void
    {
        Queue::fake();
        [$campaign, $version] = $this->campaign();
        $action = app(RecordPriorCampaignMessageReceiptAction::class);
        $operator = User::factory()->create();
        $processor = app(ProcessMessageChainEnrollmentAction::class);

        $receivedA = $this->enrollment($campaign, $version);
        $action->handle(
            $receivedA->recipient,
            $campaign,
            'step_1',
            CampaignPriorMessageReceipt::SOURCE_OPERATOR_ATTESTATION,
            attestedBy: $operator,
        );
        $processor->handle($receivedA);
        $this->assertSame('step_2', $receivedA->fresh()->currentMessageChainStep?->key);
        $this->assertSame(0, $receivedA->scheduledMessages()->count());
        $processor->handle($receivedA);
        $this->assertSame('step_2', $receivedA->scheduledMessages()->firstOrFail()
            ->messageChainStepVariant->messageChainStep->key);

        $receivedB = $this->enrollment($campaign, $version);
        $action->handle(
            $receivedB->recipient,
            $campaign,
            'step_2',
            CampaignPriorMessageReceipt::SOURCE_OPERATOR_ATTESTATION,
            attestedBy: $operator,
        );
        $processor->handle($receivedB);
        $messageA = $receivedB->scheduledMessages()->firstOrFail();
        $this->assertSame('step_1', $messageA->messageChainStepVariant->messageChainStep->key);
        $messageA->forceFill(['status' => ScheduledMessage::STATUS_SENT])->save();
        $processor->handleTerminal($messageA);
        $processor->handle($receivedB);
        $this->assertSame(1, $receivedB->scheduledMessages()->count());
        $this->assertSame(MessageChainEnrollment::STATUS_COMPLETED, $receivedB->fresh()->status);

        $receivedNeither = $this->enrollment($campaign, $version);
        $processor->handle($receivedNeither);
        $first = $receivedNeither->scheduledMessages()->firstOrFail();
        $first->forceFill(['status' => ScheduledMessage::STATUS_SENT])->save();
        $processor->handleTerminal($first);
        $processor->handle($receivedNeither);
        $this->assertSame(2, $receivedNeither->scheduledMessages()->count());
    }

    public function test_a_receipt_added_after_scheduling_blocks_the_pending_send(): void
    {
        Queue::fake();
        [$campaign, $version] = $this->campaign();
        $enrollment = $this->enrollment($campaign, $version);
        $processor = app(ProcessMessageChainEnrollmentAction::class);
        $processor->handle($enrollment);
        $message = $enrollment->scheduledMessages()->firstOrFail();
        $this->assertNull(app(ScheduledMessageGate::class)->denialReason($message));

        app(RecordPriorCampaignMessageReceiptAction::class)->handle(
            $enrollment->recipient,
            $campaign,
            'step_1',
            CampaignPriorMessageReceipt::SOURCE_OPERATOR_ATTESTATION,
            attestedBy: User::factory()->create(),
        );

        $this->assertSame(
            'Previously received outside this system.',
            app(ScheduledMessageGate::class)->denialReason($message->fresh()),
        );

        $message->forceFill(['status' => ScheduledMessage::STATUS_SKIPPED])->save();
        $processor->handleTerminal($message);
        $this->assertSame('step_2', $enrollment->fresh()->currentMessageChainStep?->key);
    }

    public function test_receipts_do_not_bypass_an_unrelated_chain_surface(): void
    {
        [$campaign, $version] = $this->campaign();
        $enrollment = $this->enrollment($campaign, $version);
        $step = $enrollment->currentMessageChainStep;
        app(RecordPriorCampaignMessageReceiptAction::class)->handle(
            $enrollment->recipient,
            $campaign,
            'step_1',
            CampaignPriorMessageReceipt::SOURCE_OPERATOR_ATTESTATION,
            attestedBy: User::factory()->create(),
        );

        $enrollment->forceFill(['surface' => 'automation'])->save();
        $this->assertNull(app(MessageChainStepBypassRegistry::class)
            ->reason($enrollment->fresh(), $step));
    }

    private function campaign(): array
    {
        $template = MessageTemplate::query()->create([
            'key' => 'campaign.prior_import.email',
            'name' => 'Prior import email',
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
        ]);
        $templateVersion = MessageTemplateVersion::query()->create([
            'message_template_id' => $template->getKey(),
            'version' => 1,
            'subject' => 'Fixture',
            'content' => ['body' => 'Fixture body'],
            'renderer_key' => 'email',
            'renderer_version' => '1',
            'content_hash' => hash('sha256', 'campaign-prior-import-email'),
        ]);
        $chain = MessageChain::query()->create([
            'key' => 'campaign.prior_import',
            'name' => 'Prior import chain',
            'status' => MessageChain::STATUS_ACTIVE,
        ]);
        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'content_hash' => hash('sha256', 'campaign-prior-import-chain'),
        ]);

        foreach (['Message A', 'Message B'] as $index => $name) {
            $step = MessageChainStep::query()->create([
                'message_chain_version_id' => $version->getKey(),
                'key' => 'step_'.($index + 1),
                'name' => $name,
                'sort_order' => ($index + 1) * 10,
            ]);
            MessageChainStepVariant::query()->create([
                'message_chain_step_id' => $step->getKey(),
                'key' => 'email',
                'message_template_version_id' => $templateVersion->getKey(),
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'fixture',
                'message_type' => 'fixture_'.($index + 1),
            ]);
        }

        $version->forceFill(['published_at' => now()])->save();
        $chain->forceFill(['current_version_id' => $version->getKey()])->save();
        $campaign = Campaign::factory()->create([
            'key' => 'prior_import',
            'message_chain_id' => $chain->getKey(),
            'status' => Campaign::STATUS_ACTIVE,
        ]);

        return [$campaign, $version];
    }

    private function enrollment(Campaign $campaign, MessageChainVersion $version): MessageChainEnrollment
    {
        $contact = Contact::factory()->create();
        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'fixture',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);
        $wrapper = CampaignEnrollment::query()->create([
            'contact_id' => $contact->getKey(),
            'campaign_id' => $campaign->getKey(),
            'campaign_key' => $campaign->key,
            'started_at' => now(),
        ]);
        $enrollment = MessageChainEnrollment::query()->create([
            'message_chain_version_id' => $version->getKey(),
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'context_type' => $wrapper->getMorphClass(),
            'context_id' => $wrapper->getKey(),
            'origin_type' => $campaign->getMorphClass(),
            'origin_id' => $campaign->getKey(),
            'surface' => 'campaigns',
            'current_message_chain_step_id' => $version->steps()->firstOrFail()->getKey(),
            'next_action_at' => now(),
            'status' => MessageChainEnrollment::STATUS_ACTIVE,
            'dedupe_key' => 'prior-import-'.uniqid(),
            'started_at' => now(),
        ]);
        $wrapper->forceFill(['message_chain_enrollment_id' => $enrollment->getKey()])->save();

        return $enrollment;
    }
}