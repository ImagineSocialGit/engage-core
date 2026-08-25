<?php

namespace Tests\Feature\Messaging;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplateCompositionLayer;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTemplatePresetControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_exposes_catalog_grouping_and_editable_template_data(): void
    {
        config()->set('modules.enabled', [
            'messaging',
        ]);

        $user = User::factory()->create();

        $preset = MessageTemplatePreset::factory()->create([
            'name' => 'Fixture Template',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'fixture',
            'message_type' => 'fixture_primary',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['fixture_dispatched'],
            'payload' => [
                'subject' => 'Fixture subject {first_name}',
                'body' => 'Fixture body.',
            ],
            'tokens' => [],
            'source_config_path' => 'messaging.email.definitions.transactional.fixture.primary',
        ]);

        MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'module_key' => 'messaging',
                'module_label' => 'Messaging',
                'surface' => 'message_templates',
                'group_key' => 'fixture:transactional:primary',
                'group_label' => 'Fixture Group',
                'item_key' => 'email.transactional.fixture.primary',
                'item_label' => 'Fixture Template',
                'item_order' => 0,
                'usage_type' => 'fixture',
            ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/message-templates')
            ->assertOk()
            ->assertViewIs('crm.messaging.message-templates.index')
            ->assertViewHas(
                'selectedPreset',
                fn (mixed $selectedPreset): bool =>
                    $selectedPreset instanceof MessageTemplatePreset
                    && $selectedPreset->is($preset),
            )
            ->assertViewHas(
                'selectedGroup',
                fn (mixed $selectedGroup): bool =>
                    is_array($selectedGroup)
                    && ($selectedGroup['key'] ?? null) === 'fixture:transactional:primary'
                    && ($selectedGroup['entries'] ?? null) instanceof \Illuminate\Support\Collection
                    && $selectedGroup['entries']->contains(
                        fn (MessageTemplateCatalogEntry $entry): bool =>
                            $entry->messageTemplatePreset?->is($preset) ?? false,
                    ),
            )
            ->assertViewHas(
                'editablePayload',
                fn (mixed $editablePayload): bool =>
                    is_array($editablePayload)
                    && ($editablePayload['subject'] ?? null) === 'Fixture subject {first_name}'
                    && ($editablePayload['body'] ?? null) === 'Fixture body.',
            )
            ->assertViewHas(
                'tokens',
                fn (mixed $tokens): bool =>
                    is_array($tokens)
                    && $tokens === ['first_name'],
            )
            ->assertDontSee('payload_class');
    }

    public function test_it_shows_read_only_usage_for_selected_template(): void
    {
        config()->set('modules.enabled', [
            'campaigns',
            'messaging',
        ]);

        $user = User::factory()->create();

        $preset = MessageTemplatePreset::factory()->create([
            'name' => 'Webinar Attended Nurture — Step 2 Email',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'message_type' => 'webinar_attended_nurture_step_2',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'dispatch_keys' => ['campaign_step_due'],
            'payload' => [
                'subject' => 'Now let’s talk about YOUR VA loan',
                'body' => 'Campaign message body.',
            ],
        ]);

        MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'module_key' => 'campaigns',
                'module_label' => 'Campaigns',
                'surface' => 'campaigns',
                'group_key' => 'campaign:webinar_attended_nurture',
                'group_label' => 'Webinar Attended Nurture',
                'item_key' => 'email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.2.variants.email',
                'item_label' => 'Step 2 Email',
                'item_order' => 2,
                'usage_type' => 'campaign_step',
                'meta' => [
                    'campaign_key' => 'webinar_attended_nurture',
                    'campaign_step' => 2,
                    'campaign_step_variant_key' => 'email',
                ],
            ]);

        $neighborPreset = MessageTemplatePreset::factory()->create([
            'name' => 'Webinar Attended Nurture — Step 3 Email',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'message_type' => 'webinar_attended_nurture_step_3',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'dispatch_keys' => ['campaign_step_due'],
            'payload' => [
                'subject' => 'What to do next after the webinar',
                'body' => 'Neighbor campaign message body.',
            ],
        ]);

        MessageTemplateCatalogEntry::factory()
            ->forPreset($neighborPreset)
            ->create([
                'module_key' => 'campaigns',
                'module_label' => 'Campaigns',
                'surface' => 'campaigns',
                'group_key' => 'campaign:webinar_attended_nurture',
                'group_label' => 'Webinar Attended Nurture',
                'item_key' => 'email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.3.variants.email',
                'item_label' => 'Step 3 Email',
                'item_order' => 3,
                'usage_type' => 'campaign_step',
                'meta' => [
                    'campaign_key' => 'webinar_attended_nurture',
                    'campaign_step' => 3,
                    'campaign_step_variant_key' => 'email',
                ],
            ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->forCampaignStepVariant('webinar_attended_nurture', 2, 'email', 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.2.variants.email')
            ->create([
                'meta' => [
                    'source' => 'config_sync',
                    'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.2.variants.email',
                    'campaign_step_variant_key' => 'email',
                    'catalog' => [
                        'group_label' => 'Webinar Attended Nurture',
                        'item_label' => 'Step 2 Email',
                    ],
                ],
            ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/message-templates?preset='.$preset->getKey())
            ->assertOk()
            ->assertSee($preset->name)
            ->assertSee('Webinar Attended Nurture')
            ->assertViewHas('messageLibrary', function (mixed $library): bool {
                $labels = collect(is_array($library) ? ($library['channels'] ?? []) : [])
                    ->flatMap(fn (array $channel): array => $channel['messages'] ?? [])
                    ->pluck('step_name')
                    ->values()
                    ->all();

                return $labels === [
                    'Now let’s talk about YOUR VA loan',
                    'What to do next after the webinar',
                ];
            })
            ->assertSee(route('crm.campaigns.message-templates.index', [
                'campaign' => 'webinar_attended_nurture',
                'step' => 2,
            ]))
            ->assertDontSee('name="message_template_preset_id"', false);
    }

    public function test_library_search_uses_human_message_labels_and_business_facing_usage_filter(): void
    {
        config()->set('modules.enabled', ['messaging']);
        $user = User::factory()->create();

        $campaignPreset = MessageTemplatePreset::factory()->create([
            'name' => 'Cold Lead Nurture — Step 7 Email',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'mortgage_homebuyer_nurture',
            'message_type' => 'cold_lead_nurture_step_7',
            'payload_class' => EmailPayload::class,
            'payload' => [
                'subject' => 'Don’t Google your way through a mortgage.',
                'body' => 'Campaign body.',
            ],
        ]);

        MessageTemplateCatalogEntry::factory()->forPreset($campaignPreset)->create([
            'module_key' => 'campaigns',
            'module_label' => 'Campaigns',
            'surface' => 'campaigns',
            'group_key' => 'campaign:cold_lead_nurture',
            'group_label' => 'Cold Lead Nurture',
            'item_key' => 'campaign.cold_lead_nurture.step.7.email',
            'item_label' => 'Step 7 Email',
            'item_order' => 7,
            'usage_type' => 'campaign_step',
        ]);

        $webinarPreset = MessageTemplatePreset::factory()->create([
            'name' => 'Homebuyer Game Plan — Reminder 5 Email',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'reminder_10_minute',
            'payload_class' => EmailPayload::class,
            'payload' => [
                'subject' => 'Starting Soon — 10 Minutes',
                'body' => 'Webinar reminder body.',
            ],
        ]);

        MessageTemplateCatalogEntry::factory()->forPreset($webinarPreset)->create([
            'module_key' => 'webinars',
            'module_label' => 'Webinars',
            'surface' => 'webinar_registrations',
            'group_key' => 'webinars:homebuyer_game_plan:reminders',
            'group_label' => 'Homebuyer Game Plan — Webinar Reminders',
            'item_key' => 'webinar.reminder_10_minute.email',
            'item_label' => 'Reminder 5 Email',
            'item_order' => 5,
            'usage_type' => 'webinar_reminder',
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/message-templates?q=10-minute')
            ->assertOk()
            ->assertSee('10-Minute Reminder')
            ->assertSee('Homebuyer Game Plan — Webinar Reminders')
            ->assertDontSee('Cold Lead Nurture')
            ->assertViewHas('messageLibrary', fn (mixed $library): bool =>
                collect(is_array($library) ? ($library['channels'] ?? []) : [])
                    ->flatMap(fn (array $channel): array => $channel['messages'] ?? [])
                    ->pluck('step_name')
                    ->contains('10-Minute Reminder')
            );

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/message-templates?q=mortgage&module=campaigns')
            ->assertOk()
            ->assertSee('Don’t Google your way through a mortgage.')
            ->assertSee('Context')
            ->assertViewHas('messageLibrary', fn (mixed $library): bool =>
                collect(is_array($library) ? ($library['channels'] ?? []) : [])
                    ->flatMap(fn (array $channel): array => $channel['messages'] ?? [])
                    ->pluck('step_name')
                    ->contains('Don’t Google your way through a mortgage.')
            );
    }

    public function test_it_updates_email_template_safe_copy_fields(): void
    {
        config()->set('modules.enabled', [
            'messaging',
        ]);

        $user = User::factory()->create();

        $preset = MessageTemplatePreset::factory()->create([
            'name' => 'Registration Confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'Old subject',
                'body' => 'Old body.',
                'cta' => [
                    'label' => 'Join',
                    'url' => '{webinar_join_url}',
                ],
            ],
            'tokens' => ['webinar_join_url'],
            'is_customized' => false,
            'customized_at' => null,
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->patch('http://crm.'.config('app.root_domain').'/message-templates/'.$preset->getKey(), [
                'name' => 'Updated Confirmation',
                'description' => 'Updated helper copy.',
                'payload' => [
                    'subject' => 'New subject {first_name}',
                    'body' => 'New body for {first_name}.',
                    'cta' => [
                        'label' => 'Join Now',
                        'url' => '{webinar_join_url}',
                    ],
                    'footer' => 'Footer copy.',
                ],
            ])
            ->assertRedirect(route('crm.messaging.message-templates.index', [
                'channel' => 'email',
                'purpose' => 'transactional',
                'preset' => $preset->getKey(),
            ]));

        $preset->refresh();
        $template = MessageTemplate::query()->where('key', $preset->key)->firstOrFail();
        $template->load('currentVersion');
        $override = MessageTemplateCompositionLayer::query()
            ->where('scope_type', MessageTemplateCompositionLayer::SCOPE_MESSAGE)
            ->where('message_template_id', $template->getKey())
            ->firstOrFail();

        $this->assertSame('Old subject', $preset->payload['subject']);
        $this->assertSame('Old body.', $preset->payload['body']);
        $this->assertFalse($preset->is_customized);
        $this->assertNull($preset->customized_at);
        $this->assertSame('New subject {first_name}', $override->payload['subject']);
        $this->assertSame('New body for {first_name}.', $override->payload['body']);
        $this->assertSame('Join Now', $override->payload['cta']['label']);
        $this->assertSame('Footer copy.', $override->payload['footer']);
        $this->assertSame('New subject {first_name}', $template->currentVersion->payload()['subject']);
        $this->assertEqualsCanonicalizing(['first_name', 'webinar_join_url'], $preset->tokens);
    }

    public function test_it_updates_sms_template_safe_copy_fields(): void
    {
        config()->set('modules.enabled', [
            'messaging',
        ]);

        $user = User::factory()->create();

        $preset = MessageTemplatePreset::factory()->create([
            'name' => 'Reminder Text',
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'reminder',
            'payload_class' => SmsPayload::class,
            'queue' => 'notifications',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'message' => 'Old reminder.',
            ],
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->patch('http://crm.'.config('app.root_domain').'/message-templates/'.$preset->getKey(), [
                'name' => 'Reminder Text',
                'description' => null,
                'payload' => [
                    'message' => 'Hi {first_name}, your webinar starts soon.',
                ],
            ])
            ->assertRedirect(route('crm.messaging.message-templates.index', [
                'channel' => 'sms',
                'purpose' => 'transactional',
                'preset' => $preset->getKey(),
            ]));

        $preset->refresh();
        $template = MessageTemplate::query()->where('key', $preset->key)->firstOrFail();
        $template->load('currentVersion');
        $override = MessageTemplateCompositionLayer::query()
            ->where('scope_type', MessageTemplateCompositionLayer::SCOPE_MESSAGE)
            ->where('message_template_id', $template->getKey())
            ->firstOrFail();

        $this->assertSame('Old reminder.', $preset->payload['message']);
        $this->assertFalse($preset->is_customized);
        $this->assertSame('Hi {first_name}, your webinar starts soon.', $override->payload['message']);
        $this->assertSame('Hi {first_name}, your webinar starts soon.', $template->currentVersion->payload()['message']);
        $this->assertEquals(['first_name'], $preset->tokens);
    }

    public function test_email_template_requires_subject_and_body(): void
    {
        config()->set('modules.enabled', [
            'messaging',
        ]);

        $user = User::factory()->create();

        $preset = MessageTemplatePreset::factory()->create([
            'payload_class' => EmailPayload::class,
            'payload' => [
                'subject' => 'Old subject',
                'body' => 'Old body.',
            ],
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->from('http://crm.'.config('app.root_domain').'/message-templates?preset='.$preset->getKey())
            ->patch('http://crm.'.config('app.root_domain').'/message-templates/'.$preset->getKey(), [
                'name' => 'Broken Email',
                'description' => null,
                'payload' => [
                    'subject' => '',
                    'body' => '',
                ],
            ])
            ->assertSessionHasErrors(['payload.subject', 'payload.body']);
    }

    public function test_it_updates_email_template_multiple_ctas(): void
    {
        config()->set('modules.enabled', [
            'messaging',
        ]);

        $user = User::factory()->create();

        $preset = MessageTemplatePreset::factory()->create([
            'name' => 'Replay Follow-Up',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'post_attended',
            'payload_class' => EmailPayload::class,
            'queue' => 'post_event',
            'dispatch_keys' => ['webinar_ended'],
            'payload' => [
                'subject' => 'Thanks for Joining',
                'body' => 'Watch the replay here: {cta}',
                'ctas' => [
                    [
                        'label' => 'Watch the Recording',
                        'url' => '{webinar_playback_url}',
                    ],
                    [
                        'label' => 'Get Pre-Approved',
                        'url' => 'https://robthemortgagecoach.my1003app.com/322051/register',
                    ],
                ],
            ],
            'tokens' => ['webinar_playback_url'],
            'is_customized' => false,
            'customized_at' => null,
        ]);

        MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'module_key' => 'webinars',
                'module_label' => 'Webinars',
                'surface' => 'webinar_registrations',
                'group_key' => 'webinars:transactional:webinar:post_attended',
                'group_label' => 'Post-Webinar Follow-Up',
                'item_key' => 'email.transactional.webinar.post_attended',
                'item_label' => 'Attended Follow-Up Email',
                'item_order' => 0,
                'usage_type' => 'webinar_post_attended',
            ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/message-templates?preset='.$preset->getKey())
            ->assertOk()
            ->assertSee('Watch the Recording')
            ->assertSee('Get Pre-Approved')
            ->assertSee('https://robthemortgagecoach.my1003app.com/322051/register');

        $this->actingAs($user)
            ->patch('http://crm.'.config('app.root_domain').'/message-templates/'.$preset->getKey(), [
                'name' => 'Replay Follow-Up',
                'description' => null,
                'payload' => [
                    'subject' => 'Replay ready {first_name}',
                    'body' => 'Watch the replay and take the next step. {cta}',
                    'ctas' => [
                        [
                            'label' => 'Watch Replay',
                            'url' => '{webinar_playback_url}',
                        ],
                        [
                            'label' => 'Start Pre-Approval',
                            'url' => 'https://robthemortgagecoach.my1003app.com/322051/register',
                        ],
                    ],
                ],
            ])
            ->assertRedirect(route('crm.messaging.message-templates.index', [
                'channel' => 'email',
                'purpose' => 'transactional',
                'module' => 'webinars',
                'group' => 'webinars:transactional:webinar:post_attended',
                'preset' => $preset->getKey(),
            ]));

        $preset->refresh();
        $template = MessageTemplate::query()->where('key', $preset->key)->firstOrFail();
        $template->load('currentVersion');
        $override = MessageTemplateCompositionLayer::query()
            ->where('scope_type', MessageTemplateCompositionLayer::SCOPE_MESSAGE)
            ->where('message_template_id', $template->getKey())
            ->firstOrFail();

        $this->assertSame('Thanks for Joining', $preset->payload['subject']);
        $this->assertSame('Replay ready {first_name}', $override->payload['subject']);
        $this->assertSame('Watch the replay and take the next step. {cta}', $override->payload['body']);
        $this->assertSame('Watch Replay', $override->payload['ctas'][0]['label']);
        $this->assertSame('{webinar_playback_url}', $override->payload['ctas'][0]['url']);
        $this->assertSame('Start Pre-Approval', $override->payload['ctas'][1]['label']);
        $this->assertFalse($preset->is_customized);
        $this->assertEqualsCanonicalizing(['cta', 'first_name', 'webinar_playback_url'], $preset->tokens);
        $this->assertSame('Replay ready {first_name}', $template->currentVersion->payload()['subject']);
    }
}