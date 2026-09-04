<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Support\CtaTrackingLinkGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailDeliverabilityContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('modules.enabled', ['core', 'messaging']);
        Config::set('messaging.cta_tracking.enabled', true);
        Config::set('messaging.email.provider', 'resend');
        Config::set(
            'messaging.email.providers.resend.from.transactional.address',
            'transactional@example.test',
        );
        Config::set(
            'messaging.email.providers.resend.from.transactional.name',
            'Transactional Sender',
        );
        Config::set(
            'messaging.email.providers.resend.from.marketing.address',
            'marketing@example.test',
        );
        Config::set(
            'messaging.email.providers.resend.from.marketing.name',
            'Marketing Sender',
        );

        URL::forceScheme('https');
    }

    public function test_new_tracked_cta_links_use_the_messaging_public_host(): void
    {
        $message = ScheduledMessage::factory()->forContact()->create([
            'send_at' => now(),
        ]);
        $destination = 'https://example.test/register';

        $url = app(CtaTrackingLinkGenerator::class)->forScheduledMessage(
            scheduledMessageId: (int) $message->getKey(),
            ctaKey: 'register',
            destination: $destination,
        );

        $this->assertSame(
            'messaging.'.config('app.root_domain'),
            parse_url($url, PHP_URL_HOST),
        );
        $this->assertSame(
            '/messaging/click/'.$message->getKey().'/register',
            parse_url($url, PHP_URL_PATH),
        );

        $this->get($url)->assertRedirect($destination);
    }

    public function test_already_delivered_legacy_crm_tracking_links_still_redirect(): void
    {
        $message = ScheduledMessage::factory()->forContact()->create([
            'send_at' => now(),
        ]);
        $destination = 'https://example.test/replay';

        $legacyUrl = URL::signedRoute(
            name: 'messaging.cta.redirect.legacy',
            parameters: [
                'message' => $message->getKey(),
                'cta' => 'replay',
                'destination' => $destination,
            ],
        );

        $this->assertSame(
            'crm.'.config('app.root_domain'),
            parse_url($legacyUrl, PHP_URL_HOST),
        );
        $this->assertSame(
            '/messaging/click/'.$message->getKey().'/replay',
            parse_url($legacyUrl, PHP_URL_PATH),
        );

        $this->get($legacyUrl)->assertRedirect($destination);
    }

    public function test_marketing_email_adds_canonical_one_click_unsubscribe_headers(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'recipient@example.test',
        ]);
        $this->grantMarketingConsent($contact);

        $message = $this->captureSymfonyMessage(EmailPayload::fromArray([
            'to' => $contact->email,
            'channel' => 'email',
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'broadcast',
            'message_type' => 'broadcast',
            'contact_id' => $contact->getKey(),
            'subject' => 'A useful update',
            'body' => 'Here is the update you requested.',
        ]));

        $listUnsubscribe = $message->getHeaders()
            ->get('List-Unsubscribe')
            ?->getBodyAsString();
        $listUnsubscribePost = $message->getHeaders()
            ->get('List-Unsubscribe-Post')
            ?->getBodyAsString();

        $this->assertIsString($listUnsubscribe);
        $this->assertMatchesRegularExpression('/^<https:\/\/[^>]+>$/', $listUnsubscribe);
        $this->assertSame(
            'List-Unsubscribe=One-Click',
            $listUnsubscribePost,
        );

        $unsubscribeUrl = trim($listUnsubscribe, '<>');

        $this->assertSame(
            'messaging.'.config('app.root_domain'),
            parse_url($unsubscribeUrl, PHP_URL_HOST),
        );

        $this->post($unsubscribeUrl, [
            'List-Unsubscribe' => 'One-Click',
        ])->assertOk();

        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->getKey(),
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'channel_purpose',
            'source' => 'public_email_unsubscribe',
        ]);
    }

    public function test_transactional_email_does_not_emit_marketing_list_headers(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'recipient@example.test',
        ]);

        $message = $this->captureSymfonyMessage(EmailPayload::fromArray([
            'to' => $contact->email,
            'channel' => 'email',
            'purpose' => MessagePurpose::Transactional->value,
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'contact_id' => $contact->getKey(),
            'subject' => 'Registration confirmed',
            'body' => 'Your registration is confirmed.',
            'unsubscribe_url' => 'https://example.test/marketing-unsubscribe',
        ]));

        $this->assertFalse($message->getHeaders()->has('List-Unsubscribe'));
        $this->assertFalse($message->getHeaders()->has('List-Unsubscribe-Post'));
    }

    private function captureSymfonyMessage(EmailPayload $payload): Email
    {
        $captured = null;
        $mailable = $payload->mailable()->to($payload->to());

        $mailable->withSymfonyMessage(
            static function (Email $message) use (&$captured): void {
                $captured = $message;
            },
        );

        Mail::mailer('array')->send($mailable);

        $this->assertInstanceOf(Email::class, $captured);

        return $captured;
    }

    private function grantMarketingConsent(Contact $contact): void
    {
        DB::table('message_consents')->insert([
            'contact_id' => $contact->getKey(),
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'broadcast',
            'consented_at' => now(),
            'source' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}