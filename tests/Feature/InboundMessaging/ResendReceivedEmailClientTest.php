<?php

namespace Tests\Feature\InboundMessaging;

use App\Integrations\Messaging\Email\Resend\ResendReceivedEmailClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResendReceivedEmailClientTest extends TestCase
{
    public function test_it_retrieves_received_email_content_from_receiving_api(): void
    {
        config()->set('services.resend.key', 're_test_key');

        Http::fake([
            'https://api.resend.com/emails/receiving/received-email-1' => Http::response([
                'id' => 'received-email-1',
                'from' => 'sender@example.com',
                'to' => ['reply@example.com'],
                'text' => 'Human reply body',
                'html' => '<p>Human reply body</p>',
                'created_at' => now()->toISOString(),
            ]),
        ]);

        $received = app(ResendReceivedEmailClient::class)
            ->retrieve('received-email-1');

        $this->assertSame('Human reply body', $received['text']);

        Http::assertSent(fn ($request): bool =>
            $request->url() === 'https://api.resend.com/emails/receiving/received-email-1'
                && $request->hasHeader('Authorization', 'Bearer re_test_key')
        );
    }
}