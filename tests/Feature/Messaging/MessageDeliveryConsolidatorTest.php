<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Data\Delivery\MessageDeliveryIntent;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\MessageDeliveryConsolidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
            $this->intent(
                contact: $contact,
                key: 'webinar.registration.confirmation',
                channel: 'email',
                purpose: 'transactional',
                payloadClass: EmailPayload::class,
            ),
            $this->intent(
                contact: $contact,
                key: 'consent.transactional.email.acknowledgement',
                channel: 'email',
                purpose: 'transactional',
                payloadClass: EmailPayload::class,
            ),
        ];

        $resolved = app(MessageDeliveryConsolidator::class)->consolidate(
            $intents,
            'webinar_registration',
        );

        $this->assertSame($intents, $resolved);
    }

    public function test_email_policy_preserves_primary_definition_and_bundles_all_email_acknowledgements(): void
    {
        $this->enablePolicy();

        $contact = Contact::factory()->create();
        $this->grantConsent($contact, 'email', 'transactional');

        $resolved = app(MessageDeliveryConsolidator::class)->consolidate([
            $this->intent(
                contact: $contact,
                key: 'webinar.registration.confirmation',
                channel: 'email',
                purpose: 'transactional',
                payloadClass: EmailPayload::class,
                occurrenceKey: 'webinar_registration:55',
                definitionPayload: [
                    'subject' => 'Selected confirmation subject',
                    'body' => 'Selected confirmation body.',
                    'cta' => [
                        'label' => 'Selected CTA',
                        'url' => 'https://example.test/join',
                    ],
                    'secondary_link' => [
                        'label' => 'Selected secondary link',
                        'url' => 'https://example.test/cancel',
                    ],
                ],
                definitionMeta: [
                    'message_template_preset' => [
                        'id' => 91,
                    ],
                    'message_template_assignment' => [
                        'id' => 92,
                    ],
                ],
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
        $this->assertSame('confirmation', $intent->definition['message_type']);
        $this->assertSame(
            'Selected confirmation subject',
            $intent->definition['payload']['subject'],
        );
        $this->assertSame('webinar_registration:55', $intent->occurrenceKey);

        $body = (string) $intent->definition['payload']['body'];

        $this->assertStringStartsWith(
            'Selected confirmation body.',
            $body,
        );
        $this->assertStringContainsString(
            '{delivery_consolidation_webinar_email_acknowledgement}',
            $body,
        );
        $this->assertStringContainsString(
            '{delivery_consolidation_marketing_email_acknowledgement}',
            $body,
        );

        $this->assertSame([
            'label' => 'Selected CTA',
            'url' => 'https://example.test/join',
        ], $intent->definition['payload']['cta']);

        $this->assertSame([
            'label' => 'Selected secondary link',
            'url' => 'https://example.test/cancel',
        ], $intent->definition['payload']['secondary_link']);

        $this->assertSame(
            91,
            data_get($intent->definition, 'meta.message_template_preset.id'),
        );
        $this->assertSame(
            92,
            data_get($intent->definition, 'meta.message_template_assignment.id'),
        );

        $this->assertEqualsCanonicalizing([
            'webinar.registration.confirmation',
            'consent.transactional.email.acknowledgement',
            'consent.marketing.email.acknowledgement',
        ], data_get($intent->meta, 'delivery_consolidation.intent_keys'));

        $this->assertEqualsCanonicalizing(
            [11, 12],
            data_get($intent->meta, 'delivery_consolidation.consent_ids'),
        );

        $this->assertSame(
            'primary_intent',
            data_get($intent->meta, 'delivery_consolidation.template_source'),
        );
        $this->assertSame(
            "\n\n",
            data_get($intent->meta, 'delivery_consolidation.separator'),
        );

        $this->assertStringContainsString(
            'Example Company',
            (string) data_get(
                $intent->payload,
                'tokens.delivery_consolidation_marketing_email_acknowledgement',
            ),
        );
        $this->assertNotEmpty($intent->payload['unsubscribe_url'] ?? null);
    }

    public function test_sms_policy_bundles_transactional_and_marketing_acknowledgements_into_one_sms(): void
    {
        $this->enablePolicy();

        $contact = Contact::factory()->create([
            'phone' => '+15555550123',
        ]);
        $this->grantConsent($contact, 'sms', 'transactional');

        $resolved = app(MessageDeliveryConsolidator::class)->consolidate([
            $this->intent(
                contact: $contact,
                key: 'webinar.registration.confirmation',
                channel: 'sms',
                purpose: 'transactional',
                payloadClass: SmsPayload::class,
                definitionPayload: [
                    'message' => 'Selected SMS confirmation.',
                ],
            ),
            $this->intent(
                contact: $contact,
                key: 'consent.transactional.sms.acknowledgement',
                channel: 'sms',
                purpose: 'transactional',
                payloadClass: SmsPayload::class,
                consentId: 21,
            ),
            $this->intent(
                contact: $contact,
                key: 'consent.marketing.sms.acknowledgement',
                channel: 'sms',
                purpose: 'marketing',
                payloadClass: SmsPayload::class,
                consentId: 22,
            ),
        ], 'webinar_registration');

        $this->assertCount(1, $resolved);

        $intent = $resolved[0];
        $message = (string) $intent->definition['payload']['message'];

        $this->assertStringStartsWith(
            'Selected SMS confirmation.',
            $message,
        );
        $this->assertStringContainsString(
            '{delivery_consolidation_webinar_sms_acknowledgement}',
            $message,
        );
        $this->assertStringContainsString(
            '{delivery_consolidation_marketing_sms_acknowledgement}',
            $message,
        );

        $this->assertEqualsCanonicalizing([
            'webinar.registration.confirmation',
            'consent.transactional.sms.acknowledgement',
            'consent.marketing.sms.acknowledgement',
        ], data_get($intent->meta, 'delivery_consolidation.intent_keys'));

        $this->assertEqualsCanonicalizing(
            [21, 22],
            data_get($intent->meta, 'delivery_consolidation.consent_ids'),
        );
    }

    public function test_past_confirmation_uses_the_earliest_eligible_reminder_as_the_carrier(): void
    {
        $this->enablePolicy();

        $contact = Contact::factory()->create();
        $this->grantConsent($contact, 'email', 'transactional');

        $past = now()->subMinute();
        $later = now()->addMinutes(30);
        $earlier = now()->addMinutes(10);

        $resolved = app(MessageDeliveryConsolidator::class)->consolidate([
            $this->intent(
                contact: $contact,
                key: 'webinar.registration.confirmation',
                channel: 'email',
                purpose: 'transactional',
                payloadClass: EmailPayload::class,
                messageType: 'confirmation',
                sendAt: $past,
                behavior: $this->scheduledBehavior(),
                definitionPayload: [
                    'subject' => 'Past confirmation',
                    'body' => 'Past confirmation body.',
                ],
            ),
            $this->intent(
                contact: $contact,
                key: 'webinar.registration.reminder_30_minute',
                channel: 'email',
                purpose: 'transactional',
                payloadClass: EmailPayload::class,
                messageType: 'reminder',
                sendAt: $later,
                behavior: $this->scheduledBehavior(),
                definitionPayload: [
                    'subject' => 'Later reminder',
                    'body' => 'Later reminder body.',
                ],
            ),
            $this->intent(
                contact: $contact,
                key: 'webinar.registration.reminder_10_minute',
                channel: 'email',
                purpose: 'transactional',
                payloadClass: EmailPayload::class,
                messageType: 'reminder',
                sendAt: $earlier,
                behavior: $this->scheduledBehavior(),
                definitionPayload: [
                    'subject' => 'Earlier reminder',
                    'body' => 'Earlier reminder body.',
                ],
            ),
            $this->intent(
                contact: $contact,
                key: 'consent.transactional.email.acknowledgement',
                channel: 'email',
                purpose: 'transactional',
                payloadClass: EmailPayload::class,
                consentId: 31,
            ),
            $this->intent(
                contact: $contact,
                key: 'consent.marketing.email.acknowledgement',
                channel: 'email',
                purpose: 'marketing',
                payloadClass: EmailPayload::class,
                consentId: 32,
            ),
        ], 'webinar_registration');

        $combined = collect($resolved)->first(
            fn (MessageDeliveryIntent $intent): bool =>
                data_get($intent->meta, 'delivery_consolidation.group')
                    === 'initial_email',
        );

        $this->assertInstanceOf(MessageDeliveryIntent::class, $combined);
        $this->assertSame('reminder', $combined->definition['message_type']);
        $this->assertSame(
            'Earlier reminder',
            $combined->definition['payload']['subject'],
        );
        $this->assertTrue(Carbon::parse($combined->sendAt)->equalTo($earlier));
        $this->assertSame(
            'webinar.registration.reminder_10_minute',
            data_get(
                $combined->meta,
                'delivery_consolidation.primary_intent_key',
            ),
        );
    }

    public function test_join_suppressible_reminder_is_not_used_as_an_acknowledgement_carrier(): void
    {
        $this->enablePolicy();

        $contact = Contact::factory()->create();
        $this->grantConsent($contact, 'email', 'transactional');

        $resolved = app(MessageDeliveryConsolidator::class)->consolidate([
            $this->intent(
                contact: $contact,
                key: 'webinar.registration.confirmation',
                channel: 'email',
                purpose: 'transactional',
                payloadClass: EmailPayload::class,
                messageType: 'confirmation',
                sendAt: now()->subMinute(),
                behavior: $this->scheduledBehavior(),
            ),
            $this->intent(
                contact: $contact,
                key: 'webinar.registration.reminder_live',
                channel: 'email',
                purpose: 'transactional',
                payloadClass: EmailPayload::class,
                messageType: 'reminder',
                sendAt: now()->addMinutes(5),
                behavior: [
                    ...$this->scheduledBehavior(),
                    'skip_when_join_clicked' => true,
                ],
            ),
            $this->intent(
                contact: $contact,
                key: 'consent.transactional.email.acknowledgement',
                channel: 'email',
                purpose: 'transactional',
                payloadClass: EmailPayload::class,
                consentId: 35,
                definitionPayload: [
                    'subject' => 'Email updates enabled',
                    'body' => 'Transactional email acknowledgement.',
                ],
            ),
            $this->intent(
                contact: $contact,
                key: 'consent.marketing.email.acknowledgement',
                channel: 'email',
                purpose: 'marketing',
                payloadClass: EmailPayload::class,
                consentId: 36,
                definitionPayload: [
                    'subject' => 'Marketing enabled',
                    'body' => 'Marketing email acknowledgement.',
                ],
            ),
        ], 'webinar_registration');

        $combined = collect($resolved)->first(
            fn (MessageDeliveryIntent $intent): bool =>
                data_get($intent->meta, 'delivery_consolidation.group')
                    === 'initial_email',
        );

        $this->assertInstanceOf(MessageDeliveryIntent::class, $combined);
        $this->assertSame('opt_in', $combined->definition['message_type']);
        $this->assertSame(
            'standalone_intent',
            data_get($combined->meta, 'delivery_consolidation.template_source'),
        );
        $this->assertStringStartsWith(
            'Transactional email acknowledgement.',
            (string) $combined->definition['payload']['body'],
        );
        $this->assertStringContainsString(
            '{delivery_consolidation_marketing_email_acknowledgement}',
            (string) $combined->definition['payload']['body'],
        );
    }

    public function test_same_channel_acknowledgements_use_one_standalone_delivery_when_no_lifecycle_message_is_eligible(): void
    {
        $this->enablePolicy();

        $contact = Contact::factory()->create();

        $resolved = app(MessageDeliveryConsolidator::class)->consolidate([
            $this->intent(
                contact: $contact,
                key: 'consent.transactional.sms.acknowledgement',
                channel: 'sms',
                purpose: 'transactional',
                payloadClass: SmsPayload::class,
                occurrenceKey: 'consent_granted:41',
                consentId: 41,
                definitionPayload: [
                    'message' => 'Transactional SMS acknowledgement.',
                ],
            ),
            $this->intent(
                contact: $contact,
                key: 'consent.marketing.sms.acknowledgement',
                channel: 'sms',
                purpose: 'marketing',
                payloadClass: SmsPayload::class,
                occurrenceKey: 'consent_granted:42',
                consentId: 42,
                definitionPayload: [
                    'message' => 'Marketing SMS acknowledgement.',
                ],
            ),
        ], 'webinar_registration');

        $this->assertCount(1, $resolved);

        $intent = $resolved[0];

        $this->assertSame('transactional', $intent->purpose());
        $this->assertSame(
            'standalone_intent',
            data_get($intent->meta, 'delivery_consolidation.template_source'),
        );
        $this->assertEqualsCanonicalizing(
            [41, 42],
            data_get($intent->meta, 'delivery_consolidation.consent_ids'),
        );
        $this->assertStringStartsWith(
            'Transactional SMS acknowledgement.',
            (string) $intent->definition['payload']['message'],
        );
        $this->assertStringContainsString(
            '{delivery_consolidation_marketing_sms_acknowledgement}',
            (string) $intent->definition['payload']['message'],
        );
    }

    public function test_missing_member_fragment_falls_back_to_independent_deliveries(): void
    {
        $this->enablePolicy();

        Config::set(
            'messaging.delivery_consolidation.policies.webinar_registration.groups.initial_email.fragments.delivery_consolidation_webinar_email_acknowledgement',
            null,
        );

        $contact = Contact::factory()->create();
        $this->grantConsent($contact, 'email', 'transactional');

        $intents = [
            $this->intent(
                contact: $contact,
                key: 'webinar.registration.confirmation',
                channel: 'email',
                purpose: 'transactional',
                payloadClass: EmailPayload::class,
            ),
            $this->intent(
                contact: $contact,
                key: 'consent.transactional.email.acknowledgement',
                channel: 'email',
                purpose: 'transactional',
                payloadClass: EmailPayload::class,
                consentId: 51,
            ),
        ];

        $resolved = app(MessageDeliveryConsolidator::class)->consolidate(
            $intents,
            'webinar_registration',
        );

        $this->assertSame($intents, $resolved);
    }

    private function enablePolicy(): void
    {
        Config::set(
            'messaging.delivery_consolidation.policies.webinar_registration.enabled',
            true,
        );
    }

    private function grantConsent(
        Contact $contact,
        string $channel,
        string $purpose,
    ): void {
        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => $channel,
            'purpose' => $purpose,
            'scope' => 'webinar',
            'consented_at' => now(),
            'source' => 'test',
        ]);
    }

    /**
     * @param array<string, mixed>|null $definitionPayload
     * @param array<string, mixed> $definitionMeta
     * @param array<string, mixed>|null $behavior
     */
    private function intent(
        Contact $contact,
        string $key,
        string $channel,
        string $purpose,
        string $payloadClass,
        ?string $occurrenceKey = null,
        ?int $consentId = null,
        ?array $definitionPayload = null,
        array $definitionMeta = [],
        ?string $messageType = null,
        Carbon|string|null $sendAt = null,
        ?array $behavior = null,
    ): MessageDeliveryIntent {
        $definitionPayload ??= $channel === 'email'
            ? ['subject' => 'Subject', 'body' => 'Body']
            : ['message' => 'Message'];

        $isLifecycle = str_starts_with($key, 'webinar.registration');

        return new MessageDeliveryIntent(
            key: $key,
            recipient: $contact,
            definition: [
                'key' => str_replace('.', '_', $key),
                'dispatch_keys' => [
                    $isLifecycle
                        ? 'registration_created'
                        : 'consent_granted',
                ],
                'message_type' => $messageType
                    ?? ($isLifecycle ? 'confirmation' : 'opt_in'),
                'channel' => $channel,
                'purpose' => $purpose,
                'scope' => 'webinar',
                'payload_class' => $payloadClass,
                'queue' => 'messages',
                'payload' => $definitionPayload,
                'meta' => $definitionMeta,
            ],
            payload: [
                'tokens' => [
                    'first_name' => 'Jeff',
                ],
            ],
            triggeredAt: now(),
            sendAt: $sendAt,
            behavior: $behavior ?? [
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

    /**
     * @return array<string, mixed>
     */
    private function scheduledBehavior(): array
    {
        return [
            'timing' => 'scheduled',
            'schedule' => [
                'type' => 'delay',
                'minutes' => 0,
            ],
        ];
    }
}