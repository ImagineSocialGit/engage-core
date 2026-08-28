<?php

namespace Tests\Feature\Campaigns;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Campaigns\Actions\ProcessDueCampaignTouchDatesAction;
use App\Modules\Campaigns\Models\CampaignTouchProgram;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Messaging\Actions\CreateReusableMessageTemplateAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignAnnualTouchAuthoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_standalone_annual_touch_program_is_authored_for_all_contacts_without_status_or_workflow(): void
    {
        config()->set('modules.enabled', ['campaigns', 'messaging']);

        $user = User::factory()->create();
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

        $index = $this->actingAs($user)
            ->get(route('crm.campaigns.annual-touches.index'));

        $index
            ->assertOk()
            ->assertViewHas('audience', fn (array $audience): bool => isset($audience['modes']['all'])
                && ($audience['mode'] ?? null) === 'all')
            ->assertViewHas('emailTemplates', fn ($templates): bool => $templates->contains('id', $email->getKey())
                && ! $templates->contains('id', $campaignStepTemplate->getKey()))
            ->assertViewHas('smsTemplates', fn ($templates): bool => $templates->contains('id', $sms->getKey()))
            ->assertDontSee('name="campaign_id"', false);

        $response = $this->actingAs($user)->post(
            route('crm.campaigns.annual-touches.store'),
            [
                'audience_mode' => 'all',
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

        $this->assertSame(CampaignTouchProgram::AUDIENCE_FILTER, $program->audience_type);
        $this->assertNull($program->audience_key);
        $this->assertSame('all', data_get($program->audience_filter, 'mode'));
        $this->assertSame(10, $program->repeat_years);
        $this->assertTrue($program->is_active);
        $this->assertSame(2, $program->touchDates()->where('is_active', true)->count());
        $this->assertDatabaseHas('campaign_touch_programs', [
            'id' => $program->getKey(),
            'campaign_id' => null,
        ]);
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

        $editResponse = $this->actingAs($user)
            ->get(route('crm.campaigns.annual-touches.index', ['edit' => $program->getKey()]));

        $editResponse
            ->assertOk()
            ->assertSee('data-template-option-id="'.$email->getKey().'"', false)
            ->assertSee('data-template-option-id="'.$sms->getKey().'"', false)
            ->assertViewHas('annualTouchAvailableFields', function (array $groups): bool {
                $fields = collect($groups)->flatMap(
                    fn (array $group) => $group['fields'] ?? [],
                );

                return $fields->contains(
                    fn (array $field): bool => ($field['token'] ?? null) === 'contact.first_name'
                        && ($field['syntax'] ?? null) === '{first_name}',
                ) && $fields->contains(
                    fn (array $field): bool => ($field['token'] ?? null) === 'contact.birthday'
                        && ($field['syntax'] ?? null) === '{birthday}',
                ) && ! $fields->contains(
                    fn (array $field): bool => str_starts_with((string) ($field['token'] ?? ''), 'campaign.'),
                );
            });
    }

    public function test_audience_preview_uses_registered_contact_filter_conditions(): void
    {
        config()->set('modules.enabled', ['campaigns', 'messaging']);

        $user = User::factory()->create();
        $matching = Contact::query()->create([
            'first_name' => 'Matching',
            'email' => 'matching@example.test',
        ]);
        Contact::query()->create([
            'first_name' => 'Other',
            'email' => 'other@example.test',
        ]);
        ContactTag::query()->create([
            'contact_id' => $matching->getKey(),
            'tag' => 'VIP',
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->postJson(route('crm.campaigns.annual-touches.audience-preview'), [
                'audience_mode' => 'criteria',
                'audience_criteria' => [
                    'tag' => ['VIP'],
                ],
            ])
            ->assertOk()
            ->assertJson([
                'matching_count' => 1,
            ]);
    }

    public function test_annual_touch_surface_creates_standalone_contextual_message_templates(): void
    {
        config()->set('modules.enabled', ['campaigns', 'messaging']);

        $user = User::factory()->create();
        $this->withoutMiddleware(ForceStagingAccess::class);

        $emailResponse = $this->actingAs($user)->postJson(
            route('crm.campaigns.annual-touches.message-templates.store'),
            [
                'channel' => 'email',
                'name' => 'Birthday Greeting',
                'subject' => 'Happy birthday, {first_name}',
                'body' => 'Hi {first_name}, hope you have a great birthday.',
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
                'channel' => 'sms',
                'name' => 'Birthday Text',
                'message' => 'Happy birthday, {first_name}! Hope you have a great day.',
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

        $this->assertSame(CampaignTouchProgram::MESSAGE_PURPOSE, $emailPreset->purpose);
        $this->assertSame(CampaignTouchProgram::MESSAGE_SCOPE, $emailPreset->scope);
        $this->assertEquals([ProcessDueCampaignTouchDatesAction::DISPATCH_KEY], $emailPreset->dispatch_keys);
        $this->assertSame('campaigns', $emailCatalog->module_key);
        $this->assertSame('campaigns', $emailCatalog->surface);
        $this->assertSame('campaign_annual_touch', $emailCatalog->usage_type);
        $this->assertNull($emailCatalog->context_type);
        $this->assertNull($emailCatalog->context_id);
        $this->assertSame('annual_touches:email', $emailCatalog->group_key);
        $this->assertEquals(
            ['campaign_annual_touch'],
            data_get($emailCatalog->meta, 'authoring.selection_contexts'),
        );
        $this->assertEquals([
            'subject' => 'Happy birthday, {first_name}',
            'body' => 'Hi {first_name}, hope you have a great birthday.',
        ], $emailTemplate->currentPayload());
    }

    public function test_annual_touch_template_creation_rejects_campaign_fields_that_runtime_no_longer_supplies(): void
    {
        config()->set('modules.enabled', ['campaigns', 'messaging']);

        $user = User::factory()->create();
        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)->postJson(
            route('crm.campaigns.annual-touches.message-templates.store'),
            [
                'channel' => 'email',
                'name' => 'Invalid Campaign Token',
                'subject' => 'Hello {first_name}',
                'body' => 'From {campaign.name}',
            ],
        )->assertUnprocessable();

        $this->assertDatabaseMissing('message_template_presets', [
            'name' => 'Invalid Campaign Token',
        ]);
    }

    public function test_annual_touch_authoring_rejects_lifecycle_owned_templates_as_new_selections(): void
    {
        config()->set('modules.enabled', ['campaigns', 'messaging']);

        $user = User::factory()->create();
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
                'audience_mode' => 'all',
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