<?php

namespace Database\Factories;

use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class WebinarScheduleProfileItemFactory extends Factory
{
    protected $model = WebinarScheduleProfileItem::class;

    public function definition(): array
    {
        return [
            'webinar_schedule_profile_id' => WebinarScheduleProfile::factory(),
            'key' => 'email_confirmation',
            'label' => 'Email confirmation',
            'context_key' => 'confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'source_config_path' => null,
            'is_enabled' => true,
            'is_active' => true,
            'sort_order' => 0,
            'timing' => 'immediate',
            'schedule' => null,
            'conditions' => [],
            'meta' => [],
        ];
    }
}
