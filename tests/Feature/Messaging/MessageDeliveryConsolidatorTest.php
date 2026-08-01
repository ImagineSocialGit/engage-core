<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Data\Delivery\MessageDeliveryIntent;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\ScheduledMessageComponent;
use App\Modules\Messaging\Payloads\EmailPayload;
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

        Config::set(
            'messaging.delivery_consolidation',
            require base_path('config/messaging/delivery_consolidation.php'),
        );
    }

    public function test_disabled_policy_preserves_independent_intents(): void
    {
        $contact = Contact::factory()->create();
        $intents = [
            $this->lifecycleIntent($contact),
            $this->consentIntent($contact, 'transactional'),
        ];

        $resolved = app(MessageDeliveryConsolidator::class)->consolidate(
            $intents,
            'webinar_registration',
        );

        $this->assertSame($intents, $resolved);
    }

    public function test_enabled_policy_preserves_primary_copy_and_builds_relational_components(): void
    {
        $this->enablePolicy();
        $contact = Contact::factory()->create();
        $primary = $this->lifecycleIntent($contact);
        $transactional = $this->consentIntent($contact, 'transactional');
        $marketing = $this->consentIntent($contact, 'marketing');

        $resolved = app(MessageDeliveryConsolidator::class)->consolidate([
            $primary,
            $transactional,
            $marketing,
        ], 'webinar_registration');

        $this->assertCount(1, $resolved);
        $carrier = $resolved[0];

        $this->assertEquals($primary->definition, $carrier->definition);
        $this->assertEquals($primary->payload, $carrier->payload);
        $this->assertEquals($primary->meta, $carrier->meta);
        $this->assertCount(2, $carrier->components);
        $this->assertEqualsCanonicalizing([
            'consent.transactional.email.acknowledgement',
            'consent.marketing.email.acknowledgement',
        ], collect($carrier->components)->pluck('intentKey')->all());
        $this->assertTrue(collect($carrier->components)->every(
            fn ($component): bool =>
                $component->role === ScheduledMessageComponent::ROLE_CONSENT_ACKNOWLEDGEMENT
                && $component->placementKey === 'email_body_append'
                && $component->messageTemplateVersionId > 0
                && $component->messageConsentId !== null,
        ));
        $this->assertArrayNotHasKey('delivery_consolidation', $carrier->meta);
        $this->assertStringNotContainsString(
            'delivery_consolidation_',
            (string) data_get($carrier->definition, 'payload.body'),
        );
    }

    public function test_same_channel_acknowledgements_can_share_one_standalone_carrier(): void
    {
        $this->enablePolicy();
        $contact = Contact::factory()->create();

        $resolved = app(MessageDeliveryConsolidator::class)->consolidate([
            $this->consentIntent($contact, 'transactional'),
            $this->consentIntent($contact, 'marketing'),
        ], 'webinar_registration');

        $this->assertCount(1, $resolved);
        $this->assertSame(
            'consent.transactional.email.acknowledgement',
            $resolved[0]->key,
        );
        $this->assertCount(1, $resolved[0]->components);
        $this->assertSame(
            'consent.marketing.email.acknowledgement',
            $resolved[0]->components[0]->intentKey,
        );
    }

    public function test_chain_carrier_components_are_selected_without_a_materialized_primary_intent(): void
    {
        $this->enablePolicy();
        $contact = Contact::factory()->create();

        $components = app(MessageDeliveryConsolidator::class)->componentsForCarrier(
            memberIntents: [
                $this->consentIntent($contact, 'transactional'),
                $this->consentIntent($contact, 'marketing'),
            ],
            policyKey: 'webinar_registration',
            primaryIntentKey: 'webinar.registration.confirmation',
            channel: 'email',
        );

        $this->assertCount(2, $components);
        $this->assertEquals([100, 110], collect($components)->pluck('sortOrder')->all());
    }

    private function lifecycleIntent(Contact $contact): MessageDeliveryIntent
    {
        return MessageDeliveryIntent::fromDefinition(
            key: 'webinar.registration.confirmation',
            recipient: $contact,
            definition: [
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'message_type' => 'confirmation',
                'dispatch_keys' => ['registration_created'],
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Selected confirmation',
                    'body' => 'Selected confirmation body.',
                ],
            ],
            payload: ['tokens' => ['first_name' => 'Jeff']],
            behavior: [
                'timing' => 'immediate',
            ],
            occurrenceKey: 'registration:1',
            meta: ['fixture' => true],
        );
    }

    private function consentIntent(
        Contact $contact,
        string $purpose,
    ): MessageDeliveryIntent {
        $consent = MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => $purpose,
            'scope' => $purpose === 'marketing'
                ? 'webinar_nurture'
                : 'webinar',
            'consented_at' => now(),
            'source' => 'test',
        ]);
        $template = MessageTemplate::query()->create([
            'key' => "fixture.consent.email.{$purpose}.{$consent->getKey()}",
            'name' => 'Fixture consent acknowledgement',
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);
        $version = app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $template,
            payload: [
                'subject' => 'Acknowledged',
                'body' => ucfirst($purpose).' acknowledgement.',
            ],
        );

        return MessageDeliveryIntent::fromDefinition(
            key: "consent.{$purpose}.email.acknowledgement",
            recipient: $contact,
            definition: [
                'channel' => 'email',
                'purpose' => $purpose,
                'scope' => $purpose === 'marketing'
                    ? 'webinar_nurture'
                    : 'webinar',
                'message_type' => 'opt_in',
                'dispatch_keys' => ['consent_granted'],
                'payload_class' => EmailPayload::class,
                'queue' => 'opt_in_messages',
                'message_template_version_id' => $version->getKey(),
                'payload' => $version->payload(),
            ],
            meta: [
                'consent' => [
                    'message_consent_id' => $consent->getKey(),
                ],
            ],
        );
    }

    private function enablePolicy(): void
    {
        Config::set(
            'messaging.delivery_consolidation.policies.webinar_registration.enabled',
            true,
        );
    }
}