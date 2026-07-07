<?php

namespace Database\Factories;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampaignStepFactory extends Factory
{
    protected $model = CampaignStep::class;

    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'step_number' => 1,
            'name' => 'Step 1',
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'is_active' => true,
            'criteria' => [
                'timing' => [
                    'type' => 'delay',
                    'minutes' => 60,
                ],
            ],
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [
                'type' => 'message',
            ],
        ];
    }

    public function forCampaign(Campaign $campaign): static
    {
        return $this->state(fn () => [
            'campaign_id' => $campaign->getKey(),
            'channel' => $campaign->channel,
            'purpose' => $campaign->purpose,
            'scope' => $campaign->scope,
        ]);
    }
}
