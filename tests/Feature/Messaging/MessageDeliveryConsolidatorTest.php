<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Data\Delivery\MessageDeliveryIntent;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\MessageDeliveryConsolidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MessageDeliveryConsolidatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('client.name', 'Example Company');
        Config::set(
            'messaging.delivery_consolidation',
            require base_path('config/messaging/delivery_consolidation.php'),
        );
    }

    public function test_disabled_policy_preserves_independent_intents(): void
    {
        $contact = Contact::factory()->create();
        $intents = [
            $this->intent($contact, 'webinar.registration.confirmation', 'email', 'transactional', EmailPayload::class),
            $this->intent($contact, 'consent.transactional.email.acknowledgement', 'email', 'transactional', EmailPayload::class),
        ];

        $resolved = app(MessageDeliveryConsolidator::class)->consolidate(
            $intents,
            'webinar_registration',
        );

        $this->assertSame($intents, $resolved);
    }

    public function test_email_policy_combines_registration_and_compatible_consent_intents(): void
    {
        Config::set('messaging.delivery_consolidation.policies.webinar_registration.enabled', true);

        $contact = Contact::factory()->create();

        $resolved = app(MessageDeliveryConsolidator::class)->consolidate([
            $this->intent(
                contact: $contact,
                key: 'webinar.registration.confirmation',
                channel: 'email',
                purpose: 'transactional',
                payloadClass: EmailPayload::class,
                occurrenceKey: 'webinar_registration:55',
            ),
            $this->intent(
                contact: $contact,
                key: 'consent.transactional.email.acknowledgement',
                channel: 'email',
                purpose: 'transactional',
                payloadClass: EmailPayload::class,
                consentId: 11,
            ),
            $this->intent(
                contact: $contact,
                key: 'consent.marketing.email.acknowledgement',
                channel: 'email',
                purpose: 'marketing',
                payloadClass: EmailPayload::class,
                consentId: 12,
            ),
        ], 'webinar_registration');

        $this->assertCount(1, $resolved);

        $intent = $resolved[0];

        $this->assertSame('transactional', $intent->purpose());
        $this->assertSame('webinar', $intent->scope());
        $this->assertSame('confirmation', $intent->definition['message_type']);
        $this->assertSame('webinar_registration:55:delivery_consolidation:initial_email', $intent->occurrenceKey);
        $this->assertSame(
            [
                'consent.marketing.email.acknowledgement',
                'consent.transactional.email.acknowledgement',
                'webinar.registration.confirmation',
            ],
            collect(data_get($intent->meta, 'delivery_consolidation.intent_keys'))
                ->sort()
                ->values()
                ->all(),
        );
        $this->assertSame([11, 12], data_get($intent->meta, 'delivery_consolidation.consent_ids'));
        $this->assertStringContainsString(
            'Webinar email updates are enabled',
            $intent->payload['tokens']['delivery_consolidation_webinar_email_acknowledgement'],
        );
        $this->assertStringContainsString(
            'Example Company',
            $intent->payload['tokens']['delivery_consolidation_marketing_email_acknowledgement'],
        );
    }

    public function test_marketing_sms_remains_separate_from_combined_webinar_sms(): void
    {
        Config::set('messaging.delivery_consolidation.policies.webinar_registration.enabled', true);

        $contact = Contact::factory()->create();

        $resolved = app(MessageDeliveryConsolidator::class)->consolidate([
            $this->intent($contact, 'webinar.registration.confirmation', 'sms', 'transactional', SmsPayload::class),
            $this->intent($contact, 'consent.transactional.sms.acknowledgement', 'sms', 'transactional', SmsPayload::class, consentId: 21),
            $this->intent($contact, 'consent.marketing.sms.acknowledgement', 'sms', 'marketing', SmsPayload::class, consentId: 22),
        ], 'webinar_registration');

        $this->assertCount(2, $resolved);

        $combined = collect($resolved)->first(
            fn (MessageDeliveryIntent $intent): bool => data_get($intent->meta, 'delivery_consolidation.group') === 'initial_sms',
        );
        $marketing = collect($resolved)->first(
            fn (MessageDeliveryIntent $intent): bool => $intent->key === 'consent.marketing.sms.acknowledgement',
        );

        $this->assertInstanceOf(MessageDeliveryIntent::class, $combined);
        $this->assertInstanceOf(MessageDeliveryIntent::class, $marketing);
        $this->assertSame([21], data_get($combined->meta, 'delivery_consolidation.consent_ids'));
        $this->assertSame('marketing', $marketing->purpose());
        $this->assertNull(data_get($marketing->meta, 'delivery_consolidation'));
    }

    private function intent(
        Contact $contact,
        string $key,
        string $channel,
        string $purpose,
        string $payloadClass,
        ?string $occurrenceKey = null,
        ?int $consentId = null,
    ): MessageDeliveryIntent {
        $payload = $channel === 'email'
            ? ['subject' => 'Subject', 'body' => 'Body']
            : ['message' => 'Message'];

        return new MessageDeliveryIntent(
            key: $key,
            recipient: $contact,
            definition: [
                'key' => str_replace('.', '_', $key),
                'dispatch_keys' => [
                    str_starts_with($key, 'webinar.registration')
                        ? 'registration_created'
                        : 'consent_granted',
                ],
                'message_type' => str_starts_with($key, 'webinar.registration')
                    ? 'confirmation'
                    : 'opt_in',
                'channel' => $channel,
                'purpose' => $purpose,
                'scope' => 'webinar',
                'payload_class' => $payloadClass,
                'queue' => 'messages',
                'payload' => $payload,
            ],
            payload: [
                'tokens' => [
                    'first_name' => 'Jeff',
                ],
            ],
            behavior: [
                'timing' => 'immediate',
            ],
            occurrenceKey: $occurrenceKey,
            meta: $consentId !== null
                ? [
                    'delivery_intent' => [
                        'key' => $key,
                        'consent_ids' => [$consentId],
                    ],
                    'consent' => [
                        'message_consent_id' => $consentId,
                    ],
                ]
                : [
                    'delivery_intent' => [
                        'key' => $key,
                        'consent_ids' => [],
                    ],
                ],
        );
    }
}
