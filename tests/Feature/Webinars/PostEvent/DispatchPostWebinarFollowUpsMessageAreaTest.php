<?php

namespace Tests\Feature\Webinars\PostEvent;

use App\Modules\Webinars\Actions\PostEvent\DispatchPostWebinarFollowUpsAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Models\Webinar;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class DispatchPostWebinarFollowUpsMessageAreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabling_transactional_follow_ups_does_not_disable_the_webinar_ended_automation_event(): void
    {
        Config::set(
            'webinars.message_areas',
            require base_path('config/webinars/message_areas.php'),
        );
        Config::set('webinars.message_areas.post_attended.enabled', false);
        Config::set('webinars.message_areas.post_missed.enabled', false);
        Config::set(
            'webinars.post_event',
            require base_path('config/webinars/post_event.php'),
        );

        Event::fake([AutomationEventRecorded::class]);

        $webinar = Webinar::factory()->create([
            'ends_at' => now()->subMinute(),
            'meta' => [],
        ]);
        $provider = Mockery::mock(WebinarProvider::class);
        $provider->shouldReceive('key')->once()->andReturn('test');

        $result = app(DispatchPostWebinarFollowUpsAction::class)->execute(
            provider: $provider,
            webinar: $webinar,
            event: 'webinar.recording_completed',
        );

        $this->assertTrue($result);
        Event::assertDispatched(AutomationEventRecorded::class);
        $this->assertNotNull(
            data_get($webinar->fresh()->meta, 'automation_events.webinar_ended_recorded_at'),
        );
        $this->assertNull(
            data_get($webinar->fresh()->meta, 'normalized.post_event.follow_ups_dispatched_at'),
        );
    }
}
