<?php

namespace Database\Factories;

use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        return [
            'key' => 'webinar_attended_nurture_'.uniqid(),
            'name' => 'Webinar Attended Nurture',
            'description' => null,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => Campaign::STATUS_ACTIVE,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ];
    }
}


