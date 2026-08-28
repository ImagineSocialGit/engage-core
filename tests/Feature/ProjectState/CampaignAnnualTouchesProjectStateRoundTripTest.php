<?php

namespace Tests\Feature\ProjectState;

use App\Modules\Campaigns\Models\CampaignTouchDate;
use App\Modules\Campaigns\Models\CampaignTouchProgram;
use App\Modules\Campaigns\Models\CampaignTouchVariant;
use App\Support\ProjectState\ProjectStateContractRegistry;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignAnnualTouchesProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_campaign_touch_program_contract_is_keyed_independently_from_legacy_campaign_provenance(): void
    {
        $campaigns = app(ProjectStateContractRegistry::class)
            ->configuredSections()['campaigns'];

        $programs = $campaigns['tables']['campaign_touch_programs'];

        $this->assertEquals(['key'], $programs['identity']);
        $this->assertSame(
            'campaigns',
            $programs['references']['campaign_id'],
        );
        $this->assertContains('audience_filter', $programs['json_columns']);
        $this->assertSame(
            (int) config('project_state.sections.campaigns.version'),
            $campaigns['version'],
        );
    }

    public function test_standalone_annual_touch_program_round_trips_with_child_identity_remapping(): void
    {
        $program = CampaignTouchProgram::query()->create([
            'key' => 'past_client_annual_touches',
            'name' => 'Past Client annual touches',
            'audience_type' => CampaignTouchProgram::AUDIENCE_FILTER,
            'audience_key' => null,
            'audience_filter' => [
                'mode' => 'criteria',
                'criteria' => [
                    'tag' => ['VIP'],
                ],
                'contact_ids' => [],
                'exclude' => [
                    'criteria' => [],
                    'contact_ids' => [],
                ],
            ],
            'recurrence' => CampaignTouchProgram::RECURRENCE_ANNUAL,
            'repeat_years' => 10,
            'starts_on' => '2026-01-01',
            'is_active' => true,
            'meta' => [
                'source' => 'project_state_round_trip_test',
            ],
        ]);

        $date = CampaignTouchDate::query()->create([
            'campaign_touch_program_id' => $program->getKey(),
            'key' => 'birthday',
            'name' => 'Birthday',
            'source_type' => CampaignTouchDate::SOURCE_CONTACT_FIELD,
            'source_key' => 'birthday',
            'month' => null,
            'day' => null,
            'offset_days' => 0,
            'send_time' => '09:00:00',
            'sort_order' => 10,
            'is_active' => true,
            'meta' => null,
        ]);

        CampaignTouchVariant::query()->create([
            'campaign_touch_date_id' => $date->getKey(),
            'key' => 'email',
            'name' => 'Email',
            'sort_order' => 10,
            'channel' => 'email',
            'purpose' => CampaignTouchProgram::MESSAGE_PURPOSE,
            'scope' => CampaignTouchProgram::MESSAGE_SCOPE,
            'message_template_preset_id' => null,
            'is_active' => true,
            'meta' => null,
        ]);

        $sourceProgramId = (int) $program->getKey();
        $sourceDateId = (int) $date->getKey();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame(
            (int) config('project_state.sections.campaigns.version'),
            $document['sections']['campaigns']['version'],
        );
        $this->assertSame(
            (int) config('project_state.sections.campaigns.version'),
            $document['contract']['section_versions']['campaigns'],
        );
        $this->assertSame(
            1,
            count($document['sections']['campaigns']['tables']['campaign_touch_programs']),
        );
        $this->assertNull(
            $document['sections']['campaigns']['tables']['campaign_touch_programs'][0]['campaign_id'],
        );
        $this->assertEquals(
            $program->audience_filter,
            $document['sections']['campaigns']['tables']['campaign_touch_programs'][0]['audience_filter'],
        );

        CampaignTouchVariant::query()->delete();
        CampaignTouchDate::query()->delete();
        CampaignTouchProgram::query()->delete();

        CampaignTouchProgram::query()->create([
            'key' => 'target_only_annual_touch_program',
            'name' => 'Target-only annual touch program',
            'audience_type' => CampaignTouchProgram::AUDIENCE_FILTER,
            'audience_key' => null,
            'audience_filter' => [
                'mode' => 'criteria',
                'criteria' => [
                    'tag' => ['VIP'],
                ],
                'contact_ids' => [],
                'exclude' => [
                    'criteria' => [],
                    'contact_ids' => [],
                ],
            ],
            'recurrence' => CampaignTouchProgram::RECURRENCE_ANNUAL,
            'repeat_years' => 1,
            'starts_on' => '2026-01-01',
            'is_active' => false,
        ]);

        $report = $projectState->validate($document);

        $this->assertTrue($report['valid'], implode(' ', $report['errors']));

        $applied = $projectState->import($document);

        $this->assertTrue($applied['applied']);

        $restoredProgram = CampaignTouchProgram::query()
            ->where('key', 'past_client_annual_touches')
            ->firstOrFail();

        $this->assertNotSame(
            $sourceProgramId,
            (int) $restoredProgram->getKey(),
        );
        $this->assertNull($restoredProgram->getAttribute('campaign_id'));
        $this->assertNull($restoredProgram->audience_key);
        $this->assertEquals([
            'mode' => 'criteria',
            'criteria' => [
                'tag' => ['VIP'],
            ],
            'contact_ids' => [],
            'exclude' => [
                'criteria' => [],
                'contact_ids' => [],
            ],
        ], $restoredProgram->audience_filter);
        $this->assertSame(10, $restoredProgram->repeat_years);
        $this->assertTrue($restoredProgram->is_active);

        $restoredDate = CampaignTouchDate::query()
            ->where('campaign_touch_program_id', $restoredProgram->getKey())
            ->where('key', 'birthday')
            ->firstOrFail();

        $this->assertNotSame(
            $sourceDateId,
            (int) $restoredDate->getKey(),
        );
        $this->assertSame(
            $restoredProgram->getKey(),
            $restoredDate->campaign_touch_program_id,
        );

        $this->assertDatabaseHas('campaign_touch_variants', [
            'campaign_touch_date_id' => $restoredDate->getKey(),
            'key' => 'email',
            'channel' => 'email',
            'purpose' => CampaignTouchProgram::MESSAGE_PURPOSE,
            'scope' => CampaignTouchProgram::MESSAGE_SCOPE,
            'message_template_preset_id' => null,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('campaign_touch_programs', [
            'key' => 'target_only_annual_touch_program',
        ]);
    }
}