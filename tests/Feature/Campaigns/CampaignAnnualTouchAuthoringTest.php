<?php

namespace Tests\Feature\Campaigns;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignTouchProgram;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignAnnualTouchAuthoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaigns_exposes_and_saves_recurring_annual_touch_authoring(): void
    {
        config()->set('modules.enabled', ['campaigns', 'messaging']);

        $user = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'status' => Campaign::STATUS_ACTIVE,
            'purpose' => 'marketing',
            'scope' => 'mortgage_past_client',
        ]);
        ContactStatus::query()->create([
            'key' => 'past_client',
            'name' => 'Past Client',
            'is_core' => true,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $email = MessageTemplatePreset::factory()->create([
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'mortgage_past_client',
            'status' => MessageTemplatePreset::STATUS_ACTIVE,
            'is_active' => true,
        ]);
        $sms = MessageTemplatePreset::factory()->create([
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'mortgage_past_client',
            'status' => MessageTemplatePreset::STATUS_ACTIVE,
            'is_active' => true,
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get(route('crm.campaigns.annual-touches.index'))
            ->assertOk()
            ->assertSee('Have recurring annual touch-base dates')
            ->assertSee('Past Client');

        $response = $this->actingAs($user)->post(
            route('crm.campaigns.annual-touches.store'),
            [
                'campaign_id' => $campaign->getKey(),
                'audience_key' => 'past_client',
                'repeat_years' => 10,
                'starts_on' => '2026-08-22',
                'is_active' => 1,
                'touches' => [
                    [
                        'name' => 'Birthday',
                        'source_type' => 'birthday',
                        'send_time' => '09:00',
                        'email_template_preset_id' => $email->getKey(),
                        'sms_template_preset_id' => $sms->getKey(),
                    ],
                    [
                        'name' => 'Christmas',
                        'source_type' => 'fixed_date',
                        'month' => 12,
                        'day' => 25,
                        'send_time' => '10:00',
                        'email_template_preset_id' => $email->getKey(),
                    ],
                ],
            ],
        );

        $program = CampaignTouchProgram::query()->firstOrFail();

        $response->assertRedirect(
            route('crm.campaigns.annual-touches.index', ['edit' => $program->getKey()]),
        );

        $this->assertSame('past_client', $program->audience_key);
        $this->assertSame(10, $program->repeat_years);
        $this->assertTrue($program->is_active);
        $this->assertSame(2, $program->touchDates()->where('is_active', true)->count());
        $this->assertDatabaseHas('campaign_touch_dates', [
            'campaign_touch_program_id' => $program->getKey(),
            'source_type' => 'contact_field',
            'source_key' => 'birthday',
        ]);
        $this->assertDatabaseHas('campaign_touch_dates', [
            'campaign_touch_program_id' => $program->getKey(),
            'source_type' => 'fixed_date',
            'month' => 12,
            'day' => 25,
        ]);
        $this->assertDatabaseHas('campaign_touch_variants', [
            'channel' => 'email',
            'message_template_preset_id' => $email->getKey(),
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('campaign_touch_variants', [
            'channel' => 'sms',
            'message_template_preset_id' => $sms->getKey(),
            'is_active' => true,
        ]);
    }
}