<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\BuildConsentOptInMessageIntentAction;
use App\Modules\Messaging\Actions\DispatchConsentOptInMessageAction;
use App\Modules\Messaging\Actions\DispatchMessageIntentsAction;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Messaging\Data\Delivery\MessageDeliveryIntent;
use App\Modules\Messaging\Models\MessageConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DispatchConsentOptInMessageActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_and_dispatches_one_standalone_intent(): void
    {
        $contact = Contact::factory()->create();
        $grant = $this->grant($contact, becameActive: true);
        $intent = new MessageDeliveryIntent(
            key: 'consent.marketing.email.acknowledgement',
            recipient: $contact,
            definition: [
                'key' => 'opt_in',
                'dispatch_keys' => ['consent_granted'],
                'message_type' => 'opt_in',
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar',
                'payload_class' => 'ExamplePayload',
                'queue' => 'opt_in_messages',
                'payload' => [
                    'subject' => 'Subscribed',
                    'body' => 'Subscribed.',
                ],
            ],
            behavior: [
                'timing' => 'immediate',
            ],
        );

        $builder = Mockery::mock(BuildConsentOptInMessageIntentAction::class);
        $builder->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Contact $passedContact, MessageConsentGrantResult $passedGrant): bool => $passedContact->is($contact)
                && $passedGrant === $grant)
            ->andReturn($intent);

        $dispatcher = Mockery::mock(DispatchMessageIntentsAction::class);
        $dispatcher->shouldReceive('handle')
            ->once()
            ->with([$intent])
            ->andReturn([]);

        $result = (new DispatchConsentOptInMessageAction($builder, $dispatcher))->handle(
            contact: $contact,
            grant: $grant,
        );

        $this->assertSame([], $result);
    }

    public function test_it_does_not_dispatch_when_no_intent_is_built(): void
    {
        $contact = Contact::factory()->create();
        $grant = $this->grant($contact, becameActive: false);

        $builder = Mockery::mock(BuildConsentOptInMessageIntentAction::class);
        $builder->shouldReceive('handle')
            ->once()
            ->andReturnNull();

        $dispatcher = Mockery::mock(DispatchMessageIntentsAction::class);
        $dispatcher->shouldReceive('handle')->never();

        $result = (new DispatchConsentOptInMessageAction($builder, $dispatcher))->handle(
            contact: $contact,
            grant: $grant,
        );

        $this->assertSame([], $result);
    }

    private function grant(Contact $contact, bool $becameActive): MessageConsentGrantResult
    {
        $consent = MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'consented_at' => now(),
            'source' => 'test',
        ]);

        return new MessageConsentGrantResult(
            consent: $consent,
            channel: 'email',
            purpose: 'marketing',
            requestedScope: 'webinar_nurture',
            domain: 'webinar',
            wasActive: ! $becameActive,
            isActive: true,
            created: $becameActive,
            becameActive: $becameActive,
        );
    }
}
