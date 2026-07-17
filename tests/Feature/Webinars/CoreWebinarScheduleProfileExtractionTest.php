<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Actions\SyncWebinarScheduleProfilesAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CoreWebinarScheduleProfileExtractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_schedule_profile_is_loaded_and_synced_from_the_dedicated_config_file(): void
    {
        $root = require base_path('config/webinars.php');
        $profiles = require base_path('config/webinars/schedule_profiles.php');

        $this->assertArrayNotHasKey('schedule_profiles', $root);
        $this->assertSame($profiles, config('webinars.schedule_profiles'));
        $this->assertArrayHasKey('full_10_day', $profiles);
        $this->assertTrue($profiles['full_10_day']['is_default']);
        $this->assertTrue($profiles['full_10_day']['is_active']);
        $this->assertCount(16, $profiles['full_10_day']['items']);
        $this->assertSame(
            'messaging.email.definitions.transactional.webinar.confirmations.0',
            $profiles['full_10_day']['items'][0]['source_config_path'],
        );

        Config::set(
            'webinars.message_areas',
            require base_path('config/webinars/message_areas.php'),
        );
        Config::set('webinars.schedule_profiles', $profiles);

        $result = app(SyncWebinarScheduleProfilesAction::class)->handle();

        $this->assertSame(1, $result['profiles_created']);
        $this->assertSame(16, $result['items_created']);
        $this->assertDatabaseHas('webinar_schedule_profiles', [
            'key' => 'full_10_day',
            'is_default' => true,
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('webinar_schedule_profile_items', 16);
        $this->assertDatabaseHas('webinar_schedule_profile_items', [
            'key' => 'email_confirmation_delay_15',
            'context_key' => 'confirmation',
            'is_enabled' => true,
            'is_active' => true,
        ]);
    }
}
