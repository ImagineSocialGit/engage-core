<?php

namespace Database\Factories;

use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignStepVariant>
 */
class CampaignStepVariantFactory extends Factory
{
    protected $model = CampaignStepVariant::class;

    public function definition(): array
    {
        return [
            'campaign_step_id' => CampaignStep::factory(),
            'key' => 'email',
            'name' => 'Email',
            'sort_order' => 0,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'is_active' => true,
            'criteria' => [],
            'dependency_rules' => [],
            'source_config_path' => null,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ];
    }
}
