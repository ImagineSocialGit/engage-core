<?php

namespace Tests\Feature\Messaging;

use App\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class EmailPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_from_array(): void
    {
        $payload = EmailPayload::fromArray([
            'email' => 'test@example.com',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'tokens' => [
                'first_name' => 'Jeff',
            ],
        ]);

        $this->assertSame(
            'test@example.com',
            $payload->to()
        );
    }

    public function test_it_requires_destination_email(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmailPayload::fromArray([]);
    }

    public function test_it_resolves_subject_from_config(): void
    {
        Config::set(
            'messaging.email.transactional.webinar.confirmation.payload.subject',
            'Welcome {first_name}'
        );

        $payload = EmailPayload::fromArray([
            'email' => 'test@example.com',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'tokens' => [
                'first_name' => 'Jeff',
            ],
        ]);

        $this->assertSame(
            'Welcome Jeff',
            $payload->subject()
        );
    }

    public function test_it_resolves_body_from_config(): void
    {
        Config::set(
            'messaging.email.transactional.webinar.confirmation.payload.body',
            'Hello {first_name}'
        );

        $payload = EmailPayload::fromArray([
            'email' => 'test@example.com',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'tokens' => [
                'first_name' => 'Jeff',
            ],
        ]);

        $this->assertSame(
            'Hello Jeff',
            $payload->text()
        );
    }

    public function test_it_uses_explicit_subject_before_config(): void
    {
        Config::set(
            'messaging.email.transactional.webinar.confirmation.payload.subject',
            'Config'
        );

        $payload = EmailPayload::fromArray([
            'email' => 'test@example.com',
            'subject' => 'Runtime',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
        ]);

        $this->assertSame(
            'Runtime',
            $payload->subject()
        );
    }

    public function test_it_merges_runtime_context_context_and_tokens(): void
    {
        $payload = EmailPayload::fromArray([
            'email' => 'test@example.com',

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

    public function test_it_uses_default_view(): void
    {
        Config::set(
            'messaging.email.transactional.webinar.confirmation.payload.view',
            null
        );

        Config::set(
            'messaging.email.transactional.webinar.confirmation.payload.subject',
            'Subject'
        );

        Config::set(
            'messaging.email.transactional.webinar.confirmation.payload.body',
            'Body'
        );

        $payload = EmailPayload::fromArray([
            'email' => 'test@example.com',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
        ]);

        $devPayload = $payload->devPayload();

        $this->assertSame(
            'email',
            $devPayload['view']
        );
    }

    public function test_it_renders_html(): void
    {
        Config::set(
            'messaging.email.transactional.webinar.confirmation.payload.subject',
            'Hello'
        );

        Config::set(
            'messaging.email.transactional.webinar.confirmation.payload.body',
            'Welcome {first_name}'
        );

        $payload = EmailPayload::fromArray([
            'email' => 'test@example.com',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'tokens' => [
                'first_name' => 'Jeff',
            ],
        ]);

        $html = $payload->html();

        $this->assertStringContainsString(
            'Welcome',
            $html
        );

        $this->assertStringContainsString(
            'Jeff',
            $html
        );
    }

    public function test_it_implements_email_message_contract(): void
    {
        Config::set(
            'messaging.email.transactional.webinar.confirmation.payload.subject',
            'Hello'
        );

        Config::set(
            'messaging.email.transactional.webinar.confirmation.payload.body',
            'Body'
        );

        $payload = EmailPayload::fromArray([
            'email' => 'test@example.com',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
        ]);

        $this->assertInstanceOf(
            \App\Contracts\Messaging\Email\EmailMessage::class,
            $payload
        );
    }

    public function test_it_throws_when_subject_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmailPayload::fromArray([
            'email' => 'test@example.com',
            'purpose' => 'transactional',
            'scope' => 'missing_scope',
            'message_type' => 'missing_message',
        ])->subject();
    }

    public function test_it_throws_when_body_missing(): void
    {
        Config::set(
            'messaging.email.transactional.missing_scope.missing_message.payload.subject',
            'Hello'
        );

        $this->expectException(InvalidArgumentException::class);

        EmailPayload::fromArray([
            'email' => 'test@example.com',
            'purpose' => 'transactional',
            'scope' => 'missing_scope',
            'message_type' => 'missing_message',
        ])->text();
    }
}