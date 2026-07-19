<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\ScheduleNextCampaignStepAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Events\ScheduledMessageFailed;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CampaignScheduledMessageTerminalReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_variant_waits_for_sending_sibling_then_records_policy_and_advances(): void
    {
        $campaign = Campaign::query()->create([
            'key' => 'terminal_reconciliation',
            'name' => 'Terminal reconciliation',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign',
            'status' => Campaign::STATUS_ACTIVE,
            'is_active' => true,
            'meta' => [],
        ]);

        $stepOne = CampaignStep::query()->create([
            'campaign_id' => $campaign->id,
            'step_number' => 1,
            'name' => 'Step one',
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign',
            'variant_strategy' => 'send_all_eligible',
            'is_active' => true,
            'criteria' => [],
            'meta' => ['type' => 'message'],
        ]);

        $stepTwo = CampaignStep::query()->create([
            'campaign_id' => $campaign->id,
            'step_number' => 2,
            'name' => 'Step two',
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign',
            'variant_strategy' => 'first_available',
            'is_active' => true,
            'criteria' => [],
            'meta' => ['type' => 'message'],
        ]);

        $contact = Contact::factory()->create();

        $enrollment = CampaignEnrollment::query()->create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'campaign_key' => $campaign->key,
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 1,
            'current_campaign_step_id' => $stepOne->id,
            'started_at' => now(),
            'meta' => [],
        ]);

        $messageMeta = [
            'campaign_enrollment_id' => $enrollment->id,
            'campaign_id' => $campaign->id,
            'campaign_key' => $campaign->key,
            'campaign_step_id' => $stepOne->id,
            'campaign_step' => 1,
            'campaign_step_waits_for_all_scheduled_variants' => true,
        ];

        $failedMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign',
            'status' => ScheduledMessage::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => 'Provider rejected the message.',
            'meta' => array_replace($messageMeta, [
                'campaign_step_variant_key' => 'email',
            ]),
        ]);

        $sendingSibling = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'campaign',
            'status' => ScheduledMessage::STATUS_SENDING,
            'sending_at' => now(),
            'claim_token' => 'sending-sibling-claim',
            'claim_expires_at' => now()->addMinutes(5),
            'meta' => array_replace($messageMeta, [
                'campaign_step_variant_key' => 'sms',
            ]),
        ]);

        $nextStep = Mockery::mock(ScheduleNextCampaignStepAction::class);
        $nextStep
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (CampaignEnrollment $lockedEnrollment) use ($stepTwo): null {
                $lockedEnrollment->forceFill([
                    'current_step' => 2,
                    'current_campaign_step_id' => $stepTwo->id,
                ])->save();

                return null;
            });

        app()->instance(ScheduleNextCampaignStepAction::class, $nextStep);

        event(new ScheduledMessageFailed($failedMessage));

        $enrollment->refresh();

        $this->assertSame(1, $enrollment->current_step);
        $this->assertSame($stepOne->id, $enrollment->current_campaign_step_id);
        $this->assertSame(
            'wait_for_scheduled_sibling_variants',
            data_get(
                $enrollment->meta,
                'scheduled_message_terminal_failures.'.$failedMessage->id.'.decision',
            ),
        );

        $sendingSibling->forceFill([
            'status' => ScheduledMessage::STATUS_SENT,
            'sending_at' => null,
            'claim_token' => null,
            'claim_expires_at' => null,
            'sent_at' => now(),
        ])->save();

        event(new ScheduledMessageSent($sendingSibling));

        $enrollment->refresh();

        $failure = data_get(
            $enrollment->meta,
            'scheduled_message_terminal_failures.'.$failedMessage->id,
        );

        $this->assertSame(2, $enrollment->current_step);
        $this->assertSame($stepTwo->id, $enrollment->current_campaign_step_id);
        $this->assertIsArray($failure);
        $this->assertSame($failedMessage->id, $failure['scheduled_message_id']);
        $this->assertSame('Provider rejected the message.', $failure['failure_reason']);
        $this->assertSame(
            'skip_failed_variant_after_all_scheduled_variants_terminal',
            $failure['policy'],
        );
        $this->assertSame('continue_to_next_campaign_step', $failure['decision']);
        $this->assertSame(
            $sendingSibling->id,
            $failure['reconciled_by_scheduled_message_id'],
        );
        $this->assertDatabaseCount('scheduled_messages', 2);
    }
}