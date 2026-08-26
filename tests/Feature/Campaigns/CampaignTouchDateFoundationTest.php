<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Models\CampaignTouchDate;
use App\Modules\Campaigns\Models\CampaignTouchProgram;
use App\Modules\Campaigns\Models\CampaignTouchVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignTouchDateFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_standalone_program_can_store_annual_touch_dates_and_channel_variants(): void
    {
        $program = CampaignTouchProgram::query()->create([
            'key' => 'past_client_annual_touches',
            'name' => 'Past Client annual touches',
            'audience_type' => CampaignTouchProgram::AUDIENCE_CONTACT_STATUS,
            'audience_key' => 'past_client',
            'recurrence' => CampaignTouchProgram::RECURRENCE_ANNUAL,
            'repeat_years' => 10,
        ]);

        $birthday = CampaignTouchDate::query()->create([
            'campaign_touch_program_id' => $program->getKey(),
            'key' => 'birthday',
            'name' => 'Birthday',
            'source_type' => CampaignTouchDate::SOURCE_CONTACT_FIELD,
            'source_key' => 'birthday',
            'send_time' => '09:00:00',
            'sort_order' => 10,
        ]);

        CampaignTouchVariant::query()->create([
            'campaign_touch_date_id' => $birthday->getKey(),
            'key' => 'email',
            'channel' => 'email',
            'purpose' => CampaignTouchProgram::MESSAGE_PURPOSE,
            'scope' => CampaignTouchProgram::MESSAGE_SCOPE,
            'message_template_preset_id' => null,
            'sort_order' => 10,
        ]);

        CampaignTouchVariant::query()->create([
            'campaign_touch_date_id' => $birthday->getKey(),
            'key' => 'sms',
            'channel' => 'sms',
            'purpose' => CampaignTouchProgram::MESSAGE_PURPOSE,
            'scope' => CampaignTouchProgram::MESSAGE_SCOPE,
            'message_template_preset_id' => null,
            'sort_order' => 20,
        ]);

        $holiday = CampaignTouchDate::query()->create([
            'campaign_touch_program_id' => $program->getKey(),
            'key' => 'christmas',
            'name' => 'Christmas',
            'source_type' => CampaignTouchDate::SOURCE_FIXED_DATE,
            'month' => 12,
            'day' => 25,
            'send_time' => '09:00:00',
            'sort_order' => 20,
        ]);

        $loadedProgram = CampaignTouchProgram::query()
            ->with('touchDates.variants')
            ->whereKey($program->getKey())
            ->firstOrFail();

        $this->assertSame('past_client', $loadedProgram->audience_key);
        $this->assertSame(10, $loadedProgram->repeat_years);
        $this->assertSame(2, $loadedProgram->touchDates->count());
        $this->assertEquals(['email', 'sms'], $birthday->variants()->pluck('channel')->all());
        $this->assertSame(12, $holiday->month);
        $this->assertSame(25, $holiday->day);
        $this->assertDatabaseHas('campaign_touch_programs', [
            'id' => $program->getKey(),
            'campaign_id' => null,
        ]);
    }
}