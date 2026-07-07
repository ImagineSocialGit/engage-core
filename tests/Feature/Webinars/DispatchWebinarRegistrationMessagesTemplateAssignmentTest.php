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
            'timing' => 'immediate',
            'payload' => [
                'subject' => 'DB confirmation for {first_name}',
                'body' => 'DB selected confirmation copy.',
            ],
            'source_config_path' => 'messaging.email.transactional.webinar.confirmation',
        ]);

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
        $this->assertSame('messaging.email.transactional.webinar.reminder', $reminder->definition_config_path);

        $this->assertSame(2, ScheduledMessage::query()->count());
        Queue::assertPushed(SendScheduledMessageJob::class, 2);
    }

    private function configureRegistrationMessages(): void
    {
        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'timing' => 'immediate',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Config confirmation for {first_name}',
                    'body' => 'Config confirmation copy.',
                ],
            ],

            'reminder' => [
                'dispatch_key' => 'registration_created',
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'anchored',
                    'minutes' => -30,
                ],
                'payload_class' => EmailPayload::class,
                'queue' => 'reminders',
                'payload' => [
                    'subject' => 'Config reminder',
                    'body' => 'Starts soon.',
                ],
            ],
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
