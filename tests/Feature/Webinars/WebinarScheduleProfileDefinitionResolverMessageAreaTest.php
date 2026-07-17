<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use App\Modules\Webinars\Services\WebinarScheduleProfileDefinitionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarScheduleProfileDefinitionResolverMessageAreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_message_area_blocks_a_matching_active_schedule_profile_item(): void
    {
        Config::set(
            'webinars.message_areas',
            require base_path('config/webinars/message_areas.php'),
        );
        Config::set('webinars.message_areas.confirmation.enabled', false);

        $profile = WebinarScheduleProfile::factory()->create([
            'key' => 'default_profile',
            'status' => WebinarScheduleProfile::STATUS_ACTIVE,
            'is_default' => true,
            'is_active' => true,
        ]);

        WebinarScheduleProfileItem::factory()->create([
            'webinar_schedule_profile_id' => $profile->getKey(),
            'key' => 'email_confirmation',
            'context_key' => 'confirmations',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'message_template_key' => 'confirmation',
            'is_enabled' => true,
            'is_active' => true,
            'timing' => 'immediate',
            'schedule' => null,
        ]);

        $resolved = app(WebinarScheduleProfileDefinitionResolver::class)->applyProfile(
            profile: $profile->fresh('items'),
            definitions: [[
                'key' => 'confirmation',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'message_type' => 'confirmation',
                'dispatch_keys' => ['registration_created'],
            ]],
            dispatchKeys: 'registration_created',
            surface: 'webinar_registrations',
        );

        $this->assertSame([], $resolved);
    }
}
