<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\CheckCampaignAudienceCompletionAction;
use App\Modules\Campaigns\Contracts\CampaignAudienceCompletionNotifier;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CampaignAudienceCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_audience_alerts_once_after_it_settles_and_rearms_for_new_eligible_leads(): void
    {
        config()->set('modules.enabled', ['campaigns', 'messaging']);
        $clock = Carbon::parse('2026-09-28 12:00:00 UTC');
        $notifier = new RecordingCampaignAudienceCompletionNotifier;
        $this->app->instance(CampaignAudienceCompletionNotifier::class, $notifier);
        [$campaign, $version] = $this->campaign();
        $check = app(CheckCampaignAudienceCompletionAction::class);

        $check->handle($clock);
        $this->assertSame([], $notifier->calls);

        $first = Contact::factory()->create(['source' => 'referral']);
        $enrollment = $this->enroll($campaign, $version, $first, MessageChainEnrollment::STATUS_ACTIVE);
        $check->handle($clock);
        $this->assertSame([], $notifier->calls);

        $enrollment->forceFill(['status' => MessageChainEnrollment::STATUS_COMPLETED])->save();
        $check->handle($clock);
        $check->handle($clock->copy()->addMinutes(14));
        $this->assertSame([], $notifier->calls);
        $check->handle($clock->copy()->addMinutes(16));
        $check->handle($clock->copy()->addMinutes(31));
        $this->assertSame([[1, 1]], $notifier->calls);

        $second = Contact::factory()->create(['source' => 'referral']);
        $check->handle($clock->copy()->addMinutes(32));
        $this->assertNull(data_get($campaign->fresh()->meta, 'audience_completion.notified_at'));
        $this->assertSame([[1, 1]], $notifier->calls);

        $this->enroll($campaign, $version, $second, MessageChainEnrollment::STATUS_COMPLETED);
        $check->handle($clock->copy()->addMinutes(33));
        $check->handle($clock->copy()->addMinutes(49));
        $this->assertSame([[1, 1], [2, 2]], $notifier->calls);

        $this->enroll(
            $campaign,
            $version,
            Contact::factory()->create(['source' => 'referral']),
            MessageChainEnrollment::STATUS_COMPLETED,
        );
        $check->handle($clock->copy()->addMinutes(50));
        $check->handle($clock->copy()->addMinutes(66));
        $this->assertSame([[1, 1], [2, 2], [3, 3]], $notifier->calls);
    }

    public function test_completed_leads_still_count_when_a_received_tag_changes_the_current_filter(): void
    {
        config()->set('modules.enabled', ['campaigns', 'messaging']);
        $notifier = new RecordingCampaignAudienceCompletionNotifier;
        $this->app->instance(CampaignAudienceCompletionNotifier::class, $notifier);
        [$campaign, $version] = $this->campaign();
        $first = Contact::factory()->create(['source' => 'referral']);
        $this->enroll($campaign, $version, $first, MessageChainEnrollment::STATUS_COMPLETED);
        $first->forceFill(['source' => 'received_campaign'])->save();

        $clock = Carbon::parse('2026-09-28 12:00:00 UTC');
        $check = app(CheckCampaignAudienceCompletionAction::class);
        $check->handle($clock);
        $check->handle($clock->copy()->addMinutes(16));

        $this->assertSame([[1, 1]], $notifier->calls);
    }

    public function test_alert_waits_for_a_real_notification_recipient_and_retries_when_one_is_configured(): void
    {
        config()->set('modules.enabled', ['campaigns', 'messaging']);
        $notifier = new RecordingCampaignAudienceCompletionNotifier;
        $notifier->available = false;
        $this->app->instance(CampaignAudienceCompletionNotifier::class, $notifier);
        [$campaign, $version] = $this->campaign();
        $this->enroll(
            $campaign,
            $version,
            Contact::factory()->create(['source' => 'referral']),
            MessageChainEnrollment::STATUS_COMPLETED,
        );

        $clock = Carbon::parse('2026-09-28 12:00:00 UTC');
        $check = app(CheckCampaignAudienceCompletionAction::class);
        $check->handle($clock);
        $check->handle($clock->copy()->addMinutes(16));
        $this->assertNull(data_get($campaign->fresh()->meta, 'audience_completion.notified_at'));

        $notifier->available = true;
        $check->handle($clock->copy()->addMinutes(17));
        $this->assertSame([[1, 1], [1, 1]], $notifier->calls);
        $this->assertNotNull(data_get($campaign->fresh()->meta, 'audience_completion.notified_at'));
    }

    /** @return array{Campaign, MessageChainVersion} */
    private function campaign(): array
    {
        $campaign = Campaign::factory()->create([
            'status' => Campaign::STATUS_ACTIVE,
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_AUTOMATIC,
            'eligibility_filter' => ['source' => ['referral']],
            'meta' => [],
        ]);
        $chain = MessageChain::query()->create([
            'key' => 'campaign.audience.'.$campaign->getKey(),
            'name' => 'Audience completion fixture',
            'status' => MessageChain::STATUS_ACTIVE,
        ]);
        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'content_hash' => hash('sha256', (string) $campaign->getKey()),
            'published_at' => now(),
        ]);
        $chain->forceFill(['current_version_id' => $version->getKey()])->save();
        $campaign->forceFill(['message_chain_id' => $chain->getKey()])->save();

        return [$campaign, $version];
    }

    private function enroll(
        Campaign $campaign,
        MessageChainVersion $version,
        Contact $contact,
        string $status,
    ): MessageChainEnrollment {
        $runtime = MessageChainEnrollment::query()->create([
            'message_chain_version_id' => $version->getKey(),
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'origin_type' => $campaign->getMorphClass(),
            'origin_id' => $campaign->getKey(),
            'surface' => 'campaigns',
            'status' => $status,
            'started_at' => now(),
        ]);
        CampaignEnrollment::query()->create([
            'contact_id' => $contact->getKey(),
            'campaign_id' => $campaign->getKey(),
            'campaign_key' => $campaign->key,
            'message_chain_enrollment_id' => $runtime->getKey(),
            'started_at' => now(),
        ]);

        return $runtime;
    }
}

final class RecordingCampaignAudienceCompletionNotifier implements CampaignAudienceCompletionNotifier
{
    public bool $available = true;

    /** @var array<int, array{int, int}> */
    public array $calls = [];

    public function notify(Campaign $campaign, int $completedCount, int $cycle): bool
    {
        $this->calls[] = [$completedCount, $cycle];

        return $this->available;
    }
}