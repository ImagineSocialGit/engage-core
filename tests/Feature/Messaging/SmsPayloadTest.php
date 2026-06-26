<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Contracts\Sms\SmsMessage;
use App\Modules\Messaging\Payloads\SmsPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class SmsPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_from_array(): void
    {
        $payload = SmsPayload::fromArray([
            'phone' => '+15555555555',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
        ]);

        $this->assertSame(
            '+15555555555',
            $payload->to()
        );
    }

    public function test_it_requires_destination_phone(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SmsPayload::fromArray([
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
        ]);
    }

    public function test_it_requires_purpose(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SmsPayload::fromArray([
            'phone' => '+15555555555',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
        ]);
    }

    public function test_it_requires_scope(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SmsPayload::fromArray([
            'phone' => '+15555555555',
            'purpose' => 'transactional',
            'message_type' => 'confirmation',
        ]);
    }

    public function test_it_requires_message_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SmsPayload::fromArray([
            'phone' => '+15555555555',
            'purpose' => 'transactional',
            'scope' => 'webinar',
        ]);
    }

    public function test_it_resolves_message_from_config(): void
    {
        Config::set(
            'messaging.sms.transactional.webinar.confirmation.payload.message',
            'Hello {first_name}'
        );

        $payload = SmsPayload::fromArray([
            'phone' => '+15555555555',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',

            'tokens' => [
                'first_name' => 'Jeff',
            ],
        ]);

        $this->assertSame(
            'Hello Jeff',
            $payload->message()
        );
    }

    public function test_it_prefers_runtime_message_over_config(): void
    {
        Config::set(
            'messaging.sms.transactional.webinar.confirmation.payload.message',
            'Config'
        );

        $payload = SmsPayload::fromArray([
            'phone' => '+15555555555',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',

            'message' => 'Runtime',
        ]);

        $this->assertSame(
            'Runtime',
            $payload->message()
        );
    }

    public function test_it_merges_runtime_context_context_and_tokens(): void
    {
        $payload = SmsPayload::fromArray([
            'phone' => '+15555555555',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',

            'runtime_context' => [
                'first_name' => 'Runtime',
            ],

            'context' => [
                'first_name' => 'Context',
            ],

            'tokens' => [
                'first_name' => 'Tokens',
            ],
        ]);

        $this->assertSame(
            'Tokens',
            $payload->tokens['first_name']
        );
    }

    public function test_it_supports_brand_prefix(): void
    {
        Config::set('brand.name', 'LeadFlow');

        Config::set(
            'messaging.sms.transactional.webinar.confirmation.payload.message',
            'Welcome'
        );

        Config::set(
            'messaging.sms.transactional.webinar.confirmation.payload.prefix_brand',
            true
        );

        $payload = SmsPayload::fromArray([
            'phone' => '+15555555555',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
        ]);

        $this->assertSame(
            'LeadFlow: Welcome',
            $payload->message()
        );
    }

    public function test_it_builds_dev_payload(): void
    {
        Config::set(
            'messaging.sms.transactional.webinar.confirmation.payload.message',
            'Hello'
        );

        $payload = SmsPayload::fromArray([
            'phone' => '+15555555555',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
        ]);

        $dev = $payload->devPayload();

        $this->assertSame(
            'confirmation',
            $dev['message_type']
        );

        $this->assertSame(
            'Hello',
            $dev['message']
        );
    }

    public function test_it_implements_sms_contract(): void
    {
        Config::set(
            'messaging.sms.transactional.webinar.confirmation.payload.message',
            'Hello'
        );

        $payload = SmsPayload::fromArray([
            'phone' => '+15555555555',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
        ]);

        $this->assertInstanceOf(
            SmsMessage::class,
            $payload
        );
    }

    public function test_it_throws_when_message_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SmsPayload::fromArray([
            'phone' => '+15555555555',
            'purpose' => 'transactional',
            'scope' => 'missing_scope',
            'message_type' => 'missing_message',
        ])->message();
    }
}