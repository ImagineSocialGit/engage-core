<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Contracts\Sms\SmsMessage;
use App\Modules\Messaging\Contracts\Sms\SmsProvider;
use App\Modules\Messaging\Data\Delivery\MessageSendResult;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\DevMessageSink;
use App\Modules\Messaging\Services\PhoneNumberNormalizer;
use App\Modules\Messaging\Services\Sms\SmsMessagingService;
use App\Modules\Messaging\Services\Sms\SmsProviderManager;
use App\Modules\Messaging\Services\Sms\SmsSendGuard;
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

        $result = $service->send(new FakeSmsPayload(
            to: '(555) 555-0123',
            message: 'Test message',
            kind: 'test_message',
        ));

        $this->assertTrue($result->isSent());
        $this->assertSame('telnyx', $result->provider);
        $this->assertTrue($provider->sent);
        $this->assertSame('+15555550123', $provider->to);
        $this->assertSame('Test message', $provider->message);
        $this->assertSame([
            'kind' => 'test_message',
            'purpose' => 'transactional',
            'source_ip' => null,
        ], $provider->meta);
    }


    public function test_it_sends_rendered_sms_body_to_configured_provider(): void
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

        $result = $service->send(SmsPayload::fromArray([
            'to' => '(555) 555-0123',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'message' => 'Hi {first_name}, join here: {webinar_join_url}',
            'tokens' => [
                'first_name' => 'Jeff',
                'webinar_join_url' => 'https://example.test/join/abc123',
            ],
        ]));

        $this->assertTrue($result->isSent());
        $this->assertTrue($provider->sent);
        $this->assertSame('+15555550123', $provider->to);
        $this->assertSame('Hi Jeff, join here: https://example.test/join/abc123', $provider->message);
        $this->assertStringNotContainsString('{first_name}', $provider->message);
        $this->assertStringNotContainsString('{webinar_join_url}', $provider->message);
        $this->assertSame([
            'kind' => 'confirmation',
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

        $result = $service->send(new FakeSmsPayload(
            to: '+15555550123',
            message: 'Test message',
            kind: 'test_message',
        ));

        $this->assertTrue($result->isSkipped());
        $this->assertSame('sms_disabled', $result->reasonCode);
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

    public function send(string $to, string $message, array $meta = []): MessageSendResult
    {
        $this->sent = true;
        $this->to = $to;
        $this->message = $message;
        $this->meta = $meta;

        return MessageSendResult::sent(
            provider: $this->provider,
            providerMessageId: 'provider-message-id',
        );
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


