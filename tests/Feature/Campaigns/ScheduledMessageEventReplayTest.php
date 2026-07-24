<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\ScheduleNextCampaignStepAction;
use App\Modules\Campaigns\Listeners\ScheduleNextCampaignStepAfterScheduledMessageSent;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ScheduledMessageEventReplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_replayed_sent_event_advances_an_enrollment_only_once(): void
    {
        $campaign = Campaign::query()->create([
            'key' => 'outbox_replay',
            'name' => 'Outbox replay',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign',
            'status' => Campaign::STATUS_ACTIVE,
            'meta' => [],
        ]);

        $stepOne = CampaignStep::query()->create([
            'campaign_id' => $campaign->getKey(),
            'step_number' => 1,
            'name' => 'Step one',
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign',
            'variant_strategy' => 'first_available',
            'is_active' => true,
            'criteria' => [],
            'meta' => ['type' => 'message'],
        ]);

        $stepTwo = CampaignStep::query()->create([
            'campaign_id' => $campaign->getKey(),
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
            'contact_id' => $contact->getKey(),
            'campaign_id' => $campaign->getKey(),
            'campaign_key' => $campaign->key,
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 1,
            'current_campaign_step_id' => $stepOne->getKey(),
            'started_at' => now(),
            'meta' => [],
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'status' => ScheduledMessage::STATUS_SENT,
            'sent_at' => now(),
            'meta' => [
                'campaign_enrollment_id' => $enrollment->getKey(),
                'campaign_id' => $campaign->getKey(),
                'campaign_step_id' => $stepOne->getKey(),
                'campaign_step' => 1,
            ],
        ]);

        $nextStep = Mockery::mock(ScheduleNextCampaignStepAction::class);
        $nextStep
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (CampaignEnrollment $lockedEnrollment) use ($stepTwo): null {
                $lockedEnrollment->forceFill([
                    'current_step' => 2,
                    'current_campaign_step_id' => $stepTwo->getKey(),
                ])->save();

                return null;
            });

        app()->instance(ScheduleNextCampaignStepAction::class, $nextStep);

        $listener = app(ScheduleNextCampaignStepAfterScheduledMessageSent::class);
        $event = new ScheduledMessageSent($scheduledMessage);

        $listener->handle($event);
        $listener->handle($event);

        $enrollment->refresh();

        $this->assertSame(2, $enrollment->current_step);
        $this->assertSame($stepTwo->getKey(), $enrollment->current_campaign_step_id);
    }
}