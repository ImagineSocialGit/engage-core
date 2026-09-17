<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ClaimScheduledMessageForSendingAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Webinars\Actions\SyncWebinarSeriesFromProviderAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\ProviderWebinarData;
use App\Modules\Webinars\Data\ProviderWebinarSnapshot;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Services\WebinarProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WebinarResyncReminderTimingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_resync_moves_waiting_and_pending_anchored_reminders_but_preserves_overrides(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-09-17 12:00:00 UTC');
        $oldStart = Carbon::parse('2026-09-20 16:00:00 UTC');
        $newStart = Carbon::parse('2026-09-20 17:00:00 UTC');
        $series = WebinarSeries::factory()->create();
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => $oldStart,
            'ends_at' => $oldStart->copy()->addHour(),
        ]);
        [$step, $variant] = $this->anchoredStep();
        $waiting = $this->enrollment($webinar, $step, $oldStart->copy()->subHour(), 'waiting');
        $materialized = $this->enrollment($webinar, $step, null, 'materialized');
        $overridden = $this->enrollment($webinar, $step, $oldStart->copy()->subMinutes(90), 'override');
        $pending = ScheduledMessage::factory()->create([
            'message_chain_enrollment_id' => $materialized->getKey(),
            'message_chain_step_variant_id' => $variant->getKey(),
            'send_at' => $oldStart->copy()->subHour(),
        ]);
        $manual = ScheduledMessage::factory()->create([
            'message_chain_enrollment_id' => $materialized->getKey(),
            'message_chain_step_variant_id' => $variant->getKey(),
            'send_at' => $oldStart->copy()->subMinutes(90),
        ]);
        $claimed = ScheduledMessage::factory()->sending()->create([
            'message_chain_enrollment_id' => $materialized->getKey(),
            'message_chain_step_variant_id' => $variant->getKey(),
            'send_at' => $oldStart->copy()->subHour(),
        ]);

        $this->providerStartsAt($series, $webinar, $newStart, 'America/Chicago');
        $result = app(SyncWebinarSeriesFromProviderAction::class)->execute($series);

        $this->assertSame([
            'enrollments' => 1,
            'messages' => 1,
            'review_required' => 2,
            'review_enrollment_ids' => [$overridden->getKey()],
            'review_message_ids' => [$manual->getKey()],
        ], $result['message_schedule']);
        $this->assertTrue($waiting->fresh()->next_action_at->equalTo($newStart->copy()->subHour()));
        $this->assertTrue($pending->fresh()->send_at->equalTo($newStart->copy()->subHour()));
        $this->assertTrue($overridden->fresh()->next_action_at->equalTo($oldStart->copy()->subMinutes(90)));
        $this->assertTrue($manual->fresh()->send_at->equalTo($oldStart->copy()->subMinutes(90)));
        $this->assertTrue($claimed->fresh()->send_at->equalTo($oldStart->copy()->subHour()));
        $this->assertNull(app(ClaimScheduledMessageForSendingAction::class)->handle($pending));
        $this->assertSame(ScheduledMessage::STATUS_PENDING, $pending->fresh()->status);
    }

    public function test_timezone_label_change_without_a_changed_instant_does_not_move_reminders(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-09-17 12:00:00 UTC');
        $start = Carbon::parse('2026-09-20 16:00:00 UTC');
        $series = WebinarSeries::factory()->create();
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => $start,
            'timezone' => 'America/New_York',
        ]);
        [$step] = $this->anchoredStep();
        $waiting = $this->enrollment($webinar, $step, $start->copy()->subHour(), 'label');

        $this->providerStartsAt($series, $webinar, $start, 'America/Chicago');
        $result = app(SyncWebinarSeriesFromProviderAction::class)->execute($series);

        $this->assertSame([
            'enrollments' => 0,
            'messages' => 0,
            'review_required' => 0,
            'review_enrollment_ids' => [],
            'review_message_ids' => [],
        ], $result['message_schedule']);
        $this->assertTrue($waiting->fresh()->next_action_at->equalTo($start->copy()->subHour()));
        $this->assertSame('America/Chicago', $webinar->fresh()->timezone);
    }

    /** @return array{MessageChainStep, MessageChainStepVariant} */
    private function anchoredStep(): array
    {
        $template = MessageTemplate::query()->create([
            'key' => 'email.transactional.webinars.resync',
            'name' => 'Resync',
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
        ]);
        $templateVersion = app(PublishMessageTemplateVersionAction::class)->handle(
            $template,
            ['subject' => 'Reminder', 'body' => 'Reminder body.'],
        );
        $chain = MessageChain::query()->create([
            'key' => 'fixture.webinar.resync',
            'name' => 'Webinar resync',
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
        ]);
        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'content_hash' => hash('sha256', 'fixture.webinar.resync'),
            'published_at' => null,
        ]);
        $step = MessageChainStep::query()->create([
            'message_chain_version_id' => $version->getKey(),
            'key' => 'reminder',
            'timing_type' => MessageChainStep::TIMING_ANCHORED,
            'anchor_key' => 'webinar.starts_at',
            'offset_seconds' => -3600,
        ]);
        $variant = MessageChainStepVariant::query()->create([
            'message_chain_step_id' => $step->getKey(),
            'key' => 'email',
            'message_template_version_id' => $templateVersion->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinars',
            'message_type' => 'reminder',
            'queue' => 'emails',
        ]);

        return [$step, $variant];
    }

    private function enrollment(Webinar $webinar, MessageChainStep $step, ?Carbon $due, string $suffix): MessageChainEnrollment
    {
        $contact = Contact::factory()->create();

        return MessageChainEnrollment::query()->create([
            'message_chain_version_id' => $step->message_chain_version_id,
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'origin_type' => $webinar->getMorphClass(),
            'origin_id' => $webinar->getKey(),
            'current_message_chain_step_id' => $step->getKey(),
            'next_action_at' => $due,
            'status' => MessageChainEnrollment::STATUS_ACTIVE,
            'dedupe_key' => 'webinar-resync-'.$suffix,
            'started_at' => now(),
        ]);
    }

    private function providerStartsAt(WebinarSeries $series, Webinar $webinar, Carbon $startsAt, string $timezone): void
    {
        $provider = Mockery::mock(WebinarProvider::class);
        $provider->shouldReceive('listWebinarsByTitle')
            ->once()->with($series->title)
            ->andReturn(ProviderWebinarSnapshot::authoritative([
                new ProviderWebinarData(
                    externalId: $webinar->external_id,
                    title: $webinar->title,
                    joinUrl: $webinar->join_url,
                    registrationUrl: $webinar->registration_url,
                    startsAt: $startsAt,
                    endsAt: $startsAt->copy()->addHour(),
                    timezone: $timezone,
                    description: $webinar->description,
                ),
            ]));
        $this->mock(WebinarProviderManager::class, function ($mock) use ($series, $provider): void {
            $mock->shouldReceive('forSeries')->once()
                ->withArgs(fn (WebinarSeries $candidate): bool => $candidate->is($series))
                ->andReturn($provider);
        });
    }
}