<?php

namespace Tests\Feature\Webinars;

use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Webinars\Actions\ImportWebinarRegistrationAction;
use App\Modules\Webinars\Data\WebinarRegistrationImportRow;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportWebinarRegistrationReminderDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Carbon::setTestNow('2026-07-13 17:00:00');

        $this->configureChannelAvailability();
        $this->configureMessageDefinitions();
    }

    public function test_import_schedules_only_future_reminders_and_never_confirmation(): void
    {
        $profile = $this->createScheduleProfile();
        $webinar = Webinar::factory()->create([
            'slug' => 'first-time-homebuyer-class',
            'webinar_schedule_profile_id' => $profile->getKey(),
            'starts_at' => now()->addHours(2),
        ]);

        $result = app(ImportWebinarRegistrationAction::class)->handle(
            webinar: $webinar,
            row: WebinarRegistrationImportRow::fromArray([
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'email' => 'jane@example.com',
                'phone' => '+15555550123',
                'transactional_email_consent' => true,
                'transactional_sms_consent' => true,
            ]),
            registeredAt: now(),
        );

        $this->assertSame(4, $result->remindersScheduled);

        $messages = ScheduledMessage::query()->orderBy('channel')->orderBy('send_at')->get();

        $this->assertCount(4, $messages);
        $this->assertEquals(['email', 'sms'], $messages->pluck('channel')->unique()->values()->all());
        $this->assertEquals(['reminder'], $messages->pluck('message_type')->unique()->values()->all());

        $this->assertTrue($messages->every(
            fn (ScheduledMessage $message): bool => $message->send_at->isFuture()
                && data_get($message->meta, 'webinar_schedule.item_key') !== 'email_confirmation'
                && data_get($message->meta, 'webinar_schedule.item_key') !== 'sms_confirmation'
        ));

        $this->assertDatabaseMissing('scheduled_messages', [
            'message_type' => 'confirmation',
        ]);

        $this->assertDatabaseMissing('scheduled_messages', [
            'definition_config_path' => 'messaging.email.definitions.transactional.webinar.reminder.0',
        ]);

        $this->assertDatabaseMissing('scheduled_messages', [
            'definition_config_path' => 'messaging.sms.definitions.transactional.webinar.reminder.0',
        ]);
    }

    public function test_import_schedules_no_sms_without_transactional_sms_consent(): void
    {
        $profile = $this->createScheduleProfile();
        $webinar = Webinar::factory()->create([
            'slug' => 'first-time-homebuyer-class',
            'webinar_schedule_profile_id' => $profile->getKey(),
            'starts_at' => now()->addHours(2),
        ]);

        $result = app(ImportWebinarRegistrationAction::class)->handle(
            webinar: $webinar,
            row: WebinarRegistrationImportRow::fromArray([
                'first_name' => 'Jane',
                'email' => 'jane@example.com',
                'phone' => '+15555550123',
                'transactional_email_consent' => true,
                'transactional_sms_consent' => false,
            ]),
        );

        $this->assertSame(2, $result->remindersScheduled);
        $this->assertDatabaseCount('scheduled_messages', 2);
        $this->assertDatabaseMissing('scheduled_messages', ['channel' => 'sms']);
        $this->assertEquals(
            ['email'],
            data_get($result->registration->meta, 'accepted_channels.transactional'),
        );
    }

    public function test_rerun_does_not_duplicate_future_reminders(): void
    {
        $profile = $this->createScheduleProfile();
        $webinar = Webinar::factory()->create([
            'slug' => 'first-time-homebuyer-class',
            'webinar_schedule_profile_id' => $profile->getKey(),
            'starts_at' => now()->addHours(2),
        ]);

        $row = WebinarRegistrationImportRow::fromArray([
            'first_name' => 'Jane',
            'email' => 'jane@example.com',
            'phone' => '+15555550123',
            'transactional_email_consent' => true,
            'transactional_sms_consent' => true,
        ]);

        $action = app(ImportWebinarRegistrationAction::class);

        $first = $action->handle($webinar, $row, now());
        $second = $action->handle($webinar, $row, now()->addMinute());

        $this->assertSame(4, $first->remindersScheduled);
        $this->assertSame(4, $second->remindersScheduled);
        $this->assertDatabaseCount('scheduled_messages', 4);
        $this->assertCount(4, ScheduledMessage::query()->pluck('dedupe_key')->unique());
    }

    public function test_import_can_skip_reminder_dispatch_for_state_only_checkpoint(): void
    {
        $profile = $this->createScheduleProfile();
        $webinar = Webinar::factory()->create([
            'slug' => 'first-time-homebuyer-class',
            'webinar_schedule_profile_id' => $profile->getKey(),
            'starts_at' => now()->addHours(2),
        ]);

        $result = app(ImportWebinarRegistrationAction::class)->handle(
            webinar: $webinar,
            row: WebinarRegistrationImportRow::fromArray([
                'email' => 'jane@example.com',
                'transactional_email_consent' => true,
            ]),
            scheduleReminders: false,
        );

        $this->assertSame(0, $result->remindersScheduled);
        $this->assertDatabaseCount('scheduled_messages', 0);
    }

    private function configureChannelAvailability(): void
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
                'webinar_registrations' => true,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
            ],
        ]);
    }

    private function configureMessageDefinitions(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmation' => [
                'key' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Registered',
                    'body' => 'You are registered.',
                ],
            ],
            'reminder' => [
                $this->emailReminderDefinition('reminder_3_hour', 'Three hours'),
                $this->emailReminderDefinition('reminder_30_minute', 'Thirty minutes'),
                $this->emailReminderDefinition('reminder_10_minute', 'Ten minutes'),
            ],
        ]);

        Config::set('messaging.sms.definitions.transactional.webinar', [
            'confirmation' => [
                'key' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'payload_class' => SmsPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'message' => 'You are registered.',
                ],
            ],
            'reminder' => [
                $this->smsReminderDefinition('reminder_3_hour', 'Three hours'),
                $this->smsReminderDefinition('reminder_30_minute', 'Thirty minutes'),
                $this->smsReminderDefinition('reminder_10_minute', 'Ten minutes'),
            ],
        ]);
    }

    private function createScheduleProfile(): WebinarScheduleProfile
    {
        $profile = WebinarScheduleProfile::factory()->create([
            'key' => 'import_test_profile',
            'name' => 'Import test profile',
            'is_default' => false,
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
                'timing' => 'immediate',
                'schedule' => null,
                'is_enabled' => true,
                'is_active' => true,
            ]);

            foreach ([
                ['reminder_3_hour', -180],
                ['reminder_30_minute', -30],
                ['reminder_10_minute', -10],
            ] as [$key, $minutes]) {
                WebinarScheduleProfileItem::factory()->create([
                    'webinar_schedule_profile_id' => $profile->getKey(),
                    'key' => "{$channel}_{$key}",
                    'context_key' => 'reminders',
                    'channel' => $channel,
                    'purpose' => 'transactional',
                    'scope' => 'webinar',
                    'surface' => 'webinar_registrations',
                    'message_type' => 'reminder',
                    'dispatch_key' => 'registration_created',
                    'message_template_key' => $key,
                    'timing' => 'scheduled',
                    'schedule' => [
                        'type' => 'anchored',
                        'minutes' => $minutes,
                    ],
                    'conditions' => [],
                    'is_enabled' => true,
                    'is_active' => true,
                ]);
            }
        }

        return $profile;
    }

    /** @return array<string, mixed> */
    private function emailReminderDefinition(string $key, string $subject): array
    {
        return [
            'key' => $key,
            'dispatch_key' => 'registration_created',
            'payload_class' => EmailPayload::class,
            'queue' => 'reminders',
            'payload' => [
                'subject' => $subject,
                'body' => $subject.'.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function smsReminderDefinition(string $key, string $message): array
    {
        return [
            'key' => $key,
            'dispatch_key' => 'registration_created',
            'payload_class' => SmsPayload::class,
            'queue' => 'reminders',
            'payload' => [
                'message' => $message.'.',
            ],
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}