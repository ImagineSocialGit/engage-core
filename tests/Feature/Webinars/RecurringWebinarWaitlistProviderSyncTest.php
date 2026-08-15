<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Actions\SyncWebinarSeriesFromProviderAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\ProviderWebinarData;
use App\Modules\Webinars\Data\ProviderWebinarSnapshot;
use App\Modules\Webinars\Jobs\NotifyWebinarWaitlistJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use App\Modules\Webinars\Services\WebinarProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class RecurringWebinarWaitlistProviderSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_provider_occurrence_queues_a_recurring_only_availability_pass(): void
    {
        Queue::fake();

        $series = WebinarSeries::factory()->create([
            'title' => 'Homebuyer Game Plan',
        ]);
        WebinarWaitlistSignup::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'notification_mode' => WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING,
            'expires_at' => now()->addYear(),
            'ended_at' => null,
        ]);

        $providerWebinar = new ProviderWebinarData(
            externalId: 'provider-new-123',
            title: 'Homebuyer Game Plan',
            joinUrl: 'https://provider.example.test/join',
            registrationUrl: 'https://provider.example.test/register',
            startsAt: now()->addWeek(),
            endsAt: now()->addWeek()->addHour(),
            timezone: 'America/New_York',
            description: 'Future session',
            meta: [],
        );
        $provider = Mockery::mock(WebinarProvider::class);
        $provider->shouldReceive('listWebinarsByTitle')
            ->once()
            ->with($series->title)
            ->andReturn(ProviderWebinarSnapshot::authoritative([
                $providerWebinar,
            ]));

        $this->mock(
            WebinarProviderManager::class,
            function ($mock) use ($series, $provider): void {
                $mock->shouldReceive('forSeries')
                    ->once()
                    ->withArgs(fn (WebinarSeries $candidate): bool =>
                        $candidate->is($series)
                    )
                    ->andReturn($provider);
            },
        );

        $result = app(SyncWebinarSeriesFromProviderAction::class)->execute($series);
        $webinar = Webinar::query()
            ->where('external_id', 'provider-new-123')
            ->sole();

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);

        Queue::assertPushed(
            NotifyWebinarWaitlistJob::class,
            fn (NotifyWebinarWaitlistJob $job): bool =>
                $job->seriesId === $series->getKey()
                && $job->webinarId === $webinar->getKey()
                && $job->notificationMode === WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING,
        );
    }
}