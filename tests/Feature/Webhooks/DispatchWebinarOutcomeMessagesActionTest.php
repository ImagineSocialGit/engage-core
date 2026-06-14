<?php

namespace Tests\Feature\Webinars;

use App\Actions\Webinars\PostEvent\DispatchWebinarOutcomeMessagesAction;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Jobs\Messaging\SendScheduledMessageJob;
use App\Messaging\Payloads\EmailPayload;
use App\Messaging\Payloads\SmsPayload;
use App\Models\Contact;
use App\Models\ScheduledMessage;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchWebinarOutcomeMessagesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_attended_registration_dispatches_attended_messages(): void
    {
        Queue::fake();

        $this->configureOutcomeMessages();

        $webinar = Webinar::factory()->create([
            'ends_at' => now(),
        ]);

        $contact = $this->createContact();

        $registration = WebinarRegistration::query()->create([
            'contact_id' => $contact->id,
            'webinar_id' => $webinar->id,
            'webinar_slug' => $webinar->slug,
            'status' => 'registered',
            'source' => 'webinar_subdomain',
            'registered_at' => now(),
            'attended_at' => now(),
        ]);

        app(DispatchWebinarOutcomeMessagesAction::class)->handle(
            registration: $registration,
            event: 'webinar.ended',
        );

        $this->assertScheduled($registration, MessageChannel::Email->value, 'attended_follow_up');
        $this->assertScheduled($registration, MessageChannel::Sms->value, 'attended_follow_up');

        $this->assertDatabaseMissing('scheduled_messages', [
            'contact_id' => $contact->id,
            'message_type' => 'missed_follow_up',
        ]);

        Queue::assertPushed(SendScheduledMessageJob::class, 2);
    }

    public function test_missed_registration_dispatches_missed_messages(): void
    {
        Queue::fake();

        $this->configureOutcomeMessages();

        $webinar = Webinar::factory()->create([
            'ends_at' => now(),
        ]);

        $contact = $this->createContact();

        $registration = WebinarRegistration::query()->create([
            'contact_id' => $contact->id,
            'webinar_id' => $webinar->id,
            'webinar_slug' => $webinar->slug,
            'status' => 'registered',
            'source' => 'webinar_subdomain',
            'registered_at' => now(),
            'attended_at' => null,
        ]);

        app(DispatchWebinarOutcomeMessagesAction::class)->handle(
            registration: $registration,
            event: 'webinar.ended',
        );

        $this->assertScheduled($registration, MessageChannel::Email->value, 'missed_follow_up');
        $this->assertScheduled($registration, MessageChannel::Sms->value, 'missed_follow_up');

        $this->assertDatabaseMissing('scheduled_messages', [
            'contact_id' => $contact->id,
            'message_type' => 'attended_follow_up',
        ]);

        Queue::assertPushed(SendScheduledMessageJob::class, 2);
    }

    private function configureOutcomeMessages(): void
    {
        Config::set('webinars.post_event.outcome_messages', [
            'enabled' => true,
            'dispatch_key' => 'webinar_ended',

            'routes' => [
                'attended' => [
                    'enabled' => true,
                    'conditions' => [
                        [
                            'field' => 'registration.attended_at',
                            'operator' => 'filled',
                        ],
                    ],
                ],

                'missed' => [
                    'enabled' => true,
                    'conditions' => [
                        [
                            'field' => 'registration.attended_at',
                            'operator' => 'blank',
                        ],
                    ],
                ],
            ],
        ]);

        Config::set('messaging.email.transactional.webinar', [
            'attended_follow_up' => [
                'dispatch_key' => 'webinar_ended',
                'timing' => 'immediate',
                'conditions' => [
                    [
                        'field' => 'webinar_registration.attended_at',
                        'operator' => 'filled',
                    ],
                ],
                'payload_class' => EmailPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'subject' => 'Thanks for attending',
                    'body' => 'Thanks for attending.',
                ],
            ],

            'missed_follow_up' => [
                'dispatch_key' => 'webinar_ended',
                'timing' => 'immediate',
                'conditions' => [
                    [
                        'field' => 'webinar_registration.attended_at',
                        'operator' => 'blank',
                    ],
                ],
                'payload_class' => EmailPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'subject' => 'Sorry we missed you',
                    'body' => 'Sorry we missed you.',
                ],
            ],
        ]);

        Config::set('messaging.sms.transactional.webinar', [
            'attended_follow_up' => [
                'dispatch_key' => 'webinar_ended',
                'timing' => 'immediate',
                'conditions' => [
                    [
                        'field' => 'webinar_registration.attended_at',
                        'operator' => 'filled',
                    ],
                ],
                'payload_class' => SmsPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'message' => 'Thanks for attending.',
                ],
            ],

            'missed_follow_up' => [
                'dispatch_key' => 'webinar_ended',
                'timing' => 'immediate',
                'conditions' => [
                    [
                        'field' => 'webinar_registration.attended_at',
                        'operator' => 'blank',
                    ],
                ],
                'payload_class' => SmsPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'message' => 'Sorry we missed you.',
                ],
            ],
        ]);
    }

    private function createContact(): Contact
    {
        return Contact::query()->create([
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'name' => 'Jeff Yarnall',
            'email' => 'attendee@example.com',
            'phone' => '+15555550123',
            'status' => 'new',
            'source' => 'webinar',
        ]);
    }

    private function assertScheduled(
        WebinarRegistration $registration,
        string $channel,
        string $messageType,
    ): void {
        $scheduledMessage = ScheduledMessage::query()
            ->where('contact_id', $registration->contact->getKey())
            ->whereMorphedTo('context', $registration)
            ->where('channel', $channel)
            ->where('message_type', $messageType)
            ->where('purpose', MessagePurpose::Transactional->value)
            ->where('scope', 'webinar')
            ->first();

        $this->assertNotNull($scheduledMessage);
        $this->assertSame('pending', $scheduledMessage->status);
        $this->assertSame(['webinar_ended'], $scheduledMessage->meta['dispatch_keys']);
        $this->assertArrayHasKey('conditions', $scheduledMessage->meta);
    }
}