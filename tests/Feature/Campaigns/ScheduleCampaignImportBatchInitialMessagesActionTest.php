<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\ScheduleCampaignImportBatchInitialMessagesAction;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class ScheduleCampaignImportBatchInitialMessagesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_retimes_all_staged_initial_actions_atomically_for_one_import_batch(): void
    {
        Carbon::setTestNow('2026-08-21 12:00:00 UTC');

        $batch = ContactImportBatch::factory()->create([
            'imported_at' => now()->subMinute(),
        ]);

        $first = $this->stagedEnrollment($batch, 'candidate_campaign');
        $second = $this->stagedEnrollment($batch, 'candidate_campaign');
        $otherBatch = ContactImportBatch::factory()->create([
            'imported_at' => now()->subMinute(),
        ]);
        $untouched = $this->stagedEnrollment($otherBatch, 'candidate_campaign');

        $result = app(ScheduleCampaignImportBatchInitialMessagesAction::class)->handle(
            batch: $batch,
            campaignKey: 'candidate_campaign',
            firstMessageAt: '2026-08-22T14:30:00Z',
        );

        $this->assertSame(2, $result['enrollment_count']);
        $this->assertSame(
            '2026-08-22T14:30:00.000000Z',
            $result['effective_first_message_at'],
        );

        $this->assertTrue(
            $first->messageChainEnrollment->fresh()->next_action_at?->equalTo(
                Carbon::parse('2026-08-22T14:30:00Z'),
            ) ?? false,
        );
        $this->assertTrue(
            $second->messageChainEnrollment->fresh()->next_action_at?->equalTo(
                Carbon::parse('2026-08-22T14:30:00Z'),
            ) ?? false,
        );
        $this->assertTrue(
            $untouched->messageChainEnrollment->fresh()->next_action_at?->equalTo(
                Carbon::parse('2026-08-23T12:00:00Z'),
            ) ?? false,
        );
    }

    public function test_past_requested_time_becomes_due_together_only_after_batch_finalization(): void
    {
        Carbon::setTestNow('2026-08-21 12:00:00 UTC');

        $batch = ContactImportBatch::factory()->create([
            'imported_at' => now()->subMinute(),
        ]);
        $enrollment = $this->stagedEnrollment($batch, 'candidate_campaign');

        $result = app(ScheduleCampaignImportBatchInitialMessagesAction::class)->handle(
            batch: $batch,
            campaignKey: 'candidate_campaign',
            firstMessageAt: '2026-08-21T11:00:00Z',
        );

        $this->assertSame(
            '2026-08-21T12:00:00.000000Z',
            $result['effective_first_message_at'],
        );
        $this->assertTrue(
            $enrollment->messageChainEnrollment->fresh()->next_action_at?->equalTo(now()) ?? false,
        );
    }

    public function test_it_refuses_to_retime_a_batch_after_any_message_was_materialized(): void
    {
        Carbon::setTestNow('2026-08-21 12:00:00 UTC');

        $batch = ContactImportBatch::factory()->create([
            'imported_at' => now()->subMinute(),
        ]);
        $enrollment = $this->stagedEnrollment($batch, 'candidate_campaign');
        $chainEnrollment = $enrollment->messageChainEnrollment;

        ScheduledMessage::factory()->create([
            'recipient_type' => $enrollment->contact->getMorphClass(),
            'recipient_id' => $enrollment->contact_id,
            'message_chain_enrollment_id' => $chainEnrollment->getKey(),
            'send_at' => now()->addDay(),
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot change after a Campaign message has already been materialized');

        app(ScheduleCampaignImportBatchInitialMessagesAction::class)->handle(
            batch: $batch,
            campaignKey: 'candidate_campaign',
            firstMessageAt: '2026-08-22T14:30:00Z',
        );
    }

    private function stagedEnrollment(
        ContactImportBatch $batch,
        string $campaignKey,
    ): CampaignEnrollment {
        $contact = Contact::factory()->create();
        $chain = MessageChain::query()->create([
            'key' => 'chain-'.uniqid(),
            'name' => 'Test chain',
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);
        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'exit_conditions' => [],
            'content_hash' => hash('sha256', uniqid('', true)),
            'published_at' => null,
        ]);
        $step = MessageChainStep::query()->create([
            'message_chain_version_id' => $version->getKey(),
            'key' => 'first',
            'name' => 'First',
            'sort_order' => 10,
            'timing_type' => MessageChainStep::TIMING_DELAY,
            'offset_seconds' => 86400,
            'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_SEND_ALL_ELIGIBLE,
            'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
            'is_active' => true,
        ]); 

        $version->forceFill([
            'published_at' => now(),
        ])->save();

        $enrollment = CampaignEnrollment::query()->create([
            'contact_id' => $contact->getKey(),
            'campaign_id' => null,
            'message_chain_enrollment_id' => null,
            'campaign_key' => $campaignKey,
            'started_at' => now(),
            'meta' => [],
        ]);

        $rowNumber = max(
            2,
            ((int) ContactImportOccurrence::query()
                ->where('contact_import_batch_id', $batch->getKey())
                ->max('row_number')) + 1,
        );

        ContactImportOccurrence::query()->create([
            'contact_import_batch_id' => $batch->getKey(),
            'contact_id' => $contact->getKey(),
            'row_number' => $rowNumber,
            'outcome' => ContactImportOccurrence::OUTCOME_CREATED,
            'identity_type' => 'email',
            'identity_value' => $contact->email,
            'row_fingerprint' => hash(
                'sha256',
                (string) $batch->getKey().':'.$contact->email,
            ),
            'meta' => [],
        ]);

        $chainEnrollment = MessageChainEnrollment::query()->create([
            'message_chain_version_id' => $version->getKey(),
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'context_type' => $enrollment->getMorphClass(),
            'context_id' => $enrollment->getKey(),
            'surface' => 'campaigns',
            'current_message_chain_step_id' => $step->getKey(),
            'next_action_at' => now()->addDays(2),
            'status' => MessageChainEnrollment::STATUS_ACTIVE,
            'dedupe_key' => 'test:'.uniqid(),
            'started_at' => now(),
        ]);

        $enrollment->forceFill([
            'message_chain_enrollment_id' => $chainEnrollment->getKey(),
        ])->save();

        return $enrollment->refresh()->load([
            'contact',
            'messageChainEnrollment',
        ]);
    }
}