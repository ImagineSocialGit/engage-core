<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Actions\SyncWebinarScheduleProfilesAction;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CoreWebinarScheduleProfileExtractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_schedule_profiles_include_a_selectable_standard_message_plan(): void
    {
        $root = require base_path('config/webinars.php');
        $profiles = require base_path('config/webinars/schedule_profiles.php');
        $standard = $profiles[WebinarScheduleProfile::STANDARD_KEY] ?? null;

        $this->assertArrayNotHasKey('schedule_profiles', $root);
        $this->assertSame($profiles, config('webinars.schedule_profiles'));
        $this->assertIsArray($standard);
        $this->assertTrue((bool) ($standard['is_active'] ?? false));
        $this->assertSame('default', $standard['message_template_set_key'] ?? null);

        $contexts = collect($standard['items'] ?? [])
            ->where('is_active', true)
            ->where('is_enabled', true)
            ->pluck('context_key')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing([
            'confirmation',
            'reminders',
            'waitlist',
            'post_attended',
            'post_missed',
        ], $contexts);

        Config::set(
            'webinars.message_areas',
            require base_path('config/webinars/message_areas.php'),
        );
        Config::set('webinars.schedule_profiles', $profiles);

        $result = app(SyncWebinarScheduleProfilesAction::class)->handle();
        $expectedItemCount = collect($profiles)
            ->sum(fn (array $profile): int => count($profile['items'] ?? []));

        $this->assertSame(count($profiles), $result['profiles_created']);
        $this->assertSame($expectedItemCount, $result['items_created']);
        $this->assertDatabaseHas('webinar_schedule_profiles', [
            'key' => WebinarScheduleProfile::STANDARD_KEY,
            'message_template_set_key' => 'default',
            'is_active' => true,
        ]);
    }
}