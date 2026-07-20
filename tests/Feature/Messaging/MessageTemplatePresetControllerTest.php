<?php

namespace Tests\Feature\Messaging;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTemplatePresetControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_message_templates_with_catalog_grouping_and_business_language(): void
    {
        config()->set('modules.enabled', [
            'messaging',
        ]);

        $user = User::factory()->create();

        $preset = MessageTemplatePreset::factory()->create([
            'name' => 'Webinar Confirmations — Confirmation Email',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'You are registered',
                'body' => 'Thanks for registering.',
            ],
            'tokens' => ['first_name'],
            'source_config_path' => 'messaging.email.definitions.transactional.webinar.confirmations.0',
        ]);

        MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'module_key' => 'webinars',
                'module_label' => 'Webinars',
                'surface' => 'webinar_registrations',
                'group_key' => 'webinars:transactional:webinar:confirmation',
                'group_label' => 'Webinar Confirmations',
                'item_key' => 'email.transactional.webinar.confirmations.0',
                'item_label' => 'Confirmation Email',
                'item_order' => 0,
                'usage_type' => 'webinar_confirmation',
            ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/message-templates')
            ->assertOk()
            ->assertSee($preset->name)
            ->assertSee('Webinars')
            ->assertSee('Webinar Confirmations')
            ->assertSee('Confirmation Email')
            ->assertSee('You are registered')
            ->assertSee('first_name')
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
            ->assertSee('Step 2 Email')
            ->assertSee('Step 3 Email')
            ->assertSee(route('crm.campaigns.message-templates.index', [
                'campaign' => 'webinar_attended_nurture',
                'step' => 2,
            ]))
            ->assertDontSee('name="message_template_preset_id"', false);
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

        $this->assertSame('Updated Confirmation', $preset->name);
        $this->assertSame('Updated helper copy.', $preset->description);
        $this->assertSame('New subject {first_name}', $preset->payload['subject']);
        $this->assertSame('New body for {first_name}.', $preset->payload['body']);
        $this->assertSame('Join Now', $preset->payload['cta']['label']);
        $this->assertSame('{webinar_join_url}', $preset->payload['cta']['url']);
        $this->assertSame('Footer copy.', $preset->payload['footer']);
        $this->assertTrue($preset->is_customized);
        $this->assertNotNull($preset->customized_at);
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

        $this->assertSame('Hi {first_name}, your webinar starts soon.', $preset->payload['message']);
        $this->assertTrue($preset->is_customized);
        $this->assertSame(['first_name'], $preset->tokens);
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

        $this->assertSame('Replay ready {first_name}', $preset->payload['subject']);
        $this->assertSame('Watch the replay and take the next step. {cta}', $preset->payload['body']);
        $this->assertSame('Watch Replay', $preset->payload['ctas'][0]['label']);
        $this->assertSame('{webinar_playback_url}', $preset->payload['ctas'][0]['url']);
        $this->assertSame('Start Pre-Approval', $preset->payload['ctas'][1]['label']);
        $this->assertSame('https://robthemortgagecoach.my1003app.com/322051/register', $preset->payload['ctas'][1]['url']);
        $this->assertTrue($preset->is_customized);
        $this->assertNotNull($preset->customized_at);
        $this->assertEqualsCanonicalizing(['cta', 'first_name', 'webinar_playback_url'], $preset->tokens);
    }
}