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
                    'token_fallbacks_present' => '1',
                    'token_fallbacks' => [
                        [
                            'token' => 'first_name',
                            'missing_behavior' => 'fallback_value',
                            'fallback' => 'there',
                        ],
                        [
                            'token' => 'webinar_join_url',
                            'missing_behavior' => 'required',
                        ],
                    ],
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
        $this->assertEquals([
            [
                'token' => 'first_name',
                'missing_behavior' => 'fallback_value',
                'fallback' => 'there',
            ],
            [
                'token' => 'webinar_join_url',
                'missing_behavior' => 'required',
            ],
        ], $override->payload['token_fallbacks']);
        $this->assertSame('New subject {first_name}', $template->currentVersion->payload()['subject']);
        $this->assertEquals(
            $override->payload['token_fallbacks'],
            $template->currentVersion->payload()['token_fallbacks'],
        );
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
            ->assertOk();

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