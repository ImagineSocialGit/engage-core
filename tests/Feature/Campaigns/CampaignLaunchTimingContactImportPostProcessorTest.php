<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\ScheduleCampaignImportBatchInitialMessagesAction;
use App\Modules\Campaigns\Import\CampaignLaunchTimingContactImportPostProcessor;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CampaignLaunchTimingContactImportPostProcessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_time_is_interpreted_in_client_timezone_and_keeps_campaign_key_server_owned(): void
    {
        config()->set('client.timezone', 'America/New_York');

        $processor = app(CampaignLaunchTimingContactImportPostProcessor::class);
        $effective = $processor->withSubmittedInputs(
            config: [
                'campaign_key' => 'candidate_campaign',
            ],
            submitted: [
                'first_message_at' => '2026-08-21T10:00',
            ],
        );

        $this->assertSame('candidate_campaign', $effective['campaign_key']);
        $this->assertSame(
            '2026-08-21T14:00:00.000000Z',
            $effective['first_message_at'],
        );
    }

    public function test_new_status_routed_enrollment_is_staged_then_retimed_only_at_batch_finalization(): void
    {
        Carbon::setTestNow('2026-08-21 12:00:00 UTC');
        config()->set('client.timezone', 'America/New_York');

        [$context, $enrollment] = $this->contextWithEnrollment(
            enrollmentStartedAt: now(),
        );

        $processor = app(CampaignLaunchTimingContactImportPostProcessor::class);
        $config = $processor->withSubmittedInputs(
            config: [
                'campaign_key' => 'candidate_campaign',
            ],
            submitted: [
                'first_message_at' => '2026-08-22T10:00',
            ],
        );

        $rowResult = $processor->handle($context, $config);

        $this->assertSame(
            ContactImportPostProcessResult::STATE_APPLIED,
            $rowResult->state,
        );
        $this->assertSame([], $enrollment->fresh()->meta ?? []);

        $this->assertTrue(
            $enrollment->messageChainEnrollment->fresh()->next_action_at?->equalTo(
                Carbon::parse('2026-08-23T12:00:00Z'),
            ) ?? false,
        );

        $finalization = $processor->finalizeBatch(
            batch: $context->batch,
            config: $config,
        );

        $this->assertSame(
            ContactImportPostProcessResult::STATE_APPLIED,
            $finalization->state,
        );
        $this->assertSame(1, $finalization->meta['enrollment_count']);
        $this->assertTrue(
            $enrollment->messageChainEnrollment->fresh()->next_action_at?->equalTo(
                Carbon::parse('2026-08-22T14:00:00Z'),
            ) ?? false,
        );
    }

    public function test_existing_open_enrollment_from_before_the_import_is_preserved(): void
    {
        Carbon::setTestNow('2026-08-21 12:00:00 UTC');

        [$context, $enrollment] = $this->contextWithEnrollment(
            enrollmentStartedAt: now()->subDay(),
        );

        $processor = app(CampaignLaunchTimingContactImportPostProcessor::class);
        $config = [
            'campaign_key' => 'candidate_campaign',
            'first_message_at' => '2026-08-22T14:00:00Z',
        ];

        $result = $processor->handle($context, $config);

        $this->assertSame(
            ContactImportPostProcessResult::STATE_SKIPPED,
            $result->state,
        );
        $this->assertSame(
            'campaign_launch_existing_enrollment_preserved',
            $result->reasonCode,
        );
        $this->assertSame([], $enrollment->fresh()->meta ?? []);
    }

    /**
     * @return array{0: ContactImportContext, 1: CampaignEnrollment}
     */
    private function contextWithEnrollment(
        Carbon $enrollmentStartedAt,
    ): array {
        $batch = ContactImportBatch::factory()->create([
            'status' => ContactImportBatch::STATUS_PROCESSING,
            'imported_at' => now()->subMinute(),
        ]);
        $contact = Contact::factory()->create();
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
            'campaign_key' => 'candidate_campaign',
            'started_at' => $enrollmentStartedAt,
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
            'started_at' => $enrollmentStartedAt,
        ]);

        $enrollment->forceFill([
            'message_chain_enrollment_id' => $chainEnrollment->getKey(),
        ])->save();

        return [
            new ContactImportContext(
                contact: $contact,
                batch: $batch,
                occurrence: $occurrence,
                row: ['Email' => $contact->email],
                mapping: ['email' => 'Email'],
                profileKey: 'test_profile',
            ),
            $enrollment->refresh()->load('messageChainEnrollment'),
        ];
    }
}