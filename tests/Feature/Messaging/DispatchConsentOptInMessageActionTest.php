<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchConsentOptInMessageAction;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Services\ConsentOptInDefinitionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DispatchConsentOptInMessageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_only_for_a_newly_active_grant(): void
    {
        $contact = Contact::factory()->create();
        $consent = MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'consented_at' => now(),
            'source' => 'test',
        ]);

        $definitionResolver = Mockery::mock(ConsentOptInDefinitionResolver::class);
        $definitionResolver->shouldReceive('resolve')
            ->once()
            ->with('email', 'marketing', 'webinar_nurture')
            ->andReturn([
                'dispatch_key' => 'consent_granted',
                'message_type' => 'opt_in',
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar',
                'payload_class' => 'ExamplePayload',
                'queue' => 'opt_in_messages',
                'payload' => ['subject' => 'Subscribed', 'body' => 'Subscribed.'],
            ]);

        $dispatch = Mockery::mock(DispatchMessageAction::class);
        $dispatch->shouldReceive('handle')
            ->once()
            ->withArgs(function (
                Contact $recipient,
                string $channel,
                string $purpose,
                string $scope,
                string|array $dispatchKeys,
                array $payload,
                mixed $context,
                mixed $triggeredAt,
                mixed $anchor,
                ?array $meta,
            ) use ($contact, $consent): bool {
                return $recipient->is($contact)
                    && $channel === 'email'
                    && $purpose === 'marketing'
                    && $scope === 'webinar'
                    && $dispatchKeys === 'consent_granted'
                    && $payload === ['tokens' => ['first_name' => 'Jeff']]
                    && data_get($meta, 'consent.message_consent_id') === $consent->getKey();
            })
            ->andReturn([]);

        $action = new DispatchConsentOptInMessageAction($dispatch, $definitionResolver);

        $result = $action->handle(
            contact: $contact,
            grant: new MessageConsentGrantResult(
                consent: $consent,
                channel: 'email',
                purpose: 'marketing',
                requestedScope: 'webinar_nurture',
                domain: 'webinar',
                wasActive: false,
                isActive: true,
                created: true,
                becameActive: true,
            ),
            payload: ['tokens' => ['first_name' => 'Jeff']],
        );

        $this->assertSame([], $result);
    }

    public function test_it_does_not_resolve_or_dispatch_for_an_already_active_grant(): void
    {
        $contact = Contact::factory()->create();
        $consent = MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'consented_at' => now(),
            'source' => 'test',
        ]);

        $definitionResolver = Mockery::mock(ConsentOptInDefinitionResolver::class);
        $definitionResolver->shouldReceive('resolve')->never();

        $dispatch = Mockery::mock(DispatchMessageAction::class);
        $dispatch->shouldReceive('handle')->never();

        $action = new DispatchConsentOptInMessageAction($dispatch, $definitionResolver);

        $result = $action->handle(
            contact: $contact,
            grant: new MessageConsentGrantResult(
                consent: $consent,
                channel: 'email',
                purpose: 'marketing',
                requestedScope: 'webinar_nurture',
                domain: 'webinar',
                wasActive: true,
                isActive: true,
                created: false,
                becameActive: false,
            ),
        );

        $this->assertSame([], $result);
    }
}
