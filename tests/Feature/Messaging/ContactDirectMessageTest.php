<?php

namespace Tests\Feature\Messaging;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\CreateReusableMessageTemplateAction;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringContext;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\ReusableMessageTemplateCatalog;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageMediaLibrary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContactDirectMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_show_exposes_messaging_owned_send_message_modal_when_contact_can_receive_email(): void
    {
        config()->set('modules.enabled', ['messaging']);
        config()->set('messaging.channel_availability.email.surfaces.contact_direct_messages', true);

        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'email' => 'jane@example.test',
        ]);
        $this->grantConsent($contact, 'email', 'transactional');

        $this->actingAs($user)
            ->get(route('crm.contacts.show', $contact))
            ->assertOk()
            ->assertSee('data-contact-direct-message-panel', false)
            ->assertSee('data-contact-direct-message-open', false)
            ->assertSee('data-contact-direct-message-modal', false);
    }

    public function test_one_off_email_creates_scheduled_message_without_creating_reusable_template_rows(): void
    {
        config()->set('modules.enabled', ['messaging']);
        config()->set('messaging.channel_availability.email.surfaces.contact_direct_messages', true);
        Queue::fake();

        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'email' => 'jane@example.test',
        ]);
        $this->grantConsent($contact, 'email', 'transactional');

        $this->actingAs($user)
            ->post(route('crm.messaging.contacts.messages.store', $contact), [
                'direct_message' => [
                    'request_key' => (string) Str::uuid(),
                    'channel' => 'email',
                    'purpose' => 'transactional',
                    'subject' => 'Checking in',
                    'body' => 'Hi {first_name}, checking in about tomorrow.',
                ],
            ])
            ->assertRedirect(route('crm.contacts.show', $contact));

        $scheduledMessage = ScheduledMessage::query()->sole();

        $this->assertSame('email', $scheduledMessage->channel);
        $this->assertSame('transactional', $scheduledMessage->purpose);
        $this->assertSame('contact_direct_message', $scheduledMessage->scope);
        $this->assertSame('contact_direct_message', $scheduledMessage->message_type);
        $this->assertSame('emails', $scheduledMessage->queue);
        $this->assertSame('jane@example.test', data_get($scheduledMessage->payload, 'to'));
        $this->assertSame('Checking in', data_get($scheduledMessage->payload, 'subject'));
        $this->assertSame('Hi {first_name}, checking in about tomorrow.', data_get($scheduledMessage->payload, 'body'));
        $this->assertSame($contact->first_name, data_get($scheduledMessage->payload, 'tokens.first_name'));
        $this->assertSame('crm_contact_direct_message', data_get($scheduledMessage->meta, 'surface'));
        $this->assertNull($scheduledMessage->message_template_version_id);
        $this->assertSame(0, MessageTemplatePreset::query()->count());
        $this->assertSame(0, MessageTemplate::query()->count());
    }

    public function test_marketing_direct_message_is_rejected_when_contact_lacks_marketing_consent(): void
    {
        config()->set('modules.enabled', ['messaging']);
        config()->set('messaging.channel_availability.email.surfaces.contact_direct_messages', true);

        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'email' => 'jane@example.test',
        ]);
        $this->grantConsent($contact, 'email', 'transactional');

        $this->actingAs($user)
            ->from(route('crm.contacts.show', $contact))
            ->post(route('crm.messaging.contacts.messages.store', $contact), [
                'direct_message' => [
                    'request_key' => (string) Str::uuid(),
                    'channel' => 'email',
                    'purpose' => 'marketing',
                    'subject' => 'An offer',
                    'body' => 'Marketing copy.',
                ],
            ])
            ->assertSessionHasErrors('direct_message.channel');

        $this->assertSame(0, ScheduledMessage::query()->count());
    }

    public function test_reusable_template_is_only_a_source_snapshot_and_can_keep_or_remove_media_for_one_off_send(): void
    {
        config()->set('modules.enabled', ['messaging']);
        config()->set('messaging.channel_availability.email.surfaces.contact_direct_messages', true);
        Queue::fake();

        $assetUuid = (string) Str::uuid();
        $mediaSnapshot = [
            'asset_uuid' => $assetUuid,
            'kind' => 'image',
            'title' => 'Reusable hero',
            'url' => 'https://cdn.example.test/media/reusable-hero.jpg',
            'mime_type' => 'image/jpeg',
            'tracking_key' => 'media_primary',
        ];

        $this->app->instance(MessageMediaLibrary::class, new class($mediaSnapshot) implements MessageMediaLibrary {
            public function __construct(private readonly array $snapshot) {}

            public function available(): bool
            {
                return true;
            }

            public function selectableAssets(): array
            {
                return [[
                    'uuid' => $this->snapshot['asset_uuid'],
                    'title' => $this->snapshot['title'],
                    'kind' => $this->snapshot['kind'],
                    'mime_type' => $this->snapshot['mime_type'],
                    'public_url' => $this->snapshot['url'],
                ]];
            }

            public function snapshot(string $assetUuid, ?string $posterAssetUuid = null): array
            {
                return $this->snapshot;
            }

            public function store(
                UploadedFile $file,
                ?string $title = null,
                ?string $posterAssetUuid = null,
                ?Model $uploadedBy = null,
            ): array {
                return $this->snapshot;
            }
        });

        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'email' => 'jane@example.test',
        ]);
        $this->grantConsent($contact, 'email', 'transactional');

        $preset = app(CreateReusableMessageTemplateAction::class)->handle(
            name: 'Reusable direct email',
            channel: 'email',
            payload: [
                'subject' => 'Reusable subject',
                'body' => 'Reusable body',
                'media' => $mediaSnapshot,
            ],
            context: new ReusableMessageTemplateAuthoringContext(
                contextKey: 'flow_routes',
                purpose: 'transactional',
                scope: 'general',
                dispatchKey: 'flow_route_send_message',
                messageType: 'flow_route_message',
                payloadClass: EmailPayload::class,
                queue: 'emails',
                moduleKey: 'messaging',
                moduleLabel: 'Messaging',
                surface: 'route_send_message_points',
                groupKey: 'test:direct',
                groupLabel: 'Test Direct Messages',
                usageType: 'test_reuse',
                selectionContexts: ['flow_routes'],
            ),
            createdBy: $user,
        );

        $publishedVersion = $preset->canonicalTemplate->currentVersion;
        $this->assertSame($assetUuid, data_get($publishedVersion->payload(), 'media.asset_uuid'));
        $this->assertSame(
            $assetUuid,
            data_get(
                app(ReusableMessageTemplateCatalog::class)->definitions(['email'], 'transactional')[0],
                'payload.media.asset_uuid',
            ),
        );

        $this->actingAs($user)
            ->post(route('crm.messaging.contacts.messages.store', $contact), [
                'direct_message' => [
                    'request_key' => (string) Str::uuid(),
                    'channel' => 'email',
                    'purpose' => 'transactional',
                    'template_preset_id' => $preset->getKey(),
                    'subject' => 'Customized subject',
                    'body' => 'Customized body',
                ],
            ])
            ->assertRedirect(route('crm.contacts.show', $contact));

        $keptMedia = ScheduledMessage::query()->sole();
        $this->assertSame($assetUuid, data_get($keptMedia->payload, 'media.asset_uuid'));
        $this->assertSame('Customized body', data_get($keptMedia->payload, 'body'));
        $this->assertNull($keptMedia->message_template_version_id);
        $this->assertSame($preset->getKey(), data_get($keptMedia->meta, 'message_template.preset_id'));
        $this->assertSame('Reusable body', data_get($publishedVersion->payload(), 'body'));

        $this->actingAs($user)
            ->post(route('crm.messaging.contacts.messages.store', $contact), [
                'direct_message' => [
                    'request_key' => (string) Str::uuid(),
                    'channel' => 'email',
                    'purpose' => 'transactional',
                    'template_preset_id' => $preset->getKey(),
                    'subject' => 'No media',
                    'body' => 'This instance removes the template media.',
                    'media_present' => '1',
                    'media_asset_uuid' => '',
                    'media_poster_asset_uuid' => '',
                    'media_title' => '',
                ],
            ])
            ->assertRedirect(route('crm.contacts.show', $contact));

        $removedMedia = ScheduledMessage::query()->latest('id')->firstOrFail();
        $this->assertArrayNotHasKey('media', $removedMedia->payload);
        $this->assertSame($assetUuid, data_get($publishedVersion->fresh()->payload(), 'media.asset_uuid'));
    }

    private function grantConsent(Contact $contact, string $channel, string $purpose): void
    {
        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => $channel,
            'purpose' => $purpose,
            'scope' => 'general',
            'consented_at' => now(),
            'source' => 'test',
            'meta' => null,
        ]);
    }
}