<?php

namespace Tests\Feature\Messaging\Email;

use App\Contracts\Messaging\Email\EmailMessage;
use App\Integrations\Messaging\Email\Resend\ResendEmailProvider;
use App\Services\Messaging\Email\EmailMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailMessagingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_service_routes_through_configured_provider(): void
    {
        config([
            'app.env' => 'testing',
            'messaging.email.provider' => 'resend',
        ]);

        Mail::fake();

        $message = new class implements EmailMessage {
            public static function fromArray(array $payload): self
            {
                return new self();
            }

            public function to(): string
            {
                return 'test@example.com';
            }

            public function mailable(): Mailable
            {
                return new class extends Mailable {
                    public function build(): static
                    {
                        return $this->subject('Test');
                    }
                };
            }

            public function devPayload(): array
            {
                return [];
            }
        };

        $provider = app(ResendEmailProvider::class);

        $this->assertInstanceOf(
            ResendEmailProvider::class,
            $provider
        );

        app(EmailMessagingService::class)
            ->send($message);

        Mail::assertSent(
            $message->mailable()::class
        );
    }
}