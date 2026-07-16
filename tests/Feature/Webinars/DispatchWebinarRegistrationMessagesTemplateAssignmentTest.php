<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Webinars\Actions\DispatchWebinarRegistrationMessagesAction;
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

    public function test_registration_messages_use_db_confirmation_and_config_fallback_reminders(): void
    {
        Queue::fake();

        $this->configureWebinarRegistrationChannelAvailability();
        $this->configureRegistrationMessages();
        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'email.transactional.webinar.confirmation.db',
            'name' => 'DB Webinar Confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'DB confirmation for {first_name}',
                'body' => 'DB selected confirmation copy.',
            ],
            'source_config_path' => 'messaging.email.definitions.transactional.webinar.confirmation',
        ]);

        $this->configureRegistrationScheduleProfile('confirmation');

        MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'module_key' => 'webinars',
                'module_label' => 'Webinars',
                'surface' => 'webinar_registrations',
                'group_label' => 'Webinar Confirmations',
                'item_label' => 'Confirmation Email',
                'usage_type' => 'webinar_confirmation',
                'source_config_path' => $preset->source_config_path,
            ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create([
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
            ]);

        $registration = $this->registrationForContact($this->contactWithTransactionalEmailConsent());

        app(DispatchWebinarRegistrationMessagesAction::class)->handle($registration);

        $this->assertDatabaseHas('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $registration->contact_id,
            'context_type' => $registration->getMorphClass(),
            'context_id' => $registration->getKey(),
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Transactional->value,
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);

        $confirmation = ScheduledMessage::query()
            ->where('message_type', 'confirmation')
            ->firstOrFail();

        $this->assertSame('DB confirmation for {first_name}', $confirmation->payload['subject']);
        $this->assertSame($preset->getKey(), data_get($confirmation->meta, 'message_template_preset.id'));

        $this->assertDatabaseHas('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $registration->contact_id,
            'context_type' => $registration->getMorphClass(),
            'context_id' => $registration->getKey(),
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Transactional->value,
            'scope' => 'webinar',
            'message_type' => 'reminder',
            'payload_class' => EmailPayload::class,
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);

        $reminder = ScheduledMessage::query()
            ->where('message_type', 'reminder')
            ->firstOrFail();

        $this->assertSame('Config reminder', $reminder->payload['subject']);
        $this->assertSame('messaging.email.definitions.transactional.webinar.reminder', $reminder->definition_config_path);

        $this->assertSame(2, ScheduledMessage::query()->count());
        Queue::assertPushed(SendScheduledMessageJob::class, 2);
    }


    public function test_exact_assigned_reminder_does_not_remove_sibling_reminder_from_registration_dispatch(): void
    {
        Queue::fake();

        $this->configureWebinarRegistrationChannelAvailability();
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

        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'email.transactional.webinar.reminder_1_day.custom',
            'name' => 'Custom One Day Reminder',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'reminder',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'Assigned one day',
                'body' => 'Assigned one day body.',
            ],
            'meta' => [
                'seed' => [
                    'definition_key' => 'reminder_1_day',
                ],
            ],
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create([
                'surface' => 'webinar_registrations',
                'definition_key' => 'reminder_1_day',
                'source_config_path' => 'messaging.email.definitions.transactional.webinar.reminders.0',
            ]);

        $profile = WebinarScheduleProfile::factory()->create([
            'key' => 'multiple_reminder_assignment_profile',
            'name' => 'Multiple reminder assignment profile',
            'status' => WebinarScheduleProfile::STATUS_ACTIVE,
            'is_default' => true,
            'is_active' => true,
        ]);

        foreach ([
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
        ] as $item) {
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

        $registration = $this->registrationForContact($this->contactWithTransactionalEmailConsent());

        $registration->webinar()->update([
            'starts_at' => now()->addDays(2),
        ]);

        app(DispatchWebinarRegistrationMessagesAction::class)->handle($registration);

        $this->assertSame(3, ScheduledMessage::query()->count());
        $this->assertEqualsCanonicalizing([
            'Assigned one day',
            'Config thirty minute',
        ], ScheduledMessage::query()
            ->where('message_type', 'reminder')
            ->get()
            ->pluck('payload.subject')
            ->all());
        Queue::assertPushed(SendScheduledMessageJob::class, 3);
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
    }

    private function configureRegistrationScheduleProfile(string $confirmationTemplateKey): void
    {
        $profile = WebinarScheduleProfile::factory()->create([
            'key' => 'registration_assignment_test_profile',
            'name' => 'Registration assignment test profile',
            'status' => WebinarScheduleProfile::STATUS_ACTIVE,
            'is_default' => true,
            'is_active' => true,
        ]);

        WebinarScheduleProfileItem::factory()->create([
            'webinar_schedule_profile_id' => $profile->getKey(),
            'key' => 'email_confirmation',
            'context_key' => 'confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'message_template_key' => $confirmationTemplateKey,
            'timing' => 'immediate',
            'schedule' => null,
            'conditions' => [],
            'is_enabled' => true,
            'is_active' => true,
        ]);

        WebinarScheduleProfileItem::factory()->create([
            'webinar_schedule_profile_id' => $profile->getKey(),
            'key' => 'email_reminder',
            'context_key' => 'reminders',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'reminder',
            'dispatch_key' => 'registration_created',
            'message_template_key' => 'reminder',
            'timing' => 'scheduled',
            'schedule' => [
                'type' => 'anchored',
                'minutes' => -30,
            ],
            'conditions' => [],
            'is_enabled' => true,
            'is_active' => true,
        ]);
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
