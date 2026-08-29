<?php

namespace Tests\Feature\Broadcasts;

use App\Modules\Broadcasts\Actions\ScheduleBroadcastAction;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Services\BroadcastMessageTemplateVersionService;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\ScheduledMessagePayloadResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BroadcastVersionedPersistenceTest extends TestCase
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
            'messaging.email.from.marketing.address' => 'marketing@example.test',
            'messaging.email.from.marketing.name' => 'Marketing',
            'messaging.bulk_delivery.chunk_size' => 200,
        ]);

        Queue::fake();
    }

    public function test_one_hundred_broadcast_recipients_share_one_pinned_version_without_copying_authored_content(): void
    {
        $contacts = collect();

        foreach (range(1, 100) as $index) {
            $contact = Contact::factory()->create([
                'first_name' => sprintf('Person%03d', $index),
                'email' => sprintf('person%03d@example.test', $index),
            ]);

            MessageConsent::query()->create([
                'contact_id' => $contact->getKey(),
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'broadcast',
                'consented_at' => now(),
                'source' => 'test',
            ]);

            $contacts->push($contact);
        }

        $broadcast = Broadcast::factory()->create([
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'dispatch_key' => Broadcast::DEFAULT_DISPATCH_KEY,
            'message_type' => Broadcast::DEFAULT_MESSAGE_TYPE,
            'payload_class' => EmailPayload::class,
            'send_at' => now()->addHour(),
            'recipient_filter' => [
                'type' => 'contact_ids',
                'contact_ids' => $contacts->pluck('id')->all(),
            ],
            'payload' => [
                'subject' => 'Fixture update',
                'body' => 'Hello {first_name}, this is the fixture update.',
            ],
        ]);

        app(ScheduleBroadcastAction::class)->handle($broadcast);

        $messages = ScheduledMessage::query()
            ->where('context_type', $broadcast->getMorphClass())
            ->where('context_id', $broadcast->getKey())
            ->orderBy('id')
            ->get();

        $this->assertSame(100, $messages->count());

        $versionIds = $messages
            ->pluck('message_template_version_id')
            ->filter()
            ->unique()
            ->values();

        $this->assertSame(1, $versionIds->count());

        $template = MessageTemplate::query()
            ->where('source', BroadcastMessageTemplateVersionService::SOURCE)
            ->sole();
        $version = MessageTemplateVersion::query()->findOrFail(
            (int) $versionIds->first(),
        );

        $this->assertSame($template->getKey(), $version->message_template_id);
        $this->assertSame($version->getKey(), $template->current_version_id);
        $this->assertEquals($broadcast->payload, $version->payload());

        foreach ($messages as $message) {
            $this->assertSame($version->getKey(), $message->message_template_version_id);
            $this->assertArrayNotHasKey('subject', $message->payload ?? []);
            $this->assertArrayNotHasKey('body', $message->payload ?? []);
            $this->assertArrayNotHasKey('tokens', $message->payload ?? []);
            $this->assertArrayNotHasKey('token_fallbacks', $message->payload ?? []);
            $this->assertArrayNotHasKey('broadcast_id', $message->meta ?? []);
            $this->assertArrayNotHasKey('broadcast_recipient_id', $message->meta ?? []);
        }

        $firstContact = $contacts->first();
        $firstMessage = $messages->firstWhere(
            'recipient_id',
            $firstContact->getKey(),
        );

        $this->assertInstanceOf(ScheduledMessage::class, $firstMessage);

        $resolved = app(ScheduledMessagePayloadResolver::class)->resolve($firstMessage);

        $this->assertSame('Fixture update', $resolved->subject());
        $this->assertSame(
            'Hello Person001, this is the fixture update.',
            $resolved->text(),
        );
    }
}