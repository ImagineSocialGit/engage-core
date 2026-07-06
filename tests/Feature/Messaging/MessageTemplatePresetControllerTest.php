<?php

namespace Tests\Feature\Messaging;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTemplatePresetControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_message_templates_with_business_language(): void
    {
        config()->set('modules.enabled', [
            'messaging',
        ]);

        $user = User::factory()->create();

        MessageTemplatePreset::factory()->create([
            'name' => 'Registration Confirmation',
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
            'source_config_path' => 'messaging.email.transactional.webinar.confirmation',
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/message-templates')
            ->assertOk()
            ->assertSee('Message Templates')
            ->assertSee('Registration Confirmation')
            ->assertSee('Selected template')
            ->assertSee('Used by')
            ->assertSee('Tokens used')
            ->assertSee('You are registered')
            ->assertDontSee('payload_class');
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
            ->assertRedirect(route('crm.messaging.message-templates.index', ['preset' => $preset->getKey()]));

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
            ->assertRedirect(route('crm.messaging.message-templates.index', ['preset' => $preset->getKey()]));

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
    public function test_it_renders_assignment_selector_for_selected_template(): void
    {
        config()->set('modules.enabled', [
            'messaging',
        ]);

        $user = User::factory()->create();

        $preset = MessageTemplatePreset::factory()->create([
            'name' => 'Current Confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
        ]);

        $alternate = MessageTemplatePreset::factory()->customized()->create([
            'name' => 'Alternate Confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/message-templates?preset='.$preset->getKey())
            ->assertOk()
            ->assertSee('Selected for workflows')
            ->assertSee('Current Confirmation')
            ->assertSee('Alternate Confirmation')
            ->assertSee('Timing, triggers, and skip rules stay in the workflow or campaign setup.');
    }

    public function test_it_updates_selected_template_for_assignment(): void
    {
        config()->set('modules.enabled', [
            'messaging',
        ]);

        $user = User::factory()->create();

        $current = MessageTemplatePreset::factory()->create([
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
        ]);

        $alternate = MessageTemplatePreset::factory()->customized()->create([
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
        ]);

        $assignment = MessageTemplatePresetAssignment::factory()
            ->forPreset($current)
            ->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->patch('http://crm.'.config('app.root_domain').'/message-templates/assignments/'.$assignment->getKey(), [
                'message_template_preset_id' => $alternate->getKey(),
            ])
            ->assertRedirect(route('crm.messaging.message-templates.index', ['preset' => $alternate->getKey()]));

        $assignment->refresh();

        $this->assertSame($alternate->getKey(), $assignment->message_template_preset_id);
        $this->assertTrue(data_get($assignment->meta, 'selected_from_crm'));
    }

    public function test_assignment_update_rejects_incompatible_template(): void
    {
        config()->set('modules.enabled', [
            'messaging',
        ]);

        $user = User::factory()->create();

        $current = MessageTemplatePreset::factory()->create([
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
        ]);

        $wrongContext = MessageTemplatePreset::factory()->create([
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'reminder',
            'payload_class' => EmailPayload::class,
        ]);

        $assignment = MessageTemplatePresetAssignment::factory()
            ->forPreset($current)
            ->create();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->from('http://crm.'.config('app.root_domain').'/message-templates?preset='.$current->getKey())
            ->patch('http://crm.'.config('app.root_domain').'/message-templates/assignments/'.$assignment->getKey(), [
                'message_template_preset_id' => $wrongContext->getKey(),
            ])
            ->assertSessionHasErrors(['message_template_preset_id']);

        $this->assertSame($current->getKey(), $assignment->refresh()->message_template_preset_id);
    }

}
