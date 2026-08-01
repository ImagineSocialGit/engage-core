<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageComponent;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\ScheduledMessagePayloadResolver;
use App\Modules\Webinars\Actions\DispatchWebinarRegistrationMessagesAction;
use App\Modules\Webinars\Actions\SyncWebinarScheduleProfileChainsAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebinarRegistrationMessageConsolidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Config::set(
            'messaging.delivery_consolidation',
            require base_path('config/messaging/delivery_consolidation.php'),
        );
        $this->configureChannels();
        $this->configureDefinitions();
    }

    public function test_enabled_policy_uses_chain_confirmations_as_relational_component_carriers(): void
    {
        $this->enablePolicy();
        $profile = $this->createProfile('immediate');
        $registration = $this->registration($profile, ['email', 'sms']);
        $grants = [
            $this->grant($registration->contact, 'email', 'transactional'),
            $this->grant($registration->contact, 'email', 'marketing'),
            $this->grant($registration->contact, 'sms', 'transactional'),
            $this->grant($registration->contact, 'sms', 'marketing'),
        ];

        $messages = app(DispatchWebinarRegistrationMessagesAction::class)->handle(
            registration: $registration,
            consentGrants: $grants,
        );

        $this->assertCount(2, $messages);
        $this->assertSame(1, MessageChainEnrollment::query()->count());
        $this->assertSame(2, ScheduledMessage::query()->count());
        $this->assertSame(4, ScheduledMessageComponent::query()->count());

        foreach (ScheduledMessage::query()->with('components')->get() as $message) {
            $this->assertSame('confirmation', $message->message_type);
            $this->assertNotNull($message->message_chain_enrollment_id);
            $this->assertNotNull($message->message_chain_step_variant_id);
            $this->assertCount(2, $message->components);
            $this->assertArrayNotHasKey('delivery_consolidation', $message->meta);
            $this->assertArrayNotHasKey('tokens', $message->payload);
            $this->assertArrayHasKey('to', $message->payload);
        }

        $email = ScheduledMessage::query()->where('channel', 'email')->sole();
        $sms = ScheduledMessage::query()->where('channel', 'sms')->sole();
        $emailPayload = app(ScheduledMessagePayloadResolver::class)->resolve($email);
        $smsPayload = app(ScheduledMessagePayloadResolver::class)->resolve($sms);

        $this->assertStringContainsString('Confirmation body.', $emailPayload->text());
        $this->assertStringContainsString('email updates', $emailPayload->text());
        $this->assertStringContainsString('marketing emails', $emailPayload->text());
        $this->assertStringContainsString('Confirmation SMS.', $smsPayload->message());
        $this->assertStringContainsString('text message updates', $smsPayload->message());
        $this->assertStringContainsString('marketing text messages', $smsPayload->message());
    }

    public function test_future_confirmation_is_materialized_early_only_when_components_need_a_carrier(): void
    {
        $this->enablePolicy();
        $profile = $this->createProfile('scheduled');
        $registration = $this->registration($profile, ['email']);
        $grant = $this->grant($registration->contact, 'email', 'transactional');

        app(DispatchWebinarRegistrationMessagesAction::class)->handle(
            registration: $registration,
            consentGrants: [$grant],
        );

        $message = ScheduledMessage::query()->sole();

        $this->assertSame('confirmation', $message->message_type);
        $this->assertTrue($message->send_at->isFuture());
        $this->assertSame(1, ScheduledMessageComponent::query()->count());
        $this->assertDatabaseMissing('scheduled_messages', [
            'message_type' => 'reminder',
        ]);
    }

    public function test_later_consent_is_attached_to_the_existing_pending_chain_delivery(): void
    {
        $this->enablePolicy();
        $profile = $this->createProfile('scheduled');
        $registration = $this->registration($profile, ['email']);

        app(DispatchWebinarRegistrationMessagesAction::class)->handle(
            registration: $registration,
            consentGrants: [
                $this->grant($registration->contact, 'email', 'transactional'),
            ],
        );
        app(DispatchWebinarRegistrationMessagesAction::class)->handle(
            registration: $registration,
            consentGrants: [
                $this->grant($registration->contact, 'email', 'marketing'),
            ],
        );

        $this->assertSame(1, ScheduledMessage::query()->count());
        $this->assertSame(2, ScheduledMessageComponent::query()->count());
        $this->assertEqualsCanonicalizing([
            'consent.transactional.email.acknowledgement',
            'consent.marketing.email.acknowledgement',
        ], ScheduledMessageComponent::query()->pluck('intent_key')->all());
    }

    public function test_disabled_policy_keeps_acknowledgements_standalone(): void
    {
        $profile = $this->createProfile('immediate');
        $registration = $this->registration($profile, ['email']);
        $grants = [
            $this->grant($registration->contact, 'email', 'transactional'),
            $this->grant($registration->contact, 'email', 'marketing'),
        ];

        app(DispatchWebinarRegistrationMessagesAction::class)->handle(
            registration: $registration,
            consentGrants: $grants,
        );

        $this->assertSame(3, ScheduledMessage::query()->count());
        $this->assertSame(0, ScheduledMessageComponent::query()->count());
        $this->assertSame(1, ScheduledMessage::query()
            ->whereNotNull('message_chain_enrollment_id')
            ->count());
        $this->assertSame(2, ScheduledMessage::query()
            ->whereNull('message_chain_enrollment_id')
            ->where('message_type', 'opt_in')
            ->count());
    }

    private function createProfile(string $confirmationTiming): WebinarScheduleProfile
    {
        $profile = WebinarScheduleProfile::factory()->create([
            'key' => 'component_registration_profile_'.$confirmationTiming,
            'name' => 'Component registration profile',
            'status' => WebinarScheduleProfile::STATUS_ACTIVE,
            'is_default' => true,
            'is_active' => true,
            'message_template_set_key' => 'default',
        ]);

        foreach (['email', 'sms'] as $channel) {
            WebinarScheduleProfileItem::factory()->create([
                'webinar_schedule_profile_id' => $profile->getKey(),
                'key' => $channel.'_confirmation',
                'context_key' => 'confirmation',
                'channel' => $channel,
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'message_template_key' => 'confirmation',
                'timing' => $confirmationTiming,
                'schedule' => $confirmationTiming === 'scheduled'
                    ? ['type' => 'delay', 'minutes' => 15]
                    : null,
                'conditions' => [],
                'is_enabled' => true,
                'is_active' => true,
            ]);
            WebinarScheduleProfileItem::factory()->create([
                'webinar_schedule_profile_id' => $profile->getKey(),
                'key' => $channel.'_reminder',
                'context_key' => 'reminders',
                'channel' => $channel,
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'reminder',
                'dispatch_key' => 'registration_created',
                'message_template_key' => 'reminder',
                'timing' => 'scheduled',
                'schedule' => ['type' => 'anchored', 'minutes' => -30],
                'conditions' => [],
                'is_enabled' => true,
                'is_active' => true,
            ]);
        }

        app(SyncMessageTemplatePresetsAction::class)->handle(force: true);
        app(SyncWebinarScheduleProfileChainsAction::class)->handle(
            profile: $profile,
            force: true,
        );

        return $profile;
    }

    /** @param array<int, string> $acceptedChannels */
    private function registration(
        WebinarScheduleProfile $profile,
        array $acceptedChannels,
    ): WebinarRegistration {
        $contact = Contact::factory()->create([
            'first_name' => 'Jeff',
            'email' => 'jeff@example.test',
            'phone' => '+15555550123',
        ]);
        $series = WebinarSeries::factory()->create([
            'webinar_schedule_profile_id' => $profile->getKey(),
        ]);
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addDay(),
        ]);

        return WebinarRegistration::query()->create([
            'contact_id' => $contact->getKey(),
            'webinar_id' => $webinar->getKey(),
            'webinar_slug' => $webinar->slug,
            'status' => 'pending',
            'source' => 'test',
            'registered_at' => now(),
            'meta' => [
                'accepted_channels' => [
                    'transactional' => $acceptedChannels,
                ],
            ],
        ])->load('contact', 'webinar.webinarSeries');
    }

    private function grant(
        Contact $contact,
        string $channel,
        string $purpose,
    ): MessageConsentGrantResult {
        $scope = $purpose === 'marketing'
            ? 'webinar_nurture'
            : 'webinar';
        $consent = MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => $channel,
            'purpose' => $purpose,
            'scope' => 'webinar',
            'consented_at' => now(),
            'source' => 'test',
        ]);

        return new MessageConsentGrantResult(
            consent: $consent,
            channel: $channel,
            purpose: $purpose,
            requestedScope: $scope,
            domain: 'webinar',
            wasActive: false,
            isActive: true,
            created: true,
            becameActive: true,
        );
    }

    private function configureDefinitions(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'default' => [
                'confirmation' => [
                    'key' => 'confirmation',
                    'dispatch_key' => 'registration_created',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'confirmation_messages',
                    'payload' => [
                        'subject' => 'Confirmation',
                        'body' => 'Confirmation body.',
                    ],
                ],
                'reminder' => [
                    'key' => 'reminder',
                    'dispatch_key' => 'registration_created',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'reminders',
                    'payload' => [
                        'subject' => 'Reminder',
                        'body' => 'Reminder body.',
                    ],
                ],

            ],
        ]);
        Config::set('messaging.sms.definitions.transactional.webinar', [
            'default' => [
                'confirmation' => [
                    'key' => 'confirmation',
                    'dispatch_key' => 'registration_created',
                    'payload_class' => SmsPayload::class,
                    'queue' => 'confirmation_messages',
                    'payload' => ['message' => 'Confirmation SMS.'],
                ],
                'reminder' => [
                    'key' => 'reminder',
                    'dispatch_key' => 'registration_created',
                    'payload_class' => SmsPayload::class,
                    'queue' => 'reminders',
                    'payload' => ['message' => 'Reminder SMS.'],
                ],

            ],
        ]);
    }

    private function configureChannels(): void
    {
        foreach (['email', 'sms'] as $channel) {
            Config::set("messaging.channel_availability.{$channel}", [
                'runtime_supported' => true,
                'provider_enabled' => true,
                'requires_explicit_opt_in' => $channel === 'sms',
                'surfaces' => ['webinar_registrations' => true],
                'purpose_scopes' => ['*' => true],
            ]);
        }
    }

    private function enablePolicy(): void
    {
        Config::set(
            'messaging.delivery_consolidation.policies.webinar_registration.enabled',
            true,
        );
    }
}