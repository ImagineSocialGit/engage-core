<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Contracts\Email\EmailMessage;
use App\Modules\Messaging\Contracts\Email\ThreadedEmailMessage;
use App\Integrations\Messaging\Email\Resend\ResendEmailProvider;
use App\Modules\Messaging\Services\Email\EmailMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailMessagingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resend_api_and_smtp_mailers_are_both_selectable(): void
    {
        $this->assertSame(
            'resend',
            config('mail.mailers.resend.transport'),
        );
        $this->assertSame(
            'smtp',
            config('mail.mailers.resend_smtp.transport'),
        );
        $this->assertSame(
            'smtp.resend.com',
            config('mail.mailers.resend_smtp.host'),
        );
        $this->assertSame(587, config('mail.mailers.resend_smtp.port'));
        $this->assertSame(
            'resend',
            config('mail.mailers.resend_smtp.username'),
        );

        Config::set('mail.default', 'resend_smtp');

        $this->assertSame('resend_smtp', config('mail.default'));
    }

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

        $result = app(EmailMessagingService::class)
            ->send($message);

        $this->assertTrue($result->isSent());
        $this->assertSame('resend', $result->provider);

        Mail::assertSent(
            $message->mailable()::class
        );
    }

    public function test_resend_provider_applies_standard_threading_headers(): void
    {
        Mail::fake();

        $mailable = new class extends Mailable {
            /** @var array<int, callable> */
            public array $capturedSymfonyCallbacks = [];

            public function build(): static
            {
                return $this->subject('Threaded reply');
            }

            public function withSymfonyMessage($callback)
            {
                $this->capturedSymfonyCallbacks[] = $callback;

                return parent::withSymfonyMessage($callback);
            }
        };

        $message = new class($mailable) implements EmailMessage, ThreadedEmailMessage {
            public function __construct(private readonly Mailable $mailable) {}

            public static function fromArray(array $payload): self
            {
                throw new \LogicException('Not used by this test.');
            }

            public function to(): string
            {
                return 'test@example.com';
            }

            public function mailable(): Mailable
            {
                return $this->mailable;
            }

            public function devPayload(): array
            {
                return [];
            }

            public function inReplyTo(): ?string
            {
                return '<incoming-1@example.test>';
            }

            public function references(): ?string
            {
                return '<incoming-1@example.test>';
            }
        };

        app(ResendEmailProvider::class)->send(
            message: $message,
            idempotencyKey: 'threading-test-key',
        );

        $email = new Email();

        foreach ($mailable->capturedSymfonyCallbacks as $callback) {
            $callback($email);
        }

        $this->assertSame(
            '<incoming-1@example.test>',
            $email->getHeaders()->get('In-Reply-To')?->getBodyAsString(),
        );
        $this->assertSame(
            '<incoming-1@example.test>',
            $email->getHeaders()->get('References')?->getBodyAsString(),
        );
        $this->assertSame(
            'threading-test-key',
            $email->getHeaders()->get('Resend-Idempotency-Key')?->getBodyAsString(),
        );
    }
}