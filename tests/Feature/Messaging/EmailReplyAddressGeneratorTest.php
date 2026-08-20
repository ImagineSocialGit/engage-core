<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Support\EmailReplyAddressGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailReplyAddressGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_reply_address_round_trips_to_sent_scheduled_message(): void
    {
        config()->set('client.key', 'test-client');
        config()->set('app.key', 'base64:test-signing-key');
        config()->set('messaging.email.inbound_domain', 'replies.example.test');

        $contact = Contact::factory()->create();
        $message = ScheduledMessage::factory()
            ->forContact($contact)
            ->email()
            ->sent()
            ->create();

        $generator = app(EmailReplyAddressGenerator::class);
        $address = $generator->forScheduledMessage($message);

        $this->assertIsString($address);
        $this->assertStringEndsWith('@replies.example.test', $address);
        $this->assertSame(
            $message->getKey(),
            $generator->resolve($address)?->getKey(),
        );

        $at = strpos($address, '@');
        $lastSignatureCharacter = $address[$at - 1];
        $replacement = $lastSignatureCharacter === '0' ? '1' : '0';
        $tampered = substr($address, 0, $at - 1)
            .$replacement
            .substr($address, $at);

        $this->assertNull($generator->resolve($tampered));
    }

    public function test_blank_inbound_domain_disables_tracked_reply_to_without_affecting_message(): void
    {
        config()->set('messaging.email.inbound_domain', null);

        $message = ScheduledMessage::factory()->email()->sent()->create();

        $this->assertNull(
            app(EmailReplyAddressGenerator::class)->forScheduledMessage($message),
        );
    }
}