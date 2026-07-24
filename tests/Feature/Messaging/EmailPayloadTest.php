<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Symfony\Component\Mime\Email;
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
            'messaging.email.definitions.transactional.webinar.confirmation.payload.subject',
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
            'messaging.email.definitions.transactional.webinar.confirmation.payload.body',
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
            'messaging.email.definitions.transactional.webinar.confirmation.payload.subject',
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

    public function test_it_reads_only_canonical_tokens(): void
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
        $this->assertCount(1, $payload->tokens);
    }

    public function test_it_uses_default_view(): void
    {
        Config::set(
            'messaging.email.definitions.transactional.webinar.confirmation.payload.view',
            null
        );

        Config::set(
            'messaging.email.definitions.transactional.webinar.confirmation.payload.subject',
            'Subject'
        );

        Config::set(
            'messaging.email.definitions.transactional.webinar.confirmation.payload.body',
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
            'messaging.email.definitions.transactional.webinar.confirmation.payload.subject',
            'Hello'
        );

        Config::set(
            'messaging.email.definitions.transactional.webinar.confirmation.payload.body',
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
            'messaging.email.definitions.transactional.webinar.confirmation.payload.subject',
            'Hello'
        );

        Config::set(
            'messaging.email.definitions.transactional.webinar.confirmation.payload.body',
            'Body'
        );

        $payload = EmailPayload::fromArray([
            'email' => 'test@example.com',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
        ]);

        $this->assertInstanceOf(
            \App\Modules\Messaging\Contracts\Email\EmailMessage::class,
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
            'messaging.email.definitions.transactional.missing_scope.missing_message.payload.subject',
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

    public function test_it_resolves_from_sender_by_purpose(): void
    {
        Config::set(
            'messaging.email.definitions.transactional.webinar.confirmation.payload.subject',
            'Transactional Subject'
        );

        Config::set(
            'messaging.email.definitions.transactional.webinar.confirmation.payload.body',
            'Transactional Body'
        );

        Config::set(
            'messaging.email.definitions.marketing.webinar.follow_up.payload.subject',
            'Marketing Subject'
        );

        Config::set(
            'messaging.email.definitions.marketing.webinar.follow_up.payload.body',
            'Marketing Body'
        );

        $transactional = EmailPayload::fromArray([
            'email' => 'test@example.com',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
        ]);

        $marketing = EmailPayload::fromArray([
            'email' => 'test@example.com',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'message_type' => 'follow_up',
        ]);

        $this->assertSame(
            'transactional@example.com',
            $transactional->devPayload()['from']['address']
        );

        $this->assertSame(
            'Transactional Sender',
            $transactional->devPayload()['from']['name']
        );

        $this->assertSame(
            'marketing@example.com',
            $marketing->devPayload()['from']['address']
        );

        $this->assertSame(
            'Marketing Sender',
            $marketing->devPayload()['from']['name']
        );
    }


    public function test_it_renders_inline_cta_and_secondary_link(): void
    {
        $payload = EmailPayload::fromArray([
            'email' => 'test@example.com',
            'subject' => 'Confirm your preferences',
            'body' => "Please confirm your preferences.
{cta}
Thanks.",
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'message_type' => 'imported_contact_permission_invitation',
            'cta' => [
                'label' => 'Confirm preferences',
                'url' => 'https://example.test/preferences/token',
            ],
            'secondary_link' => [
                'label' => 'Copy this link',
                'url' => 'https://example.test/preferences/token',
            ],
        ]);

        $html = $payload->html();

        $this->assertStringContainsString('Confirm preferences', $html);
        $this->assertStringContainsString('href="https://example.test/preferences/token"', $html);
        $this->assertStringContainsString('Copy this link', $html);
        $this->assertStringContainsString('https://example.test/preferences/token', $html);
        $this->assertStringNotContainsString('{cta}', $html);
    }

    public function test_it_renders_cta_after_body_when_inline_token_is_missing(): void
    {
        $payload = EmailPayload::fromArray([
            'email' => 'test@example.com',
            'subject' => 'Confirm your preferences',
            'body' => 'Please confirm your preferences.',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'message_type' => 'imported_contact_permission_invitation',
            'cta' => [
                'label' => 'Confirm preferences',
                'url' => 'https://example.test/preferences/token',
            ],
        ]);

        $html = $payload->html();

        $this->assertStringContainsString('Please confirm your preferences.', $html);
        $this->assertStringContainsString('Confirm preferences', $html);
        $this->assertStringContainsString('href="https://example.test/preferences/token"', $html);
    }

    public function test_it_renders_readable_plain_text_links_instead_of_bracketed_html_fallbacks(): void
    {
        $payload = EmailPayload::fromArray([
            'email' => 'test@example.com',
            'subject' => 'Starting Soon',
            'body' => "Hi Jeff,\n\nWe start in 10 minutes.\n\n{cta}\n\nSee you soon.",
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'reminder',
            'cta' => [
                'label' => 'Join Webinar',
                'url' => 'https://webinar.example.test/j/example-token#join_proof=proof-token',
            ],
            'secondary_link' => [
                'label' => 'Need to cancel?',
                'url' => 'https://webinar.example.test/cancel/example-token',
            ],
        ]);

        $plainText = $payload->plainText();

        $this->assertStringContainsString(
            "Join Webinar:\nhttps://webinar.example.test/j/example-token#join_proof=proof-token",
            $plainText,
        );
        $this->assertStringContainsString(
            "Need to cancel?:\nhttps://webinar.example.test/cancel/example-token",
            $plainText,
        );
        $this->assertStringNotContainsString(
            '[https://webinar.example.test/j/example-token]Join Webinar',
            $plainText,
        );
        $this->assertStringNotContainsString('{cta}', $plainText);
    }

    public function test_combined_delivery_renders_both_transactional_and_marketing_preference_links(): void
    {
        $payload = EmailPayload::fromArray([
            'email' => 'test@example.com',
            'subject' => 'Registration confirmed',
            'body' => 'Your registration is confirmed.',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'transactional_opt_out_url' => 'https://example.test/webinar-opt-out',
            'unsubscribe_url' => 'https://example.test/marketing-unsubscribe',
        ]);

        $plainText = $payload->plainText();
        $html = $payload->html();

        $this->assertStringContainsString(
            "Don't want these emails?\nhttps://example.test/webinar-opt-out",
            $plainText,
        );
        $this->assertStringContainsString(
            "Unsubscribe from marketing emails:\nhttps://example.test/marketing-unsubscribe",
            $plainText,
        );

        $this->assertStringContainsString(
            'href="https://example.test/webinar-opt-out"',
            $html,
        );
        $this->assertStringContainsString(
            'href="https://example.test/marketing-unsubscribe"',
            $html,
        );
        $this->assertStringContainsString(
            'unsubscribe from marketing emails here',
            $html,
        );
    }

    public function test_mailable_declares_an_explicit_plain_text_alternative(): void
    {
        $payload = EmailPayload::fromArray([
            'email' => 'test@example.com',
            'subject' => 'Starting Soon',
            'body' => "We start soon.\n\n{cta}",
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'reminder',
            'cta' => [
                'label' => 'Join Webinar',
                'url' => 'https://webinar.example.test/j/example-token',
            ],
        ]);

        $mailable = $payload->mailable()->build();

        $this->assertSame('email-text', $mailable->textView);
        $this->assertSame(
            $payload->plainText(),
            $mailable->viewData['content'] ?? null,
        );
    }

    public function test_application_serialization_keeps_both_mime_parts_unindented(): void
    {
        $payload = EmailPayload::fromArray([
            'email' => 'test@example.com',
            'subject' => 'Starting Soon',
            'body' => "We start soon.\n\n{cta}",
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'reminder',
            'cta' => [
                'label' => 'Join Webinar',
                'url' => 'https://webinar.example.test/j/example-token',
            ],
        ]);

        $capturedMessage = null;
        $mailable = $payload->mailable()->to($payload->to());

        $mailable->withSymfonyMessage(
            function (Email $message) use (&$capturedMessage): void {
                $capturedMessage = $message;
            },
        );

        Mail::mailer('array')->send($mailable);

        $this->assertInstanceOf(Email::class, $capturedMessage);

        $serialized = $capturedMessage->toString();
        $plainTextPosition = strpos($serialized, 'Content-Type: text/plain;');
        $htmlPosition = strpos($serialized, 'Content-Type: text/html;');

        $this->assertStringContainsString(
            'Content-Type: multipart/alternative;',
            $serialized,
        );
        $this->assertNotFalse($plainTextPosition);
        $this->assertNotFalse($htmlPosition);
        $this->assertLessThan($htmlPosition, $plainTextPosition);
        $this->assertDoesNotMatchRegularExpression(
            '/(?:\r\n|\n)[ \t]+Content-Type: text\/html;/',
            $serialized,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('messaging.email.provider', 'resend');

        Config::set('messaging.email.providers.resend.from.transactional.address', 'transactional@example.com');
        Config::set('messaging.email.providers.resend.from.transactional.name', 'Transactional Sender');

        Config::set('messaging.email.providers.resend.from.marketing.address', 'marketing@example.com');
        Config::set('messaging.email.providers.resend.from.marketing.name', 'Marketing Sender');
    }
}