<?php

namespace Tests\Feature\Campaigns;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Actions\ProcessDueCampaignTouchDatesAction;
use App\Modules\Campaigns\Models\CampaignTouchProgram;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Messaging\Actions\CreateReusableMessageTemplateAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
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
        $email = $this->reusablePreset('Birthday Email', 'email');
        $sms = $this->reusablePreset('Birthday SMS', 'sms');
        $campaignStepTemplate = MessageTemplatePreset::factory()->create([
            'name' => 'Cold Lead Nurture — Step 7 Email',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'mortgage_homebuyer_nurture',
            'status' => MessageTemplatePreset::STATUS_ACTIVE,
            'is_active' => true,
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get(route('crm.campaigns.annual-touches.index'))
            ->assertOk()
            ->assertSee('Have recurring annual touch-base dates')
            ->assertSee('Past Client')
            ->assertSee('Birthday Email')
            ->assertSee('Birthday SMS')
            ->assertDontSee($campaignStepTemplate->name);

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

    public function test_annual_touch_surface_can_create_contextual_message_templates_without_leaving_the_form(): void
    {
        config()->set('modules.enabled', ['campaigns', 'messaging']);

        $user = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'key' => 'past_client_nurture',
            'name' => 'Past Client Nurture',
            'status' => Campaign::STATUS_ACTIVE,
            'purpose' => 'marketing',
            'scope' => 'mortgage_past_client',
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $emailResponse = $this->actingAs($user)->postJson(
            route('crm.campaigns.annual-touches.message-templates.store'),
            [
                'campaign_id' => $campaign->getKey(),
                'channel' => 'email',
                'name' => 'Birthday Greeting',
                'subject' => 'Happy birthday',
                'body' => 'Hope you have a great birthday.',
            ],
        );

        $emailResponse
            ->assertCreated()
            ->assertJson([
                'name' => 'Birthday Greeting',
                'channel' => 'email',
            ]);

        $smsResponse = $this->actingAs($user)->postJson(
            route('crm.campaigns.annual-touches.message-templates.store'),
            [
                'campaign_id' => $campaign->getKey(),
                'channel' => 'sms',
                'name' => 'Birthday Text',
                'message' => 'Happy birthday! Hope you have a great day.',
            ],
        );

        $smsResponse
            ->assertCreated()
            ->assertJson([
                'name' => 'Birthday Text',
                'channel' => 'sms',
            ]);

        $emailPreset = MessageTemplatePreset::query()
            ->where('name', 'Birthday Greeting')
            ->sole();
        $emailTemplate = MessageTemplate::query()
            ->where('key', $emailPreset->key)
            ->sole();
        $emailCatalog = MessageTemplateCatalogEntry::query()
            ->where('message_template_preset_id', $emailPreset->getKey())
            ->sole();

        $this->assertSame('marketing', $emailPreset->purpose);
        $this->assertSame('mortgage_past_client', $emailPreset->scope);
        $this->assertEquals([ProcessDueCampaignTouchDatesAction::DISPATCH_KEY], $emailPreset->dispatch_keys);
        $this->assertSame('campaigns', $emailCatalog->module_key);
        $this->assertSame('campaigns', $emailCatalog->surface);
        $this->assertSame('campaign_annual_touch', $emailCatalog->usage_type);
        $this->assertSame($campaign->getMorphClass(), $emailCatalog->context_type);
        $this->assertSame($campaign->getKey(), $emailCatalog->context_id);
        $this->assertEquals(
            ['campaign_annual_touch'],
            data_get($emailCatalog->meta, 'authoring.selection_contexts'),
        );
        $this->assertEquals([
            'subject' => 'Happy birthday',
            'body' => 'Hope you have a great birthday.',
        ], $emailTemplate->currentPayload());

        $this->actingAs($user)
            ->get(route('crm.campaigns.annual-touches.index', ['campaign' => $campaign->getKey()]))
            ->assertOk()
            ->assertSee('Birthday Greeting')
            ->assertSee('Birthday Text')
            ->assertSee('+ Add new message');
    }

    public function test_annual_touch_authoring_rejects_lifecycle_owned_templates_as_new_selections(): void
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
        $campaignStepTemplate = MessageTemplatePreset::factory()->create([
            'name' => 'Cold Lead Nurture — Step 7 Email',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'mortgage_homebuyer_nurture',
            'status' => MessageTemplatePreset::STATUS_ACTIVE,
            'is_active' => true,
        ]);

        MessageTemplateCatalogEntry::factory()->forPreset($campaignStepTemplate)->create([
            'module_key' => 'campaigns',
            'module_label' => 'Campaigns',
            'surface' => 'campaigns',
            'group_key' => 'campaign:cold_lead_nurture',
            'group_label' => 'Cold Lead Nurture',
            'item_key' => 'campaign.cold_lead_nurture.step.7.email',
            'item_label' => 'Step 7 Email',
            'usage_type' => 'campaign_step',
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->post(route('crm.campaigns.annual-touches.store'), [
                'campaign_id' => $campaign->getKey(),
                'audience_key' => 'past_client',
                'repeat_years' => 10,
                'is_active' => 1,
                'touches' => [[
                    'name' => 'Birthday',
                    'source_type' => 'birthday',
                    'send_time' => '09:00',
                    'email_template_preset_id' => $campaignStepTemplate->getKey(),
                ]],
            ])
            ->assertSessionHasErrors(['touches.0.email_template_preset_id']);

        $this->assertSame(0, CampaignTouchProgram::query()->count());
    }

    private function reusablePreset(string $name, string $channel): MessageTemplatePreset
    {
        $preset = MessageTemplatePreset::factory()->create([
            'name' => $name,
            'channel' => $channel,
            'purpose' => 'marketing',
            'scope' => 'mortgage_past_client',
            'source' => CreateReusableMessageTemplateAction::SOURCE,
            'status' => MessageTemplatePreset::STATUS_ACTIVE,
            'is_active' => true,
        ]);

        MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'module_key' => 'broadcasts',
                'module_label' => 'Broadcasts',
                'surface' => 'broadcasts',
                'group_key' => 'saved_broadcast_messages_'.$channel,
                'group_label' => 'Saved Broadcast Messages — '.($channel === 'sms' ? 'SMS' : 'Email'),
                'item_key' => $preset->key,
                'item_label' => $name,
                'usage_type' => 'broadcast_reuse',
                'is_active' => true,
                'meta' => null,
            ]);

        return $preset;
    }
}