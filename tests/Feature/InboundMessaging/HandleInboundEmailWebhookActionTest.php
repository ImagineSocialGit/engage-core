<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Actions\Email\HandleInboundEmailWebhookAction;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandleInboundEmailWebhookActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_unsubscribe_revokes_all_marketing_email_consent_domains(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'unsubscribe@example.com',
        ]);

        foreach (['webinar', 'broadcast', 'campaign'] as $scope) {
            MessageConsent::query()->create([
                'contact_id' => $contact->getKey(),
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => $scope,
                'consented_at' => now()->subDay(),
                'source' => 'test',
            ]);
        }

        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'consented_at' => now()->subDay(),
            'source' => 'test',
        ]);

        app(HandleInboundEmailWebhookAction::class)->handle(
            event: [
                'type' => 'email.unsubscribed',
                'created_at' => now()->toISOString(),
                'data' => [
                    'to' => ['unsubscribe@example.com'],
                    'email_id' => 'email-provider-message-1',
                ],
            ],
            sourceEventId: 'provider-event-1',
            provider: 'resend',
        );

        $marketingRevocations = ConsentRevocation::query()
            ->where('contact_id', $contact->getKey())
            ->where('channel', 'email')
            ->where('purpose', 'marketing')
            ->get();

        $this->assertCount(3, $marketingRevocations);
        $this->assertEqualsCanonicalizing(
            ['webinar', 'broadcast', 'campaign'],
            $marketingRevocations->pluck('scope')->all(),
        );
        $this->assertTrue($marketingRevocations->every(
            fn (ConsentRevocation $revocation): bool => $revocation->reason === ConsentRevocation::REASON_PROVIDER_UNSUBSCRIBE
                && $revocation->source === 'resend_webhook'
                && data_get($revocation->meta, 'revocation_scope') === 'all_marketing_email_domains',
        ));

        $this->assertDatabaseMissing('consent_revocations', [
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
        ]);
    }
}
