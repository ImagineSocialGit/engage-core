<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\UpdateCampaignSendPatternAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ScheduleMessageAction;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CampaignSendPatternTest extends TestCase
{
    use RefreshDatabase;

    public function test_spread_pattern_caps_and_spaces_new_campaign_marketing_emails(): void
    {
        Bus::fake();
        Carbon::setTestNow('2026-09-23 13:00:00 UTC');

        $campaign = Campaign::factory()->create([
            'send_pattern' => [
                'mode' => 'spread',
                'daily_limit' => 2,
                'days_of_week' => [3, 4],
                'window_start' => '09:00',
                'window_end' => '10:00',
                'timezone' => 'America/Chicago',
            ],
        ]);

        $messages = collect();

        foreach (range(1, 3) as $index) {
            $contact = Contact::factory()->create();
            $enrollment = CampaignEnrollment::query()->create([
                'contact_id' => $contact->getKey(),
                'campaign_id' => $campaign->getKey(),
                'campaign_key' => $campaign->key,
                'start_context' => [],
                'dedupe_key' => 'send-pattern-enrollment-'.$index,
                'started_at' => now(),
                'meta' => [],
            ]);

            $messages->push(
                app(ScheduleMessageAction::class)->handle(
                    recipient: $contact,
                    channel: 'email',
                    purpose: 'marketing',
                    scope: 'test_campaign',
                    messageType: 'campaign_message',
                    payloadClass: EmailPayload::class,
                    payload: [
                        'to' => $contact->email,
                        'subject' => 'Campaign',
                        'body' => 'Campaign message.',
                    ],
                    sendAt: Carbon::parse(
                        '2026-09-23 09:00:00',
                        'America/Chicago',
                    ),
                    context: $enrollment,
                    dedupeKey: 'send-pattern-message-'.$index,
                    queue: 'emails',
                ),
            );
        }

        $local = $messages
            ->map(fn (ScheduledMessage $message): string =>
                $message->send_at
                    ->copy()
                    ->timezone('America/Chicago')
                    ->format('Y-m-d H:i:s')
            )
            ->all();

        $this->assertSame([
            '2026-09-23 09:00:00',
            '2026-09-23 09:30:00',
            '2026-09-24 09:00:00',
        ], $local);
    }

    public function test_send_pattern_does_not_delay_transactional_email_or_sms(): void
    {
        Bus::fake();

        $campaign = Campaign::factory()->create([
            'send_pattern' => [
                'mode' => 'spread',
                'daily_limit' => 1,
                'days_of_week' => [1],
                'window_start' => '09:00',
                'window_end' => '10:00',
                'timezone' => 'America/Chicago',
            ],
        ]);
        $contact = Contact::factory()->create();
        $enrollment = CampaignEnrollment::query()->create([
            'contact_id' => $contact->getKey(),
            'campaign_id' => $campaign->getKey(),
            'campaign_key' => $campaign->key,
            'start_context' => [],
            'dedupe_key' => 'non-marketing-pattern-enrollment',
            'started_at' => now(),
            'meta' => [],
        ]);
        $requested = Carbon::parse('2026-09-23 14:15:00 UTC');

        $transactional = app(ScheduleMessageAction::class)->handle(
            recipient: $contact,
            channel: 'email',
            purpose: 'transactional',
            scope: 'test_campaign',
            messageType: 'transactional',
            payloadClass: EmailPayload::class,
            payload: [
                'to' => $contact->email,
                'subject' => 'Transactional',
                'body' => 'Transactional message.',
            ],
            sendAt: $requested,
            context: $enrollment,
            dedupeKey: 'transactional-pattern-message',
            queue: 'emails',
        );

        $this->assertTrue($transactional->send_at->equalTo($requested));
    }

    public function test_update_action_persists_user_authored_send_pattern(): void
    {
        $campaign = Campaign::factory()->create([
            'is_customized' => false,
            'customized_at' => null,
        ]);

        app(UpdateCampaignSendPatternAction::class)->handle(
            campaign: $campaign,
            pattern: [
                'mode' => 'spread',
                'daily_limit' => 125,
                'days_of_week' => [1, 2, 3, 4, 5],
                'window_start' => '09:00',
                'window_end' => '16:30',
                'timezone' => 'America/Chicago',
            ],
        );

        $campaign->refresh();

        $this->assertSame('spread', $campaign->send_pattern['mode']);
        $this->assertSame(125, $campaign->send_pattern['daily_limit']);
        $this->assertSame(
            [1, 2, 3, 4, 5],
            $campaign->send_pattern['days_of_week'],
        );
        $this->assertTrue($campaign->is_customized);
        $this->assertNotNull($campaign->customized_at);
    }
}