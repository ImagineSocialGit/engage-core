<?php

namespace Tests\Feature\Broadcasts;

use App\Models\User;
use App\Modules\Broadcasts\Actions\ScheduleBroadcastAction;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\ReusableMessageTemplateCatalog;
use App\Modules\Messaging\Services\ScheduledMessagePayloadResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BroadcastCtaAuthoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.modules.broadcasts.enabled' => true,
            'modules.modules.messaging.enabled' => true,
            'messaging.channel_availability.email.runtime_supported' => true,
            'messaging.channel_availability.email.surfaces.broadcasts' => true,
            'messaging.channel_availability.sms.runtime_supported' => true,
            'messaging.channel_availability.sms.surfaces.broadcasts' => true,
            'messaging.email.from.marketing.address' => 'marketing@example.test',
            'messaging.email.from.marketing.name' => 'Marketing',
            'messaging.bulk_delivery.chunk_size' => 200,
        ]);

        Queue::fake();
    }

    public function test_regular_email_broadcast_persists_primary_cta_and_exposes_authoring_contract(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->get(route('crm.broadcasts.index'))
            ->assertOk()
            ->assertSee('data-broadcast-cta-editor', false)
            ->assertSee('name="cta[label]"', false)
            ->assertSee('name="cta[url]"', false);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.store'), [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
                'intent' => 'draft',
                'name' => 'CTA fixture',
                'channel' => 'email',
                'subject' => 'Fixture subject',
                'body' => "Fixture body.\n\n{cta}",
                'cta_present' => '1',
                'cta' => [
                    'label' => 'See the details',
                    'url' => 'https://example.test/details?utm_source=broadcast',
                ],
                'recipient_filter_type' => 'all',
            ]);

        $broadcast = Broadcast::query()->sole();

        $response->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $cta = $broadcast->messagePayload()['cta'] ?? [];

        $this->assertSame('primary', $cta['tracking_key'] ?? null);
        $this->assertSame('See the details', $cta['label'] ?? null);
        $this->assertSame(
            'https://example.test/details?utm_source=broadcast',
            $cta['url'] ?? null,
        );

        $this
            ->actingAs($user)
            ->get(route('crm.broadcasts.show', $broadcast))
            ->assertOk()
            ->assertSee('data-broadcast-cta-preview', false)
            ->assertSee('href="https://example.test/details?utm_source=broadcast"', false);
    }

    public function test_regular_email_broadcast_rejects_invalid_cta_destination_without_persisting(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->from(route('crm.broadcasts.index'))
            ->post(route('crm.broadcasts.store'), [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
                'intent' => 'draft',
                'name' => 'Invalid CTA fixture',
                'channel' => 'email',
                'subject' => 'Fixture subject',
                'body' => 'Fixture body.',
                'cta_present' => '1',
                'cta' => [
                    'label' => 'Unsafe destination',
                    'url' => 'javascript:alert(1)',
                ],
                'recipient_filter_type' => 'all',
            ])
            ->assertRedirect(route('crm.broadcasts.index'))
            ->assertSessionHasErrors('cta.url');

        $this->assertSame(0, Broadcast::query()->count());
    }

    public function test_explicit_empty_cta_removes_existing_cta_and_sms_payload_does_not_keep_it(): void
    {
        $user = User::factory()->create();
        $broadcast = $this->emailBroadcastWithCta();

        $this
            ->actingAs($user)
            ->patch(route('crm.broadcasts.update', $broadcast), [
                'name' => $broadcast->name,
                'channel' => 'email',
                'subject' => 'Updated subject',
                'body' => 'Updated body.',
                'cta_present' => '1',
                'cta' => [
                    'label' => '',
                    'url' => '',
                ],
                'recipient_filter_type' => 'all',
            ])
            ->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $broadcast->refresh();
        $this->assertArrayNotHasKey('cta', $broadcast->messagePayload());

        $response = $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.store'), [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
                'intent' => 'draft',
                'name' => 'SMS CTA fixture',
                'channel' => 'sms',
                'message' => 'SMS fixture message',
                'cta_present' => '1',
                'cta' => [
                    'label' => 'Ignored',
                    'url' => 'https://example.test/ignored',
                ],
                'recipient_filter_type' => 'all',
            ]);

        $smsBroadcast = Broadcast::query()
            ->where('channel', 'sms')
            ->sole();

        $response->assertRedirect(route('crm.broadcasts.show', $smsBroadcast));
        $this->assertArrayNotHasKey('cta', $smsBroadcast->messagePayload());
    }

    public function test_saved_reusable_broadcast_message_preserves_primary_cta(): void
    {
        $user = User::factory()->create();
        $broadcast = $this->emailBroadcastWithCta();

        $this
            ->actingAs($user)
            ->post(route('crm.broadcasts.save-message-template', $broadcast), [
                'name' => 'Reusable CTA fixture',
            ])
            ->assertRedirect(route('crm.broadcasts.show', $broadcast));

        $definition = collect(app(ReusableMessageTemplateCatalog::class)->definitions(
            channels: ['email'],
            purpose: 'marketing',
            selectionContext: 'broadcasts',
        ))->sole();

        $savedCta = $definition['payload']['cta'] ?? [];

        $this->assertSame('primary', $savedCta['tracking_key'] ?? null);
        $this->assertSame('See the fixture', $savedCta['label'] ?? null);
        $this->assertSame('https://example.test/fixture', $savedCta['url'] ?? null);
    }

    public function test_scheduled_broadcast_uses_existing_messaging_cta_tracking_at_render_time(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'cta-recipient@example.test',
        ]);

        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'consented_at' => now(),
            'source' => 'test',
        ]);

        $broadcast = Broadcast::factory()->withMessage([
            'subject' => 'Tracked CTA fixture',
            'body' => "Fixture body.\n\n{cta}",
            'cta' => [
                'tracking_key' => 'primary',
                'label' => 'Open fixture',
                'url' => 'https://example.test/tracked-destination',
            ],
        ])->create([
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'dispatch_key' => Broadcast::DEFAULT_DISPATCH_KEY,
            'message_type' => Broadcast::DEFAULT_MESSAGE_TYPE,
            'payload_class' => EmailPayload::class,
            'send_at' => now()->addHour(),
            'recipient_filter' => [
                'type' => 'contact_ids',
                'contact_ids' => [$contact->getKey()],
            ],
        ]);

        app(ScheduleBroadcastAction::class)->handle($broadcast);

        $scheduledMessage = ScheduledMessage::query()
            ->where('context_type', $broadcast->getMorphClass())
            ->where('context_id', $broadcast->getKey())
            ->sole();
        $resolved = app(ScheduledMessagePayloadResolver::class)->resolve($scheduledMessage);

        $this->assertInstanceOf(EmailPayload::class, $resolved);

        $cta = $resolved->devPayload()['cta'];

        $this->assertSame('primary', $cta['tracking_key']);
        $this->assertSame('Open fixture', $cta['label']);
        $this->assertNotSame('https://example.test/tracked-destination', $cta['url']);
        $this->assertStringContainsString('/messaging/click/', $cta['url']);
    }

    private function emailBroadcastWithCta(): Broadcast
    {
        return Broadcast::factory()->withMessage([
            'subject' => 'CTA fixture subject',
            'body' => "CTA fixture body.\n\n{cta}",
            'cta' => [
                'tracking_key' => 'primary',
                'label' => 'See the fixture',
                'url' => 'https://example.test/fixture',
            ],
        ])->create([
            'name' => 'CTA fixture broadcast',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'dispatch_key' => Broadcast::DEFAULT_DISPATCH_KEY,
            'message_type' => Broadcast::DEFAULT_MESSAGE_TYPE,
            'payload_class' => EmailPayload::class,
            'recipient_filter' => ['type' => 'all'],
            'meta' => [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
            ],
        ]);
    }
}