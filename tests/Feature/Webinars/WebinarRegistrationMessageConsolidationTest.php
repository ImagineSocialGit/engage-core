<?php

namespace Tests\Feature\Webinars;

use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Webinars\Actions\CreateWebinarRegistrationAction;
use App\Modules\Webinars\Actions\FinalizeWebinarRegistrationAction;
use App\Modules\Webinars\Jobs\SyncWebinarRegistrationToProviderJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebinarRegistrationMessageConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_four_selected_consents_schedule_one_acknowledgement_bearing_delivery_per_channel(): void
    {
        Queue::fake();

        $this->configureConsolidation();
        Config::set('client.name', 'Example Company');

        $this->configureChannelAvailability();
        $this->configureRegistrationDefinitions();
        $this->configureConfirmationScheduleProfile();

        $series = WebinarSeries::factory()->create();

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'external_id' => null,
            'starts_at' => now()->addDays(2),
        ]);

        $result = app(CreateWebinarRegistrationAction::class)->handle(
            validated: $this->registrationInput(
                transactionalSms: true,
                marketingEmail: true,
                marketingSms: true,
            ),
            request: Request::create('/register', 'POST'),
            webinar: $webinar,
        );

        $registration = $result->registration;

        (new SyncWebinarRegistrationToProviderJob(
            (int) $registration->getKey(),
        ))->handle(
            app(FinalizeWebinarRegistrationAction::class),
        );

        $messages = ScheduledMessage::query()
            ->where('context_type', $registration->getMorphClass())
            ->where('context_id', $registration->getKey())
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $messages);
        $this->assertSame(1, $messages->where('channel', 'email')->count());
        $this->assertSame(1, $messages->where('channel', 'sms')->count());
        $this->assertSame(0, $messages->where('message_type', 'opt_in')->count());

        $email = $messages->firstWhere('channel', 'email');
        $sms = $messages->firstWhere('channel', 'sms');

        $this->assertNotNull($email);
        $this->assertNotNull($sms);

        $this->assertSame('confirmation', $email->message_type);
        $this->assertSame('confirmation', $sms->message_type);

        $this->assertSame(
            'Original email confirmation',
            $email->payload['subject'],
        );
        $this->assertStringStartsWith(
            'Original email confirmation body.',
            (string) $email->payload['body'],
        );
        $this->assertStringContainsString(
            '{delivery_consolidation_webinar_email_acknowledgement}',
            (string) $email->payload['body'],
        );
        $this->assertStringContainsString(
            '{delivery_consolidation_marketing_email_acknowledgement}',
            (string) $email->payload['body'],
        );

        $this->assertEqualsCanonicalizing([
            'label' => 'Original CTA',
            'url' => '{webinar_join_url}',
        ], $email->payload['cta']);

        $this->assertEqualsCanonicalizing([
            'label' => 'Original secondary link',
            'url' => '{cancel_registration_url}',
        ], $email->payload['secondary_link']);

        $this->assertStringStartsWith(
            'Original SMS confirmation.',
            (string) $sms->payload['message'],
        );
        $this->assertStringContainsString(
            '{delivery_consolidation_webinar_sms_acknowledgement}',
            (string) $sms->payload['message'],
        );
        $this->assertStringContainsString(
            '{delivery_consolidation_marketing_sms_acknowledgement}',
            (string) $sms->payload['message'],
        );

        $this->assertEqualsCanonicalizing([
            'webinar.registration.confirmation',
            'consent.transactional.email.acknowledgement',
            'consent.marketing.email.acknowledgement',
        ], data_get($email->meta, 'delivery_consolidation.intent_keys'));

        $this->assertEqualsCanonicalizing([
            'webinar.registration.confirmation',
            'consent.transactional.sms.acknowledgement',
            'consent.marketing.sms.acknowledgement',
        ], data_get($sms->meta, 'delivery_consolidation.intent_keys'));

        $this->assertCount(
            2,
            data_get($email->meta, 'delivery_consolidation.consent_ids'),
        );
        $this->assertCount(
            2,
            data_get($sms->meta, 'delivery_consolidation.consent_ids'),
        );

        $this->assertSame(
            'primary_intent',
            data_get($email->meta, 'delivery_consolidation.template_source'),
        );
        $this->assertSame(
            'primary_intent',
            data_get($sms->meta, 'delivery_consolidation.template_source'),
        );

        Queue::assertPushed(SendScheduledMessageJob::class, 2);
    }

    public function test_acknowledgements_move_to_the_first_future_reminder_when_confirmation_is_past(): void
    {
        Queue::fake();

        $this->configureConsolidation();
        $this->configureChannelAvailability();
        $this->configureRegistrationDefinitionsWithReminder();
        $this->configurePastConfirmationFutureReminderProfile();

        $series = WebinarSeries::factory()->create();

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'external_id' => null,
            'starts_at' => now()->addMinutes(20),
        ]);

        $result = app(CreateWebinarRegistrationAction::class)->handle(
            validated: $this->registrationInput(
                marketingEmail: true,
            ),
            request: Request::create('/register', 'POST'),
            webinar: $webinar,
        );

        $registration = $result->registration;

        (new SyncWebinarRegistrationToProviderJob(
            (int) $registration->getKey(),
        ))->handle(
            app(FinalizeWebinarRegistrationAction::class),
        );

        $messages = ScheduledMessage::query()
            ->where('context_type', $registration->getMorphClass())
            ->where('context_id', $registration->getKey())
            ->get();

        $this->assertCount(1, $messages);

        $message = $messages->first();

        $this->assertSame('email', $message->channel);
        $this->assertSame('reminder', $message->message_type);
        $this->assertSame('Upcoming reminder', $message->payload['subject']);
        $this->assertStringContainsString(
            '{delivery_consolidation_webinar_email_acknowledgement}',
            (string) $message->payload['body'],
        );
        $this->assertStringContainsString(
            '{delivery_consolidation_marketing_email_acknowledgement}',
            (string) $message->payload['body'],
        );
        $this->assertSame(
            'webinar.registration.reminder_10_minute',
            data_get(
                $message->meta,
                'delivery_consolidation.primary_intent_key',
            ),
        );

        Queue::assertPushed(SendScheduledMessageJob::class, 1);
    }

    public function test_new_consent_on_an_existing_registration_merges_into_the_pending_lifecycle_delivery(): void
    {
        Queue::fake();

        $this->configureConsolidation();
        $this->configureChannelAvailability();
        $this->configureRegistrationDefinitions();
        $this->configureConfirmationScheduleProfile();

        $series = WebinarSeries::factory()->create();

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'external_id' => null,
            'starts_at' => now()->addDays(2),
        ]);

        $action = app(CreateWebinarRegistrationAction::class);

        $first = $action->handle(
            validated: $this->registrationInput(),
            request: Request::create('/register', 'POST'),
            webinar: $webinar,
        );

        (new SyncWebinarRegistrationToProviderJob(
            (int) $first->registration->getKey(),
        ))->handle(
            app(FinalizeWebinarRegistrationAction::class),
        );

        $before = ScheduledMessage::query()
            ->where('context_type', $first->registration->getMorphClass())
            ->where('context_id', $first->registration->getKey())
            ->sole();

        $this->assertStringContainsString(
            '{delivery_consolidation_webinar_email_acknowledgement}',
            (string) $before->payload['body'],
        );
        $this->assertStringNotContainsString(
            '{delivery_consolidation_marketing_email_acknowledgement}',
            (string) $before->payload['body'],
        );

        $action->handle(
            validated: $this->registrationInput(
                marketingEmail: true,
            ),
            request: Request::create('/register', 'POST'),
            webinar: $webinar,
        );

        $messages = ScheduledMessage::query()
            ->where('context_type', $first->registration->getMorphClass())
            ->where('context_id', $first->registration->getKey())
            ->get();

        $this->assertCount(1, $messages);

        $after = $messages->first();

        $this->assertSame($before->getKey(), $after->getKey());
        $this->assertStringContainsString(
            '{delivery_consolidation_webinar_email_acknowledgement}',
            (string) $after->payload['body'],
        );
        $this->assertStringContainsString(
            '{delivery_consolidation_marketing_email_acknowledgement}',
            (string) $after->payload['body'],
        );

        $this->assertEqualsCanonicalizing([
            'webinar.registration.confirmation',
            'consent.transactional.email.acknowledgement',
            'consent.marketing.email.acknowledgement',
        ], data_get($after->meta, 'delivery_consolidation.intent_keys'));

        $this->assertCount(
            2,
            data_get($after->meta, 'delivery_consolidation.consent_ids'),
        );

        Queue::assertPushed(SendScheduledMessageJob::class, 1);
    }

    public function test_new_consent_merges_into_an_overdue_pending_reminder_without_creating_a_standalone_delivery(): void
    {
        Queue::fake();

        $this->configureConsolidation();
        $this->configureChannelAvailability();
        $this->configureRegistrationDefinitionsWithReminder();
        $this->configurePastConfirmationFutureReminderProfile();

        $series = WebinarSeries::factory()->create();

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'external_id' => null,
            'starts_at' => now()->addMinutes(20),
        ]);

        $action = app(CreateWebinarRegistrationAction::class);

        $first = $action->handle(
            validated: $this->registrationInput(),
            request: Request::create('/register', 'POST'),
            webinar: $webinar,
        );

        (new SyncWebinarRegistrationToProviderJob(
            (int) $first->registration->getKey(),
        ))->handle(
            app(FinalizeWebinarRegistrationAction::class),
        );

        $before = ScheduledMessage::query()
            ->where('context_type', $first->registration->getMorphClass())
            ->where('context_id', $first->registration->getKey())
            ->sole();

        $this->assertSame('reminder', $before->message_type);
        $this->assertTrue($before->send_at->isFuture());
        $this->assertStringContainsString(
            '{delivery_consolidation_webinar_email_acknowledgement}',
            (string) $before->payload['body'],
        );

        $this->travel(11)->minutes();

        $action->handle(
            validated: $this->registrationInput(
                marketingEmail: true,
            ),
            request: Request::create('/register', 'POST'),
            webinar: $webinar,
        );

        $messages = ScheduledMessage::query()
            ->where('context_type', $first->registration->getMorphClass())
            ->where('context_id', $first->registration->getKey())
            ->get();

        $this->assertCount(1, $messages);

        $after = $messages->first();

        $this->assertSame($before->getKey(), $after->getKey());
        $this->assertSame('pending', $after->status);
        $this->assertTrue($after->send_at->isPast());
        $this->assertStringContainsString(
            '{delivery_consolidation_webinar_email_acknowledgement}',
            (string) $after->payload['body'],
        );
        $this->assertStringContainsString(
            '{delivery_consolidation_marketing_email_acknowledgement}',
            (string) $after->payload['body'],
        );
        $this->assertCount(
            2,
            data_get($after->meta, 'delivery_consolidation.consent_ids'),
        );

        Queue::assertPushed(SendScheduledMessageJob::class, 1);
    }

    public function test_consolidation_preserves_db_assigned_confirmation_template_and_profile_behavior(): void
    {
        Queue::fake();

        $this->configureConsolidation();
        $this->configureChannelAvailability();
        $this->configureRegistrationDefinitions();

        $profile = $this->configureConfirmationScheduleProfile();

        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'email.transactional.webinar.confirmation.custom',
            'name' => 'Custom Webinar Confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'CRM-selected confirmation subject',
                'body' => 'CRM-selected confirmation body.',
                'cta' => [
                    'label' => 'CRM-selected CTA',
                    'url' => '{webinar_join_url}',
                ],
                'secondary_link' => [
                    'label' => 'CRM-selected secondary link',
                    'url' => '{cancel_registration_url}',
                ],
            ],
            'source_config_path' =>
                'messaging.email.definitions.transactional.webinar.confirmations.0',
            'meta' => [
                'seed' => [
                    'definition_key' => 'confirmation',
                ],
            ],
        ]);

        $assignment = MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create([
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'definition_key' => 'confirmation',
            ]);

        $series = WebinarSeries::factory()->create();

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'external_id' => null,
            'starts_at' => now()->addDays(2),
        ]);

        $result = app(CreateWebinarRegistrationAction::class)->handle(
            validated: [
                'first_name' => 'Jeff',
                'last_name' => 'Yarnall',
                'email' => 'jeff@example.com',
                'transactional_email_consent' => true,
            ],
            request: Request::create('/register', 'POST'),
            webinar: $webinar,
        );

        $registration = $result->registration;

        (new SyncWebinarRegistrationToProviderJob(
            (int) $registration->getKey(),
        ))->handle(
            app(FinalizeWebinarRegistrationAction::class),
        );

        $message = ScheduledMessage::query()
            ->where('context_type', $registration->getMorphClass())
            ->where('context_id', $registration->getKey())
            ->where('channel', 'email')
            ->where('message_type', 'confirmation')
            ->firstOrFail();

        $profileItem = $profile->items()
            ->where('channel', 'email')
            ->where('message_type', 'confirmation')
            ->firstOrFail();

        $this->assertSame(
            'CRM-selected confirmation subject',
            $message->payload['subject'],
        );

        $body = (string) $message->payload['body'];
        $acknowledgementPlaceholder =
            '{delivery_consolidation_webinar_email_acknowledgement}';

        $this->assertStringStartsWith(
            'CRM-selected confirmation body.',
            $body,
        );

        $this->assertStringContainsString(
            $acknowledgementPlaceholder,
            $body,
        );

        $primaryBodyPosition = strpos(
            $body,
            'CRM-selected confirmation body.',
        );

        $acknowledgementPosition = strpos(
            $body,
            $acknowledgementPlaceholder,
        );

        $this->assertIsInt($primaryBodyPosition);
        $this->assertIsInt($acknowledgementPosition);
        $this->assertTrue(
            $primaryBodyPosition < $acknowledgementPosition,
            'The acknowledgement should follow the CRM-selected primary body.',
        );

        $this->assertEqualsCanonicalizing([
            'label' => 'CRM-selected CTA',
            'url' => '{webinar_join_url}',
        ], $message->payload['cta']);

        $this->assertEqualsCanonicalizing([
            'label' => 'CRM-selected secondary link',
            'url' => '{cancel_registration_url}',
        ], $message->payload['secondary_link']);

        $this->assertSame(
            $preset->getKey(),
            data_get($message->meta, 'message_template_preset.id'),
        );

        $this->assertSame(
            $assignment->getKey(),
            data_get($message->meta, 'message_template_assignment.id'),
        );

        $this->assertSame(
            $profileItem->getMorphClass(),
            $message->behavior_owner_type,
        );

        $this->assertSame(
            $profileItem->getKey(),
            $message->behavior_owner_id,
        );

        $this->assertSame(
            'confirmation',
            data_get($message->meta, 'delivery_consolidation.template_key'),
        );

        $this->assertSame(
            'primary_intent',
            data_get($message->meta, 'delivery_consolidation.template_source'),
        );

        $this->assertEqualsCanonicalizing([
            'webinar.registration.confirmation',
            'consent.transactional.email.acknowledgement',
        ], data_get(
            $message->meta,
            'delivery_consolidation.intent_keys',
        ));

        Queue::assertPushed(SendScheduledMessageJob::class, 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationInput(
        bool $transactionalSms = false,
        bool $marketingEmail = false,
        bool $marketingSms = false,
    ): array {
        return array_filter([
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'email' => 'jeff@example.com',
            'phone' => ($transactionalSms || $marketingSms)
                ? '(555) 555-0123'
                : null,
            'transactional_email_consent' => true,
            'transactional_sms_consent' => $transactionalSms,
            'marketing_email_consent' => $marketingEmail,
            'marketing_sms_consent' => $marketingSms,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function configureRegistrationDefinitionsWithReminder(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmations' => [[
                'key' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'message_type' => 'confirmation',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Past confirmation',
                    'body' => 'Past confirmation body.',
                ],
            ]],
            'reminders' => [[
                'key' => 'reminder_10_minute',
                'dispatch_key' => 'registration_created',
                'message_type' => 'reminder',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'payload_class' => EmailPayload::class,
                'queue' => 'reminders',
                'payload' => [
                    'subject' => 'Upcoming reminder',
                    'body' => 'Upcoming reminder body.',
                ],
            ]],
        ]);

        Config::set(
            'messaging.sms.definitions.transactional.webinar',
            [],
        );
    }

    private function configurePastConfirmationFutureReminderProfile(): WebinarScheduleProfile
    {
        $profile = WebinarScheduleProfile::factory()->create([
            'key' => 'reminder_fallback_test',
            'name' => 'Reminder Fallback Test',
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
            'message_template_key' => 'confirmation',
            'is_enabled' => true,
            'is_active' => true,
            'timing' => 'scheduled',
            'schedule' => [
                'type' => 'anchored',
                'minutes' => -40,
            ],
            'conditions' => [],
        ]);

        WebinarScheduleProfileItem::factory()->create([
            'webinar_schedule_profile_id' => $profile->getKey(),
            'key' => 'email_reminder_10_minute',
            'context_key' => 'reminder',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'reminder',
            'dispatch_key' => 'registration_created',
            'message_template_key' => 'reminder_10_minute',
            'is_enabled' => true,
            'is_active' => true,
            'timing' => 'scheduled',
            'schedule' => [
                'type' => 'anchored',
                'minutes' => -10,
            ],
            'conditions' => [],
        ]);

        return $profile;
    }

    private function configureConsolidation(): void
    {
        $config = require base_path('config/messaging/delivery_consolidation.php');
        data_set($config, 'policies.webinar_registration.enabled', true);

        Config::set('client.name', 'Example Company');
        Config::set('messaging.delivery_consolidation', $config);
    }

    private function configureChannelAvailability(): void
    {
        foreach (['email', 'sms'] as $channel) {
            Config::set("messaging.channel_availability.{$channel}", [
                'runtime_supported' => true,
                'provider_enabled' => true,
                'requires_explicit_opt_in' => $channel === 'sms',
                'surfaces' => [
                    'webinar_registrations' => true,
                ],
                'purpose_scopes' => [
                    'transactional:webinar' => true,
                    'marketing:webinar_nurture' => true,
                    'marketing:webinar' => true,
                ],
            ]);
        }
    }

    private function configureRegistrationDefinitions(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmations' => [[
                'key' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'message_type' => 'confirmation',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Original email confirmation',
                    'body' => 'Original email confirmation body.',
                    'cta' => [
                        'label' => 'Original CTA',
                        'url' => '{webinar_join_url}',
                    ],
                    'secondary_link' => [
                        'label' => 'Original secondary link',
                        'url' => '{cancel_registration_url}',
                    ],
                ],
            ]],
        ]);

        Config::set('messaging.sms.definitions.transactional.webinar', [
            'confirmations' => [[
                'key' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'message_type' => 'confirmation',
                'channel' => 'sms',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'payload_class' => SmsPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'message' => 'Original SMS confirmation.',
                ],
            ]],
        ]);
    }

    private function configureConfirmationScheduleProfile(): WebinarScheduleProfile
    {
        $profile = WebinarScheduleProfile::factory()->create([
            'key' => 'consolidation_test',
            'name' => 'Consolidation Test',
            'status' => WebinarScheduleProfile::STATUS_ACTIVE,
            'is_default' => true,
            'is_active' => true,
        ]);

        foreach (['email', 'sms'] as $channel) {
            WebinarScheduleProfileItem::factory()->create([
                'webinar_schedule_profile_id' => $profile->getKey(),
                'key' => "{$channel}_confirmation",
                'context_key' => 'confirmation',
                'channel' => $channel,
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'message_template_key' => 'confirmation',
                'is_enabled' => true,
                'is_active' => true,
                'timing' => 'immediate',
                'schedule' => null,
                'conditions' => [],
            ]);
        }

        return $profile;
    }
}