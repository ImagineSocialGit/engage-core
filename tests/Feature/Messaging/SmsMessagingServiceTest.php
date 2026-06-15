<?php

namespace Tests\Feature\Messaging;

use App\Contracts\Messaging\Sms\SmsMessage;
use App\Contracts\Messaging\Sms\SmsProvider;
use App\Services\Messaging\DevMessageSink;
use App\Services\Messaging\PhoneNumberNormalizer;
use App\Services\Messaging\Sms\SmsMessagingService;
use App\Services\Messaging\Sms\SmsProviderManager;
use App\Services\Messaging\Sms\SmsSendGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsMessagingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_sms_through_configured_provider(): void
    {
        config([
            'sms.enabled' => true,
            'sms.provider' => 'telnyx',
        ]);

        $provider = new FakeSmsProvider('telnyx');

        $service = new SmsMessagingService(
            devMessageSink: app(DevMessageSink::class),
            phoneNumberNormalizer: app(PhoneNumberNormalizer::class),
            smsProviderManager: new SmsProviderManager([
                'telnyx' => $provider,
            ]),
            smsSendGuard: app(SmsSendGuard::class),
        );

        $service->send(new FakeSmsPayload(
            to: '(555) 555-0123',
            message: 'Test message',
            kind: 'test_message',
        ));

        $this->assertTrue($provider->sent);
        $this->assertSame('+15555550123', $provider->to);
        $this->assertSame('Test message', $provider->message);
        $this->assertSame([
            'kind' => 'test_message',
            'purpose' => 'transactional',
            'source_ip' => null,
        ], $provider->meta);
    }

    public function test_it_does_not_send_when_sms_is_disabled(): void
    {
        config(['sms.enabled' => false]);

        $provider = new FakeSmsProvider('twilio');

        $service = new SmsMessagingService(
            devMessageSink: app(DevMessageSink::class),
            phoneNumberNormalizer: app(PhoneNumberNormalizer::class),
            smsProviderManager: new SmsProviderManager([
                'twilio' => $provider,
            ]),
            smsSendGuard: app(SmsSendGuard::class),
        );

        $service->send(new FakeSmsPayload(
            to: '+15555550123',
            message: 'Test message',
            kind: 'test_message',
        ));

        $this->assertFalse($provider->sent);
    }
}

class FakeSmsProvider implements SmsProvider
{
    public bool $sent = false;

    public ?string $to = null;

    public ?string $message = null;

    public array $meta = [];

    public function __construct(
        private readonly string $provider,
    ) {}

    public function provider(): string
    {
        return $this->provider;
    }

    public function send(string $to, string $message, array $meta = []): void
    {
        $this->sent = true;
        $this->to = $to;
        $this->message = $message;
        $this->meta = $meta;
    }
}

class FakeSmsPayload implements SmsMessage
{
    public function __construct(
        private readonly string $to,
        private readonly string $message,
        private readonly string $kind,
        private readonly string $purpose = 'transactional',
        private readonly ?string $sourceIp = null,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            to: $payload['to'],
            message: $payload['message'],
            kind: $payload['kind'],
            purpose: $payload['purpose'] ?? 'transactional',
            sourceIp: $payload['source_ip'] ?? null,
        );
    }

    public function to(): string
    {
        return $this->to;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function devPayload(): array
    {
        return [
            'to' => $this->to,
            'message' => $this->message,
            'kind' => $this->kind,
            'purpose' => $this->purpose,
            'source_ip' => $this->sourceIp,
        ];
    }

    public function sourceIp(): ?string
    {
        return $this->sourceIp;
    }
}