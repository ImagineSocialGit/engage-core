<?php

namespace Tests\Feature\InboundMessaging;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Services\Email\InboundEmailSampleParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class InboundEmailSampleAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', [
            'messaging',
            'inbound_messaging',
        ]);
        config()->set(
            'messaging.email.inbound_domain',
            'inbound.example.test',
        );

        $this->withoutMiddleware(ForceStagingAccess::class);
    }

    public function test_eml_sample_prefills_deterministic_extraction_without_persisting_or_creating_contacts(): void
    {
        $route = $this->route();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(
            route(
                'crm.inbound-messaging.email-routes.contact-extraction.assist',
                $route,
            ),
            [
                'form_mode' => 'contact_extraction_assist:'.$route->getKey(),
                'sample_eml' => UploadedFile::fake()->createWithContent(
                    'lead.eml',
                    $this->multipartSample(),
                ),
            ],
        );

        $response
            ->assertOk()
            ->assertSee('Suggested setup is prefilled below.')
            ->assertSee('Nothing from the uploaded sample has been stored.')
            ->assertSee('Body after a label &quot;First Name&quot;', false)
            ->assertSee('Body after a label &quot;Last Name&quot;', false)
            ->assertSee('Body after a label &quot;Email&quot;', false)
            ->assertSee('Body after a label &quot;Phone&quot;', false)
            ->assertSee('jane@example.com')
            ->assertSee('Sample matched the suggested setup.');

        $route->refresh();

        $this->assertFalse($route->contact_extraction_enabled);
        $this->assertNull($route->contact_extraction_definition);
        $this->assertSame(0, Contact::query()->count());
        $this->assertSame(0, InboundMessage::query()->count());
    }

    public function test_sample_parser_prefers_plain_text_and_ignores_attachments(): void
    {
        $sample = app(InboundEmailSampleParser::class)->parse(
            $this->multipartSample(),
        );

        $this->assertSame(
            'Lead Source <notifications@example.test>',
            $sample['from'],
        );
        $this->assertSame('jane@example.com', $sample['reply_to']);
        $this->assertSame('New Lead', $sample['subject']);
        $this->assertSame('text/plain', $sample['body_source']);
        $this->assertStringContainsString(
            'First Name: Jane',
            (string) $sample['body'],
        );
        $this->assertStringNotContainsString(
            'attachment-only-value',
            (string) $sample['body'],
        );
    }

    public function test_assistant_rejects_non_eml_uploads(): void
    {
        $route = $this->route();

        $this->actingAs(User::factory()->create())
            ->from(route('crm.inbound-messaging.email-routes.index'))
            ->post(
                route(
                    'crm.inbound-messaging.email-routes.contact-extraction.assist',
                    $route,
                ),
                [
                    'form_mode' => 'contact_extraction_assist:'.$route->getKey(),
                    'sample_eml' => UploadedFile::fake()->createWithContent(
                        'lead.txt',
                        'not an eml',
                    ),
                ],
            )
            ->assertRedirect(route('crm.inbound-messaging.email-routes.index'))
            ->assertSessionHasErrors('sample_eml');

        $this->assertFalse($route->refresh()->contact_extraction_enabled);
    }

    private function route(): InboundEmailRoute
    {
        return InboundEmailRoute::query()->create([
            'key' => 'website_leads',
            'local_part' => 'website-leads',
            'label' => 'Website Leads',
            'source' => 'crm',
            'context_key' => null,
            'is_active' => true,
            'contact_extraction_enabled' => false,
            'contact_extraction_definition' => null,
        ]);
    }

    private function multipartSample(): string
    {
        $plain = implode("\r\n", [
            'First Name: Jane',
            'Last Name: Doe',
            'Email: jane@example.com',
            'Phone: (555) 555-1212',
        ]);

        $html = implode('', [
            '<p>First Name: Wrong Html Name</p>',
            '<p>Email: wrong-html@example.com</p>',
        ]);

        return implode("\r\n", [
            'From: Lead Source <notifications@example.test>',
            'Reply-To: jane@example.com',
            'Subject: =?UTF-8?Q?New_Lead?=',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="outer-boundary"',
            '',
            '--outer-boundary',
            'Content-Type: multipart/alternative; boundary="alternative-boundary"',
            '',
            '--alternative-boundary',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            quoted_printable_encode($plain),
            '--alternative-boundary',
            'Content-Type: text/html; charset=UTF-8',
            '',
            $html,
            '--alternative-boundary--',
            '--outer-boundary',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Disposition: attachment; filename="ignored.txt"',
            '',
            'attachment-only-value',
            '--outer-boundary--',
            '',
        ]);
    }
}