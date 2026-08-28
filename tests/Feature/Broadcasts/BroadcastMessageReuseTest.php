<?php

namespace Tests\Feature\Broadcasts;

use App\Models\User;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Messaging\Actions\CreateReusableMessageTemplateAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Services\MessageTokenFallbackResolver;
use App\Modules\Messaging\Services\ReusableMessageTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastMessageReuseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.modules.broadcasts.enabled' => true,
            'modules.modules.messaging.enabled' => true,
        ]);
    }

    public function test_regular_broadcast_message_can_be_saved_to_the_existing_message_template_catalog(): void
    {
        $user = User::factory()->create();
        $broadcast = Broadcast::factory()->create([
            'name' => 'Friday Realtor Update',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'dispatch_key' => Broadcast::DEFAULT_DISPATCH_KEY,
            'message_type' => Broadcast::DEFAULT_MESSAGE_TYPE,
            'payload' => [
                'subject' => 'One VA myth to stop repeating',
                'body' => 'Here is the useful copy.',
            ],
            'meta' => [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.save-message-template', $broadcast), [
                'name' => 'VA Realtor Myth — Reusable',
            ]);

        $response
            ->assertRedirect(route('crm.broadcasts.show', $broadcast))
            ->assertSessionHas('success');

        $preset = MessageTemplatePreset::query()->sole();
        $template = MessageTemplate::query()->where('key', $preset->key)->sole();
        $catalogEntry = MessageTemplateCatalogEntry::query()->sole();

        $this->assertSame(CreateReusableMessageTemplateAction::SOURCE, $preset->source);
        $this->assertSame('VA Realtor Myth — Reusable', $preset->name);
        $this->assertSame('broadcasts', $catalogEntry->module_key);
        $this->assertSame('broadcasts', $catalogEntry->surface);
        $this->assertSame('broadcast_reuse', $catalogEntry->usage_type);
        $this->assertSame('broadcasts', data_get($catalogEntry->meta, 'authoring.context_key'));
        $this->assertEquals(
            ['broadcasts', 'campaign_annual_touch'],
            data_get($catalogEntry->meta, 'authoring.selection_contexts'),
        );
        $this->assertEquals($broadcast->payload, $template->currentPayload());

        $this
            ->actingAs($user)
            ->get(route('crm.messaging.message-templates.index'))
            ->assertOk()
            ->assertSee('VA Realtor Myth — Reusable');

        $this
            ->actingAs($user)
            ->get(route('crm.broadcasts.index'))
            ->assertOk()
            ->assertSee('VA Realtor Myth — Reusable');
    }

    public function test_saved_broadcast_message_preserves_missing_field_behavior_when_loaded_for_reuse(): void
    {
        $user = User::factory()->create();
        $broadcast = Broadcast::factory()->create([
            'name' => 'Personalized Update',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'dispatch_key' => Broadcast::DEFAULT_DISPATCH_KEY,
            'message_type' => Broadcast::DEFAULT_MESSAGE_TYPE,
            'payload' => [
                'subject' => 'A note for {first_name}',
                'body' => 'Hey {first_name}, here is the update.',
                'token_fallbacks' => [[
                    'token' => 'first_name',
                    'missing_behavior' => MessageTokenFallbackResolver::BEHAVIOR_FALLBACK_VALUE,
                    'fallback' => 'there',
                ]],
            ],
            'meta' => [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
            ],
        ]);

        $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.save-message-template', $broadcast), [
                'name' => 'Personalized Reusable Update',
            ])
            ->assertRedirect(route('crm.broadcasts.show', $broadcast))
            ->assertSessionHas('success');

        $definition = collect(app(ReusableMessageTemplateCatalog::class)->definitions(
            channels: ['email'],
            purpose: 'marketing',
            selectionContext: 'broadcasts',
        ))->sole();

        $this->assertEquals(
            $broadcast->payload['token_fallbacks'],
            $definition['payload']['token_fallbacks'],
        );
        $this->assertSame(
            'Hey {first_name}, here is the update.',
            $definition['payload']['body'],
        );
    }

    public function test_make_new_broadcast_copies_message_but_resets_audience_and_send_state(): void
    {
        $user = User::factory()->create();
        $broadcast = Broadcast::factory()->completed()->create([
            'name' => 'Original Update',
            'payload' => [
                'subject' => 'Original subject',
                'body' => 'Original body',
            ],
            'recipient_filter' => [
                'type' => 'contact_ids',
                'contact_ids' => [123, 456],
            ],
            'recipient_count' => 2,
            'scheduled_count' => 2,
            'meta' => [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
            ],
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.duplicate', $broadcast));

        $copy = Broadcast::query()
            ->whereKeyNot($broadcast->getKey())
            ->sole();

        $response
            ->assertRedirect(route('crm.broadcasts.edit', $copy))
            ->assertSessionHas('success');

        $this->assertSame($user->id, $copy->user_id);
        $this->assertSame(Broadcast::STATUS_DRAFT, $copy->status);
        $this->assertSame('Copy of Original Update', $copy->name);
        $this->assertEquals($broadcast->payload, $copy->payload);
        $this->assertEquals([
            'type' => 'criteria',
            'criteria' => [],
        ], $copy->recipient_filter);
        $this->assertNull($copy->send_at);
        $this->assertSame(0, $copy->recipient_count);
        $this->assertSame(0, $copy->scheduled_count);
        $this->assertNull($copy->cancelled_at);
        $this->assertNull($copy->completed_at);
        $this->assertSame(Broadcast::BROADCAST_TYPE_REGULAR, $copy->meta['broadcast_type']);
        $this->assertArrayNotHasKey('copied_from', $copy->meta);
    }

    public function test_permission_invitation_cannot_be_saved_or_duplicated_as_regular_reuse(): void
    {
        $user = User::factory()->create();
        $broadcast = Broadcast::factory()->create([
            'name' => 'Permission invitation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'dispatch_key' => Broadcast::PERMISSION_INVITATION_DISPATCH_KEY,
            'message_type' => Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'recipient_filter' => ['type' => 'imported'],
            'meta' => [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION,
            ],
        ]);

        $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.save-message-template', $broadcast), [
                'name' => 'Should not save',
            ])
            ->assertRedirect(route('crm.broadcasts.show', $broadcast))
            ->assertSessionHas('error');

        $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.duplicate', $broadcast))
            ->assertRedirect(route('crm.broadcasts.show', $broadcast))
            ->assertSessionHas('error');

        $this->assertSame(0, MessageTemplatePreset::query()->count());
        $this->assertSame(0, MessageTemplate::query()->count());
        $this->assertSame(1, Broadcast::query()->count());
    }
}