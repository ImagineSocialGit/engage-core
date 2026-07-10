<?php

namespace Tests\Feature\Webinars;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebinarMessageTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_webinar_message_contexts_without_copy_editing(): void
    {
        config()->set('modules.enabled', [
            'webinars',
            'messaging',
        ]);

        $user = User::factory()->create();
        $preset = $this->webinarTemplate([
            'message_type' => 'confirmation',
            'usage_type' => 'webinar_confirmation',
            'group_label' => 'Webinar Confirmations',
            'item_label' => 'Confirmation Email',
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create([
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'meta' => [
                    'catalog' => [
                        'group_label' => 'Webinar Confirmations',
                        'item_label' => 'Confirmation Email',
                    ],
                ],
            ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/webinars/message-templates')
            ->assertOk()
            ->assertSee('Webinar Messages')
            ->assertSee('Message selection')
            ->assertSee('Setup readiness')
            ->assertSee('Registration confirmations')
            ->assertSee('Registration opt-in confirmations')
            ->assertSee('Reminder messages')
            ->assertSee('Waitlist availability messages')
            ->assertSee('Waitlist opt-in confirmations')
            ->assertSee('Attended replay follow-up')
            ->assertSee('Missed replay follow-up')
            ->assertSee('Active template')
            ->assertSee('Confirmation Email')
            ->assertSee('Save selection')
            ->assertSee('Edit copy')
            ->assertSee(route('crm.messaging.message-templates.index', ['module' => 'webinars']), false)
            ->assertSee('Webinars decide when lifecycle messages are scheduled')
            ->assertDontSee('Subject')
            ->assertDontSee('Body')
            ->assertDontSee('Template title');
    }

    public function test_it_updates_the_selected_template_for_a_webinar_message_context(): void
    {
        config()->set('modules.enabled', [
            'webinars',
            'messaging',
        ]);

        $user = User::factory()->create();
        $oldPreset = $this->webinarTemplate([
            'key' => 'email.transactional.webinar.confirmation.old',
            'name' => 'Old Confirmation Email',
            'message_type' => 'confirmation',
            'usage_type' => 'webinar_confirmation',
            'group_label' => 'Webinar Confirmations',
            'item_label' => 'Confirmation Email',
            'payload' => [
                'subject' => 'Old subject',
                'body' => 'Old body.',
            ],
        ]);
        $newPreset = $this->webinarTemplate([
            'key' => 'email.transactional.webinar.confirmation.new',
            'name' => 'New Confirmation Email',
            'message_type' => 'confirmation',
            'usage_type' => 'webinar_confirmation',
            'group_label' => 'Webinar Confirmations',
            'item_label' => 'Confirmation Email',
            'payload' => [
                'subject' => 'New subject',
                'body' => 'New body.',
            ],
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($oldPreset)
            ->create([
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
            ]);

        $catalogEntry = $oldPreset->catalogEntries()->firstOrFail();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->from('http://crm.'.config('app.root_domain').'/webinars/message-templates?section=confirmation')
            ->patch('http://crm.'.config('app.root_domain').'/webinars/message-templates', [
                'context_key' => 'confirmation',
                'catalog_entry_id' => $catalogEntry->getKey(),
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'message_template_preset_id' => $newPreset->getKey(),
            ])
            ->assertRedirect(route('crm.webinars.message-templates.index', [
                'section' => 'confirmation',
                'context' => 'confirmation',
            ]));

        $this->assertDatabaseHas('message_template_preset_assignments', [
            'message_template_preset_id' => $newPreset->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'confirmation',
            'campaign_key' => null,
            'campaign_step' => null,
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
        ]);

        $definitions = app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertSame('New subject', collect($definitions)->firstWhere('message_type', 'confirmation')['payload']['subject'] ?? null);
    }

    public function test_it_rejects_a_template_that_is_not_cataloged_for_webinars(): void
    {
        config()->set('modules.enabled', [
            'webinars',
            'messaging',
        ]);

        $user = User::factory()->create();
        $webinarPreset = $this->webinarTemplate([
            'message_type' => 'confirmation',
            'usage_type' => 'webinar_confirmation',
        ]);

        $wrongPreset = MessageTemplatePreset::factory()->create([
            'key' => 'email.transactional.permission_invitation.invitation',
            'name' => 'Permission Invitation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'message_type' => 'imported_contact_permission_invitation',
            'payload_class' => EmailPayload::class,
            'queue' => 'permission_invitations',
            'dispatch_keys' => ['imported_contact_permission_invitation'],
        ]);

        MessageTemplateCatalogEntry::factory()
            ->forPreset($wrongPreset)
            ->create([
                'module_key' => 'messaging',
                'module_label' => 'Messaging',
                'surface' => 'permission_invitations',
                'usage_type' => 'permission_invitation',
            ]);

        $catalogEntry = $webinarPreset->catalogEntries()->firstOrFail();

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->from('http://crm.'.config('app.root_domain').'/webinars/message-templates?section=confirmation')
            ->patch('http://crm.'.config('app.root_domain').'/webinars/message-templates', [
                'context_key' => 'confirmation',
                'catalog_entry_id' => $catalogEntry->getKey(),
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'message_template_preset_id' => $wrongPreset->getKey(),
            ])
            ->assertSessionHasErrors(['message_template_preset_id']);
    }

    public function test_index_displays_effective_timing_from_the_active_webinar_schedule_profile(): void
    {
        config()->set('modules.enabled', [
            'webinars',
            'messaging',
        ]);

        $user = User::factory()->create();

        $preset = $this->webinarTemplate([
            'message_type' => 'confirmation',
            'usage_type' => 'webinar_confirmation',
            'group_label' => 'Webinar Confirmations',
            'item_label' => 'Confirmation Email',
            'timing' => 'immediate',
            'schedule' => null,
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create([
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
            ]);

        $profile = WebinarScheduleProfile::factory()->create([
            'key' => 'default_profile',
            'name' => 'Default profile',
            'status' => WebinarScheduleProfile::STATUS_ACTIVE,
            'is_default' => true,
            'is_active' => true,
        ]);

        WebinarScheduleProfileItem::factory()->create([
            'webinar_schedule_profile_id' => $profile->getKey(),
            'key' => 'email_confirmation',
            'context_key' => 'confirmations',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'source_config_path' => $preset->source_config_path,
            'is_enabled' => true,
            'is_active' => true,
            'timing' => 'scheduled',
            'schedule' => [
                'type' => 'delay',
                'minutes' => 10,
            ],
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/webinars/message-templates?section=confirmation')
            ->assertOk()
            ->assertSee('After 10 minutes')
            ->assertDontSee('Timing</dt>
                                                            <dd class="mt-1 font-semibold text-slate-900">
                                                                Immediate', false);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function webinarTemplate(array $overrides = []): MessageTemplatePreset
    {
        $usageType = $overrides['usage_type'] ?? 'webinar_confirmation';
        $groupLabel = $overrides['group_label'] ?? 'Webinar Confirmations';
        $itemLabel = $overrides['item_label'] ?? 'Confirmation Email';
        unset($overrides['usage_type'], $overrides['group_label'], $overrides['item_label']);

        $preset = MessageTemplatePreset::factory()->create(array_replace_recursive([
            'key' => 'email.transactional.webinar.confirmation.'.uniqid(),
            'name' => 'Webinar Confirmations — Confirmation Email',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'Registered',
                'body' => 'You are registered.',
            ],
            'source_config_path' => 'messaging.email.transactional.webinar.confirmation',
        ], $overrides));

        MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'module_key' => 'webinars',
                'module_label' => 'Webinars',
                'surface' => 'webinar_registrations',
                'group_key' => 'webinars:transactional:webinar:confirmation',
                'group_label' => $groupLabel,
                'item_key' => $preset->key,
                'item_label' => $itemLabel,
                'item_order' => 0,
                'usage_type' => $usageType,
                'source_config_path' => $preset->source_config_path,
                'meta' => [
                    'message_type' => $preset->message_type,
                    'source_message_type' => $preset->message_type,
                ],
            ]);

        return $preset;
    }
}
