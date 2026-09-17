<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Webinars\Actions\SyncWebinarSeriesFromProviderAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\ProviderWebinarData;
use App\Modules\Webinars\Data\ProviderWebinarSnapshot;
use App\Modules\Webinars\Jobs\ProcessWebinarScheduleChangeJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarScheduleChange;
use App\Modules\Webinars\Services\WebinarProviderManager;
use App\Modules\Webinars\Services\WebinarTimeChangeTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class WebinarTimeChangeAutoSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('modules.enabled', ['core', 'messaging', 'webinars']);
        Config::set('messaging.channel_availability.email.provider_enabled', true);
    }

    public function test_published_ui_copy_and_opt_in_auto_send_pin_version_once_on_resync(): void
    {
        Queue::fake();
        $series = \App\Modules\Webinars\Models\WebinarSeries::factory()->create(['status' => 'active']);
        $webinar = Webinar::factory()->for($series, 'webinarSeries')->create([
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHour(),
        ]);
        $user = User::factory()->create();
        $path = route('crm.webinar-series.time-change-settings.template', $series);

        $this->actingAs($user)->post($path, [
            'channel' => 'email',
            'subject' => '{webinar_title} has a new time',
            'body' => 'Hi {first_name}, {webinar_title} moved from {previous_webinar_time} to {current_webinar_time}.',
        ])->assertRedirect();

        $templates = app(WebinarTimeChangeTemplates::class);
        $original = $templates->version($series, 'email');
        $this->assertNotNull($original);
        $this->actingAs($user)->patch(
            route('crm.webinar-series.time-change-settings.policy', $series),
            ['auto_send' => 1, 'channels' => ['email']],
        )->assertRedirect();
        $this->assertTrue($templates->autoSend($series->fresh()));

        $start = $webinar->starts_at->copy()->addHour();
        $provider = Mockery::mock(WebinarProvider::class);
        $provider->shouldReceive('listWebinarsByTitle')->twice()->with($series->title)
            ->andReturn($this->snapshot($webinar, $start), $this->snapshot($webinar, $start));
        $this->mock(WebinarProviderManager::class, function (MockInterface $mock) use ($provider): void {
            $mock->shouldReceive('forSeries')->twice()->andReturn($provider);
        });

        $sync = app(SyncWebinarSeriesFromProviderAction::class);
        $sync->execute($series);
        $change = WebinarScheduleChange::query()->firstOrFail();
        $this->assertSame(WebinarScheduleChange::STATUS_DISPATCHING, $change->status);
        $this->assertSame('automatic', $change->notification_mode);
        $this->assertSame(['email'], $change->channels);
        $this->assertSame(['email' => $original->getKey()], $change->template_version_ids);
        Queue::assertPushed(ProcessWebinarScheduleChangeJob::class, 1);

        $this->actingAs($user)->post($path, [
            'channel' => 'email',
            'subject' => 'Updated {webinar_title}',
            'body' => 'Hi {first_name}, the webinar changed from {previous_webinar_time} to {current_webinar_time}.',
        ])->assertRedirect();
        $this->assertNotSame($original->getKey(), $templates->version($series, 'email')->getKey());
        $this->assertSame(['email' => $original->getKey()], $change->fresh()->template_version_ids);

        $sync->execute($series);
        $this->assertSame(1, WebinarScheduleChange::query()->count());
        Queue::assertPushed(ProcessWebinarScheduleChangeJob::class, 1);
    }

    public function test_ui_rejects_auto_send_without_published_copy_and_keeps_manual_review(): void
    {
        $series = \App\Modules\Webinars\Models\WebinarSeries::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->patch(
            route('crm.webinar-series.time-change-settings.policy', $series),
            ['auto_send' => 1, 'channels' => ['email']],
        )->assertSessionHasErrors('channels');

        $this->assertFalse(app(WebinarTimeChangeTemplates::class)->autoSend($series->fresh()));
        $this->assertNull(MessageTemplate::query()
            ->where('key', app(WebinarTimeChangeTemplates::class)->key($series, 'email'))->first());
    }

    private function snapshot(Webinar $webinar, $startsAt): ProviderWebinarSnapshot
    {
        return ProviderWebinarSnapshot::authoritative([
            new ProviderWebinarData(
                externalId: $webinar->external_id,
                title: $webinar->title,
                joinUrl: $webinar->join_url,
                registrationUrl: $webinar->registration_url,
                startsAt: $startsAt,
                endsAt: $startsAt->copy()->addHour(),
                timezone: $webinar->timezone,
                description: $webinar->description,
            ),
        ]);
    }
}