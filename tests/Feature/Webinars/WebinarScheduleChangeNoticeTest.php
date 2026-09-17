<?php

namespace Tests\Feature\Webinars;

use App\Modules\Messaging\Actions\ScheduleMessageAction;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\MessageGate;
use App\Modules\Webinars\Actions\ProcessWebinarScheduleChangeAction;
use App\Modules\Webinars\Actions\SyncWebinarSeriesFromProviderAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\ProviderWebinarData;
use App\Modules\Webinars\Data\ProviderWebinarSnapshot;
use App\Modules\Webinars\Jobs\ProcessWebinarScheduleChangeJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarScheduleChange;
use App\Modules\Webinars\Services\WebinarProviderManager;
use App\Modules\Webinars\Services\WebinarScheduleChangeCopy;
use App\Modules\Webinars\Services\WebinarScheduleChangeMessageGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WebinarScheduleChangeNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_resync_records_once_and_supersedes_unsent_old_notices(): void
    {
        Queue::fake();
        $webinar = Webinar::factory()->create([
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHour(),
            'timezone' => 'America/New_York',
        ]);
        $series = $webinar->webinarSeries;
        $newStart = $webinar->starts_at->copy()->addHour();
        $laterStart = $newStart->copy()->addHour();
        $snapshot = function ($startsAt, $timezone) use ($webinar): ProviderWebinarSnapshot {
            return ProviderWebinarSnapshot::authoritative([
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
            ]);
        };
        $provider = Mockery::mock(WebinarProvider::class);
        $provider->shouldReceive('listWebinarsByTitle')->times(3)->with($series->title)
            ->andReturn(
                $snapshot($newStart, 'America/Chicago'),
                $snapshot($newStart, 'America/Chicago'),
                $snapshot($laterStart, 'America/Chicago'),
            );
        $this->mock(WebinarProviderManager::class, function ($mock) use ($provider): void {
            $mock->shouldReceive('forSeries')->times(3)->andReturn($provider);
        });

        $sync = app(SyncWebinarSeriesFromProviderAction::class);
        $sync->execute($series);
        $first = WebinarScheduleChange::query()->firstOrFail();
        $this->assertSame('America/New_York', $first->previous_timezone);
        $this->assertSame('America/Chicago', $first->current_timezone);

        $oldNotice = ScheduledMessage::factory()->create([
            'message_type' => 'webinar_schedule_change',
            'behavior_owner_type' => $first->getMorphClass(),
            'behavior_owner_id' => $first->getKey(),
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);
        $sync->execute($series);
        $this->assertSame(1, WebinarScheduleChange::query()->count());

        $sync->execute($series);
        $this->assertSame(2, WebinarScheduleChange::query()->count());
        $this->assertSame(WebinarScheduleChange::STATUS_SUPERSEDED, $first->fresh()->status);
        $this->assertSame(ScheduledMessage::STATUS_CANCELLED, $oldNotice->fresh()->status);
        $this->assertSame(1, $first->fresh()->messages_cancelled);
    }

    public function test_notice_preserves_previous_and_new_display_zones_and_advances_once(): void
    {
        Queue::fake();
        $webinar = Webinar::factory()->create([
            'title' => 'Getting Started',
            'starts_at' => '2027-07-12 00:00:00',
            'timezone' => 'America/Chicago',
        ]);
        $registration = WebinarRegistration::factory()->create([
            'webinar_id' => $webinar->getKey(),
            'status' => 'confirmed',
            'meta' => ['accepted_channels' => ['transactional' => ['email']]],
        ]);
        $change = WebinarScheduleChange::query()->create([
            'webinar_id' => $webinar->getKey(),
            'previous_starts_at' => '2027-07-11 23:00:00',
            'current_starts_at' => $webinar->starts_at,
            'previous_timezone' => 'America/New_York',
            'current_timezone' => 'America/Chicago',
            'status' => WebinarScheduleChange::STATUS_DISPATCHING,
            'channels' => ['email'],
        ]);
        $expected = 'changed from Jul 11, 2027 at 7:00 PM Eastern to Jul 11, 2027 at 7:00 PM Central';
        $this->mock(MessageChannelAvailability::class, function ($mock): void {
            $mock->shouldReceive('visibleChannelsForSurface')->once()->andReturn(['email']);
        });
        $this->mock(MessageGate::class, function ($mock): void {
            $mock->shouldReceive('allows')->once()->andReturn(true);
        });
        $scheduled = new ScheduledMessage;
        $scheduled->wasRecentlyCreated = true;
        $this->mock(ScheduleMessageAction::class, function ($mock) use ($expected, $registration, $scheduled): void {
            $mock->shouldReceive('handle')->once()->withArgs(
                function (...$args) use ($expected, $registration): bool {
                    return $args[0]->is($registration->contact)
                        && $args[1] === 'email'
                        && str_contains($args[6]['body'], $expected)
                        && str_contains($args[6]['body'], 'Hi '.$registration->contact->first_name.'!')
                        && $args[8]->is($registration);
                },
            )->andReturn($scheduled);
        });

        app(ProcessWebinarScheduleChangeAction::class)->handle($change->getKey());
        $this->assertSame(1, $change->fresh()->messages_queued);
        $this->assertSame($registration->getKey(), $change->fresh()->last_registration_id);
        Queue::assertPushed(ProcessWebinarScheduleChangeJob::class);

        app(ProcessWebinarScheduleChangeAction::class)->handle($change->getKey());
        $this->assertSame(WebinarScheduleChange::STATUS_COMPLETED, $change->fresh()->status);
        $this->assertSame(1, $change->fresh()->messages_queued);
    }

    public function test_outdated_change_cannot_queue_or_send_a_notice(): void
    {
        Queue::fake();
        $webinar = Webinar::factory()->create(['starts_at' => now()->addDays(3)]);
        $registration = WebinarRegistration::factory()->create(['webinar_id' => $webinar->getKey()]);
        $change = WebinarScheduleChange::query()->create([
            'webinar_id' => $webinar->getKey(),
            'previous_starts_at' => now()->addDay(),
            'current_starts_at' => now()->addDays(2),
            'previous_timezone' => 'America/Chicago',
            'current_timezone' => 'America/Chicago',
            'status' => WebinarScheduleChange::STATUS_DISPATCHING,
            'channels' => ['email'],
        ]);

        app(ProcessWebinarScheduleChangeAction::class)->handle($change->getKey());
        $this->assertSame(WebinarScheduleChange::STATUS_SUPERSEDED, $change->fresh()->status);
        Queue::assertNotPushed(ProcessWebinarScheduleChangeJob::class);

        $message = new ScheduledMessage(['message_type' => 'webinar_schedule_change']);
        $message->setRelation('context', $registration);
        $message->setRelation('behaviorOwner', $change->fresh());
        $this->assertSame('webinar_schedule_change_no_longer_current',
            app(WebinarScheduleChangeMessageGate::class)->denialReason(
                $registration->contact, 'email', 'webinar_schedule_change',
                ['scheduled_message' => $message],
            ));
    }

    public function test_copy_formats_each_instant_in_its_own_timezone(): void
    {
        $change = new WebinarScheduleChange([
            'previous_starts_at' => '2027-07-11 23:00:00',
            'current_starts_at' => '2027-07-12 00:00:00',
            'previous_timezone' => 'America/New_York',
            'current_timezone' => 'America/Chicago',
        ]);

        $this->assertSame([
            'previous' => 'Jul 11, 2027 at 7:00 PM Eastern',
            'current' => 'Jul 11, 2027 at 7:00 PM Central',
        ], app(WebinarScheduleChangeCopy::class)->labels($change));
    }
}