<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
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

class DispatchWebinarRegistrationMessagesTemplateAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureWebinarRegistrationChannelAvailability();
    }

    public function test_direct_confirmation_uses_assigned_db_preset_while_reminders_are_chain_enrolled(): void
    {
        Queue::fake();

        $this->configureRegistrationMessages();
        app(SyncMessageTemplatePresetsAction::class)->handle(force: true);

        [$preset, $version] = $this->createCanonicalPreset(
            key: 'email.transactional.webinar.confirmation.db',
            messageType: 'confirmation',
            payload: [
                'subject' => 'DB confirmation for {first_name}',
                'body' => 'DB selected confirmation copy.',
            ],
        );

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create([
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'definition_key' => 'confirmation',
            ]);

        $profile = $this->registrationProfile([
            [
                'key' => 'email_confirmation',
                'context_key' => 'confirmation',
                'message_type' => 'confirmation',
                'message_template_key' => 'confirmation',
                'timing' => 'immediate',
                'schedule' => null,
            ],
            [
                'key' => 'email_reminder',
                'context_key' => 'reminders',
                'message_type' => 'reminder',
                'message_template_key' => 'reminder',
                'timing' => 'scheduled',
                'schedule' => ['type' => 'anchored', 'minutes' => -30],
            ],
        ]);
        $this->syncProfileChains($profile);

        $registration = $this->registrationForContact(
            $this->contactWithTransactionalEmailConsent(),
        );

        app(DispatchWebinarRegistrationMessagesAction::class)->handle($registration);

        $confirmation = ScheduledMessage::query()
            ->where('message_type', 'confirmation')
            ->sole();

        $this->assertSame((int) $version->getKey(), (int) $confirmation->message_template_version_id);
        $this->assertSame(
            $preset->getKey(),
            data_get($confirmation->meta, 'message_template.preset_id'),
        );
        $this->assertDatabaseMissing('scheduled_messages', [
            'message_type' => 'reminder',
        ]);
        $this->assertSame(1, ScheduledMessage::query()->count());
        $this->assertSame(1, MessageChainEnrollment::query()->count());
    }

    public function test_exact_assigned_reminder_and_config_sibling_are_both_pinned_in_the_chain(): void
    {
        Queue::fake();

        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmation' => [
                'key' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Config confirmation',
                    'body' => 'Config confirmation body.',
                ],
            ],
            'reminders' => [
                [
                    'key' => 'reminder_1_day',
                    'dispatch_key' => 'registration_created',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'reminders',
                    'payload' => [
                        'subject' => 'Config one day',
                        'body' => 'Config one day body.',
                    ],
                ],
                [
                    'key' => 'reminder_30_minute',
                    'dispatch_key' => 'registration_created',
                    'payload_class' => EmailPayload::class,
                    'queue' => 'reminders',
                    'payload' => [
                        'subject' => 'Config thirty minute',
                        'body' => 'Config thirty minute body.',
                    ],
                ],
            ],
        ]);
        Config::set('messaging.sms.definitions.transactional.webinar', []);
        app(SyncMessageTemplatePresetsAction::class)->handle(force: true);

        [$preset] = $this->createCanonicalPreset(
            key: 'email.transactional.webinar.reminder_1_day.custom',
            messageType: 'reminder',
            payload: [
                'subject' => 'Assigned one day',
                'body' => 'Assigned one day body.',
            ],
            definitionKey: 'reminder_1_day',
        );

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create([
                'surface' => 'webinar_registrations',
                'message_type' => 'reminder',
                'definition_key' => 'reminder_1_day',
                'source_config_path' => 'messaging.email.definitions.transactional.webinar.reminders.0',
            ]);

        $profile = $this->registrationProfile([
            [
                'key' => 'email_confirmation',
                'context_key' => 'confirmation',
                'message_type' => 'confirmation',
                'message_template_key' => 'confirmation',
                'timing' => 'immediate',
                'schedule' => null,
            ],
            [
                'key' => 'email_reminder_1_day',
                'context_key' => 'reminders',
                'message_type' => 'reminder',
                'message_template_key' => 'reminder_1_day',
                'timing' => 'scheduled',
                'schedule' => ['type' => 'anchored', 'minutes' => -1440],
            ],
            [
                'key' => 'email_reminder_30_minute',
                'context_key' => 'reminders',
                'message_type' => 'reminder',
                'message_template_key' => 'reminder_30_minute',
                'timing' => 'scheduled',
                'schedule' => ['type' => 'anchored', 'minutes' => -30],
            ],
        ]);
        $this->syncProfileChains($profile);

        $binding = $profile->messageChainBindings()
            ->with('messageChain.currentVersion.steps.variants.messageTemplateVersion')
            ->where('message_area_key', 'reminders')
            ->sole();
        $subjects = $binding->messageChain->currentVersion->steps
            ->flatMap(fn ($step) => $step->variants)
            ->filter(fn ($variant): bool => $variant->message_type === 'reminder')
            ->map(fn ($variant): ?string => $variant->messageTemplateVersion->subject)
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing([
            'Assigned one day',
            'Config thirty minute',
        ], $subjects);

        $registration = $this->registrationForContact(
            $this->contactWithTransactionalEmailConsent(),
        );
        $registration->webinar()->update(['starts_at' => now()->addDays(2)]);

        app(DispatchWebinarRegistrationMessagesAction::class)->handle($registration);

        $this->assertSame(1, ScheduledMessage::query()->count());
        $this->assertDatabaseMissing('scheduled_messages', [
            'message_type' => 'reminder',
        ]);
        $this->assertSame(1, MessageChainEnrollment::query()->count());
    }

    private function configureRegistrationMessages(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmation' => [
                'key' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Config confirmation for {first_name}',
                    'body' => 'Config confirmation copy.',
                ],
            ],
            'reminder' => [
                'key' => 'reminder',
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'reminders',
                'payload' => [
                    'subject' => 'Config reminder',
                    'body' => 'Starts soon.',
                ],
            ],
        ]);
        Config::set('messaging.sms.definitions.transactional.webinar', []);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function registrationProfile(array $items): WebinarScheduleProfile
    {
        $profile = WebinarScheduleProfile::factory()->create([
            'key' => 'registration_assignment_test_profile',
            'name' => 'Registration assignment test profile',
            'status' => WebinarScheduleProfile::STATUS_ACTIVE,
            'is_default' => true,
            'is_active' => true,
            'message_template_set_key' => 'default',
        ]);

        foreach ($items as $item) {
            WebinarScheduleProfileItem::factory()->create([
                'webinar_schedule_profile_id' => $profile->getKey(),
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'dispatch_key' => 'registration_created',
                'conditions' => [],
                'is_enabled' => true,
                'is_active' => true,
                ...$item,
            ]);
        }

        return $profile;
    }

    private function syncProfileChains(WebinarScheduleProfile $profile): void
    {
        app(SyncWebinarScheduleProfileChainsAction::class)->handle(
            profile: $profile,
            force: true,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: MessageTemplatePreset, 1: MessageTemplateVersion}
     */
    private function createCanonicalPreset(
        string $key,
        string $messageType,
        array $payload,
        ?string $definitionKey = null,
    ): array {
        $preset = MessageTemplatePreset::factory()->create([
            'key' => $key,
            'name' => str_replace('.', ' ', $key),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => $messageType,
            'payload_class' => EmailPayload::class,
            'queue' => $messageType === 'confirmation'
                ? 'confirmation_messages'
                : 'reminders',
            'dispatch_keys' => ['registration_created'],
            'payload' => $payload,
            'meta' => $definitionKey !== null
                ? ['seed' => ['definition_key' => $definitionKey]]
                : [],
        ]);
        $template = MessageTemplate::query()->create([
            'key' => $key,
            'name' => $preset->name,
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => true,
            'customized_at' => now(),
        ]);
        $version = app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $template,
            payload: $payload,
        );

        return [$preset, $version];
    }

    private function contactWithTransactionalEmailConsent(): Contact
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'name' => 'Jeff Yarnall',
            'email' => 'jeff@example.com',
            'phone' => '+15555550123',
        ]);

        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Transactional->value,
            'scope' => 'webinar',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);

        return $contact;
    }

    private function registrationForContact(Contact $contact): WebinarRegistration
    {
        $series = WebinarSeries::factory()->create();
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
                    'transactional' => [MessageChannel::Email->value],
                ],
            ],
        ]);
    }

    private function configureWebinarRegistrationChannelAvailability(): void
    {
        Config::set('messaging.channel_availability.email', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => [
                'webinar_registrations' => true,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
            ],
        ]);

        Config::set('messaging.channel_availability.sms', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => true,
            'surfaces' => [
                'webinar_registrations' => false,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
            ],
        ]);
    }
}