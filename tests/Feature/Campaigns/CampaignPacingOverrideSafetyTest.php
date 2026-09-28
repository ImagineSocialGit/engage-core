<?php

namespace Tests\Feature\Campaigns;

use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Services\CampaignPacingOverrideService;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignPacingOverrideSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_hundred_ready_emails_are_spaced_across_the_last_hour(): void
    {
        Bus::fake();
        Carbon::setTestNow('2026-09-23 21:00:00 UTC');

        try {
            $campaign = $this->spreadCampaign();
            $pattern = $campaign->send_pattern;

            foreach (range(1, 100) as $index) {
                $this->pendingEmail($campaign, now()->addDay(), now()->subMinute());
            }

            $service = app(CampaignPacingOverrideService::class);
            $this->assertSame(100, $service->preview($campaign)['candidate_count']);
            $this->assertSame(100, $service->apply(
                $campaign,
                User::factory()->create(),
                100,
                (string) Str::uuid(),
            )['rescheduled']);

            $times = ScheduledMessage::query()->orderBy('send_at')->pluck('send_at');
            $this->assertCount(100, $times);
            $this->assertSame('2026-09-23 21:00:00', Carbon::parse($times->first())->utc()->format('Y-m-d H:i:s'));
            $this->assertTrue(Carbon::parse($times->last())->utc()->lt(Carbon::parse('2026-09-23 22:00:00 UTC')));

            for ($index = 1; $index < $times->count(); $index++) {
                $gap = Carbon::parse($times[$index - 1])->diffInSeconds(Carbon::parse($times[$index]));
                $this->assertGreaterThanOrEqual(1, $gap);
                $this->assertLessThanOrEqual(40, $gap);
            }

            $this->assertSame(100, ScheduledMessage::query()->whereNotNull('manual_schedule_override_at')->count());
            $this->assertEqualsCanonicalizing($pattern, $campaign->refresh()->send_pattern);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_future_due_held_individually_adjusted_and_other_campaign_emails_are_untouched(): void
    {
        Bus::fake();
        Carbon::setTestNow('2026-09-23 21:00:00 UTC');

        try {
            $campaign = $this->spreadCampaign();
            $ready = $this->pendingEmail($campaign, now()->addDay(), now()->subMinute());
            $future = $this->pendingEmail($campaign, now()->addDay(), now()->addHour());
            $held = $this->pendingEmail($campaign, now()->addDay(), now()->subMinute(), [
                'operational_state' => ScheduledMessage::OPERATIONAL_HELD,
            ]);
            $adjusted = $this->pendingEmail($campaign, now()->addDay(), now()->subMinute(), [
                'manual_schedule_override_at' => now()->subMinute(),
            ]);
            $other = $this->pendingEmail($this->spreadCampaign(), now()->addDay(), now()->subMinute());
            $service = app(CampaignPacingOverrideService::class);

            $this->assertSame(1, $service->preview($campaign)['candidate_count']);
            $this->assertSame(1, $service->apply(
                $campaign,
                User::factory()->create(),
                5,
                (string) Str::uuid(),
            )['rescheduled']);
            $this->assertSame('2026-09-23 21:00:00', $ready->refresh()->send_at->utc()->format('Y-m-d H:i:s'));

            foreach ([$future, $held, $adjusted, $other] as $message) {
                $this->assertSame('2026-09-24 21:00:00', $message->refresh()->send_at->utc()->format('Y-m-d H:i:s'));
            }

            $this->assertNull($future->manual_schedule_override_at);
            $this->assertNull($held->manual_schedule_override_at);
            $this->assertNotNull($adjusted->manual_schedule_override_at);
            $this->assertNull($other->manual_schedule_override_at);
            $this->assertSame(0, $service->preview($campaign)['candidate_count']);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function spreadCampaign(): Campaign
    {
        return Campaign::factory()->create([
            'send_pattern' => [
                'mode' => 'spread',
                'daily_limit' => 1,
                'days_of_week' => [1, 2, 3, 4, 5],
                'window_start' => '09:00',
                'window_end' => '17:00',
                'timezone' => 'America/Chicago',
            ],
        ]);
    }

    private function pendingEmail(Campaign $campaign, Carbon $sendAt, Carbon $requestedAt, array $changes = []): ScheduledMessage
    {
        $contact = Contact::factory()->create();
        $enrollment = CampaignEnrollment::query()->create([
            'campaign_id' => $campaign->getKey(),
            'contact_id' => $contact->getKey(),
            'campaign_key' => $campaign->key,
            'started_at' => now(),
        ]);

        return ScheduledMessage::factory()->forContact($contact)->email()->create(array_replace([
            'context_type' => $enrollment->getMorphClass(),
            'context_id' => $enrollment->getKey(),
            'purpose' => 'marketing',
            'send_at' => $sendAt,
            'meta' => ['planning_requested_at' => $requestedAt->toISOString()],
        ], $changes));
    }
}