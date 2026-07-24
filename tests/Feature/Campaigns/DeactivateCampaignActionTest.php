<?php

namespace Tests\Feature\Campaigns;

use App\Models\User;
use App\Modules\Campaigns\Actions\ActivateCampaignAction;
use App\Modules\Campaigns\Actions\DeactivateCampaignAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeactivateCampaignActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivation_cancels_open_enrollments_and_skips_only_pending_campaign_messages(): void
    {
        Carbon::setTestNow('2026-07-24 12:00:00');

        $campaign = Campaign::factory()->create([
            'key' => 'generic_nurture',
            'status' => Campaign::STATUS_ACTIVE,
            'meta' => ['preset' => ['package' => 'default']],
        ]);
        $actor = User::factory()->create();
        $contact = Contact::factory()->create();

        $activeEnrollment = $this->enrollment(
            campaign: $campaign,
            contact: $contact,
            status: CampaignEnrollment::STATUS_ACTIVE,
        );
        $pausedEnrollment = $this->enrollment(
            campaign: $campaign,
            contact: Contact::factory()->create(),
            status: CampaignEnrollment::STATUS_PAUSED,
        );
        $completedEnrollment = $this->enrollment(
            campaign: $campaign,
            contact: Contact::factory()->create(),
            status: CampaignEnrollment::STATUS_COMPLETED,
        );

        $pendingById = ScheduledMessage::factory()
            ->forContact($contact)
            ->create([
                'meta' => [
                    'campaign_id' => $campaign->id,
                    'campaign_key' => $campaign->key,
                    'campaign_enrollment_id' => $activeEnrollment->id,
                ],
            ]);
        $pendingByKey = ScheduledMessage::factory()
            ->forContact($contact)
            ->create([
                'meta' => [
                    'campaign_key' => $campaign->key,
                    'campaign_enrollment_id' => $pausedEnrollment->id,
                ],
            ]);
        $sending = ScheduledMessage::factory()
            ->forContact($contact)
            ->sending()
            ->create([
                'meta' => [
                    'campaign_id' => $campaign->id,
                    'campaign_key' => $campaign->key,
                ],
            ]);
        $sent = ScheduledMessage::factory()
            ->forContact($contact)
            ->sent()
            ->create([
                'meta' => [
                    'campaign_id' => $campaign->id,
                    'campaign_key' => $campaign->key,
                ],
            ]);
        $failed = ScheduledMessage::factory()
            ->forContact($contact)
            ->failed()
            ->create([
                'meta' => [
                    'campaign_id' => $campaign->id,
                    'campaign_key' => $campaign->key,
                ],
            ]);
        $previouslySkipped = ScheduledMessage::factory()
            ->forContact($contact)
            ->skipped('previous_reason')
            ->create([
                'meta' => [
                    'campaign_id' => $campaign->id,
                    'campaign_key' => $campaign->key,
                ],
            ]);
        $unrelatedPending = ScheduledMessage::factory()
            ->forContact($contact)
            ->create([
                'meta' => [
                    'campaign_key' => 'another_campaign',
                ],
            ]);

        $result = app(DeactivateCampaignAction::class)->handle(
            campaign: $campaign,
            actor: $actor,
            source: 'test',
            meta: ['request_id' => 'request-123'],
        );

        $this->assertTrue($result['status_changed']);
        $this->assertSame(2, $result['enrollments_cancelled']);
        $this->assertSame(2, $result['scheduled_messages_skipped']);

        $campaign->refresh();
        $this->assertSame(Campaign::STATUS_INACTIVE, $campaign->status);
        $this->assertSame('default', data_get($campaign->meta, 'preset.package'));
        $this->assertSame(
            DeactivateCampaignAction::REASON,
            data_get($campaign->meta, 'lifecycle.last_status_change.reason'),
        );
        $this->assertSame('test', data_get($campaign->meta, 'lifecycle.last_status_change.source'));
        $this->assertSame($actor->getMorphClass(), data_get($campaign->meta, 'lifecycle.last_status_change.actor_type'));
        $this->assertSame($actor->getKey(), data_get($campaign->meta, 'lifecycle.last_status_change.actor_id'));

        foreach ([$activeEnrollment, $pausedEnrollment] as $enrollment) {
            $enrollment->refresh();
            $this->assertSame(CampaignEnrollment::STATUS_CANCELLED, $enrollment->status);
            $this->assertSame(
                CampaignEnrollment::EXIT_REASON_CAMPAIGN_DEACTIVATED,
                $enrollment->exit_reason,
            );
            $this->assertNotNull($enrollment->cancelled_at);
            $this->assertNotNull($enrollment->exited_at);
            $this->assertSame(
                DeactivateCampaignAction::REASON,
                data_get($enrollment->meta, 'cancellation.reason'),
            );
            $this->assertTrue((bool) data_get($enrollment->meta, 'cancellation.skipped_pending_messages'));
        }

        $this->assertSame(CampaignEnrollment::STATUS_COMPLETED, $completedEnrollment->refresh()->status);

        foreach ([$pendingById, $pendingByKey] as $message) {
            $message->refresh();
            $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $message->status);
            $this->assertSame(DeactivateCampaignAction::REASON, $message->skip_reason);
        }

        $this->assertSame(ScheduledMessage::STATUS_SENDING, $sending->refresh()->status);
        $this->assertSame(ScheduledMessage::STATUS_SENT, $sent->refresh()->status);
        $this->assertSame(ScheduledMessage::STATUS_FAILED, $failed->refresh()->status);
        $this->assertSame('previous_reason', $previouslySkipped->refresh()->skip_reason);
        $this->assertSame(ScheduledMessage::STATUS_PENDING, $unrelatedPending->refresh()->status);

        $secondResult = app(DeactivateCampaignAction::class)->handle(
            campaign: $campaign,
            source: 'test',
        );

        $this->assertFalse($secondResult['status_changed']);
        $this->assertSame(0, $secondResult['enrollments_cancelled']);
        $this->assertSame(0, $secondResult['scheduled_messages_skipped']);
    }

    public function test_reactivation_allows_future_enrollments_without_resurrecting_cancelled_work(): void
    {
        $campaign = Campaign::factory()->create([
            'key' => 'reactivatable_campaign',
            'status' => Campaign::STATUS_ACTIVE,
        ]);
        $contact = Contact::factory()->create();
        $enrollment = $this->enrollment(
            campaign: $campaign,
            contact: $contact,
            status: CampaignEnrollment::STATUS_ACTIVE,
        );
        $message = ScheduledMessage::factory()
            ->forContact($contact)
            ->create([
                'meta' => [
                    'campaign_id' => $campaign->id,
                    'campaign_key' => $campaign->key,
                    'campaign_enrollment_id' => $enrollment->id,
                ],
            ]);

        app(DeactivateCampaignAction::class)->handle($campaign, source: 'test');
        $result = app(ActivateCampaignAction::class)->handle($campaign, source: 'test');

        $this->assertTrue($result['status_changed']);
        $this->assertSame(Campaign::STATUS_ACTIVE, $campaign->refresh()->status);
        $this->assertSame(CampaignEnrollment::STATUS_CANCELLED, $enrollment->refresh()->status);
        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $message->refresh()->status);
        $this->assertSame(
            ActivateCampaignAction::REASON,
            data_get($campaign->meta, 'lifecycle.last_status_change.reason'),
        );
    }

    public function test_archived_campaign_cannot_be_reactivated(): void
    {
        $campaign = Campaign::factory()->create([
            'status' => Campaign::STATUS_ARCHIVED,
        ]);

        $this->expectExceptionMessage(
            "Archived Campaign [{$campaign->key}] cannot be activated."
        );

        app(ActivateCampaignAction::class)->handle($campaign, source: 'test');
    }

    private function enrollment(
        Campaign $campaign,
        Contact $contact,
        string $status,
    ): CampaignEnrollment {
        return CampaignEnrollment::query()->create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'campaign_key' => $campaign->key,
            'status' => $status,
            'current_step' => 1,
            'started_at' => now(),
            'completed_at' => $status === CampaignEnrollment::STATUS_COMPLETED ? now() : null,
            'meta' => [],
        ]);
    }
}