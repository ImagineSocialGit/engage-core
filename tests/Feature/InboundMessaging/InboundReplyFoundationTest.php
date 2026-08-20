<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Actions\Email\RecordInboundEmailAction;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Services\Reply\InboundReplyIntentClassifier;
use App\Modules\InboundMessaging\Services\Reply\InboundReplyTextNormalizer;
use App\Modules\InboundMessaging\Services\Reply\InboundSmsReplyCorrelator;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Support\EmailReplyAddressGenerator;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class InboundReplyFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('app.key', 'base64:test-signing-key');
        config()->set('messaging.email.inbound_domain', 'replies.example.test');
        config()->set('messaging.reply_profiles.test_profile', [
            'intents' => [
                'high_intent' => [
                    'keywords' => ['ready', 'call me'],
                ],
            ],
        ]);
    }

    public function test_email_reply_records_exact_correlation_and_compact_event_identity(): void
    {
        Event::fake();

        $contact = Contact::factory()->create([
            'email' => 'reply@example.com',
        ]);
        $scheduled = ScheduledMessage::factory()
            ->forContact($contact)
            ->email()
            ->sent()
            ->create([
                'purpose' => 'marketing',
                'scope' => 'test_scope',
                'reply_profile_key' => 'test_profile',
            ]);
        $replyTo = app(EmailReplyAddressGenerator::class)
            ->forScheduledMessage($scheduled);

        $inbound = app(RecordInboundEmailAction::class)->handle(
            provider: 'test_provider',
            providerEventId: 'evt-email-1',
            providerMessageId: 'received-email-1',
            from: 'Reply Person <reply@example.com>',
            toAddresses: [$replyTo],
            text: "I am READY.\n\nOn Wed, Someone wrote:\n> old content",
            html: null,
        );

        $this->assertSame($scheduled->getKey(), $inbound->correlated_scheduled_message_id);
        $this->assertSame('exact', $inbound->reply_correlation_method);
        $this->assertSame('high_intent', $inbound->reply_intent_key);
        $this->assertSame('I am READY.', app(InboundReplyTextNormalizer::class)->normalize($inbound->body));

        $outbox = AutomationEventOutboxEvent::query()
            ->where('event_key', 'inbound_message.normal_reply')
            ->firstOrFail();

        $this->assertSame(
            $scheduled->getKey(),
            data_get($outbox->payload, 'inbound_message.scheduled_message_id'),
        );
        $this->assertSame(
            'test_profile',
            data_get($outbox->payload, 'inbound_message.reply_profile_key'),
        );
        $this->assertNull(data_get($outbox->payload, 'inbound_message.body'));
    }

    public function test_sms_correlation_is_explicitly_heuristic_and_bounded_to_recent_sent_delivery(): void
    {
        $contact = Contact::factory()->create([
            'phone' => '+15551234567',
        ]);
        $scheduled = ScheduledMessage::factory()
            ->forContact($contact)
            ->sms()
            ->sent()
            ->create([
                'send_at' => now()->subHour(),
                'reply_profile_key' => 'test_profile',
            ]);
        $scheduled->latestDeliveryAttempt()->update([
            'destination' => '+15551234567',
        ]);

        $correlated = app(InboundSmsReplyCorrelator::class)->correlate(
            contact: $contact,
            fromValue: '+15551234567',
            receivedAt: now(),
        );

        $this->assertSame($scheduled->getKey(), $correlated?->getKey());
        $this->assertSame(
            'high_intent',
            app(InboundReplyIntentClassifier::class)->classify(
                $correlated?->reply_profile_key,
                'Please call me today.',
            ),
        );
        $this->assertNull(
            app(InboundReplyIntentClassifier::class)->classify(
                $correlated?->reply_profile_key,
                'Thanks for the information.',
            ),
        );
    }
}