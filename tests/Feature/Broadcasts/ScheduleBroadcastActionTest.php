<?php

namespace Tests\Feature\Broadcasts;

use App\Modules\Broadcasts\Actions\ScheduleBroadcastAction;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScheduleBroadcastActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_schedules_a_broadcast_to_all_contacts_through_messaging(): void
    {
        $contacts = Contact::factory()->count(2)->create();

        $broadcast = Broadcast::factory()->create([
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'dispatch_key' => 'broadcast_send',
            'message_type' => 'broadcast',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'send_at' => now()->addHour(),
            'recipient_filter' => [
                'type' => 'all',
            ],
            'payload' => [
                'subject' => 'Monthly update',
                'body' => 'Here is the monthly update.',
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->twice()
            ->andReturnUsing(function (...$arguments): array {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $broadcast = $arguments['context'] ?? $arguments[6];

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                        'channel' => 'email',
                        'purpose' => 'marketing',
                        'scope' => 'broadcast',
                        'message_type' => 'broadcast',
                        'payload_class' => EmailPayload::class,
                        'queue' => 'marketing',
                        'dispatch_keys' => ['broadcast_send'],
                        'payload' => [
                            'subject' => 'Monthly update',
                            'body' => 'Here is the monthly update.',
                        ],
                    ]),
                ];
            });

        $scheduledBroadcast = app(ScheduleBroadcastAction::class)->handle($broadcast);

        $this->assertSame(Broadcast::STATUS_SCHEDULED, $scheduledBroadcast->status);
        $this->assertSame(2, $scheduledBroadcast->recipient_count);
        $this->assertSame(2, $scheduledBroadcast->scheduled_count);

        foreach ($contacts as $contact) {
            $recipient = BroadcastRecipient::query()
                ->where('broadcast_id', $broadcast->id)
                ->where('contact_id', $contact->id)
                ->first();

            $this->assertNotNull($recipient);
            $this->assertSame(BroadcastRecipient::STATUS_SCHEDULED, $recipient->status);
            $this->assertCount(1, $recipient->scheduled_message_ids);
            $this->assertNull($recipient->skip_reason);
        }
    }

    public function test_it_schedules_an_sms_broadcast_to_all_contacts_through_messaging(): void
    {
        $contacts = Contact::factory()->count(2)->create([
            'phone' => '+15555550123',
        ]);

        config()->set('messaging.channel_availability.sms.runtime_supported', true);
        config()->set('messaging.channel_availability.sms.provider_enabled', true);
        config()->set('messaging.channel_availability.sms.surfaces.broadcasts', true);
        config()->set('messaging.channel_availability.sms.purpose_scopes', [
            'marketing:broadcast' => true,
        ]);

        $broadcast = Broadcast::factory()->create([
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'dispatch_key' => Broadcast::DEFAULT_DISPATCH_KEY,
            'message_type' => Broadcast::DEFAULT_MESSAGE_TYPE,
            'payload_class' => SmsPayload::class,
            'queue' => 'marketing',
            'send_at' => now()->addHour(),
            'recipient_filter' => [
                'type' => 'all',
            ],
            'payload' => [
                'message' => 'This is an SMS broadcast.',
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->twice()
            ->andReturnUsing(function (...$arguments): array {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $channel = $arguments['channel'] ?? $arguments[1];
                $purpose = $arguments['purpose'] ?? $arguments[2];
                $scope = $arguments['scope'] ?? $arguments[3];
                
                $payload = collect($arguments)->first(
                    fn (mixed $argument): bool => is_array($argument)
                        && ($argument['message'] ?? null) === 'This is an SMS broadcast.',
                );

                $broadcast = collect($arguments)->first(
                    fn (mixed $argument): bool => $argument instanceof Broadcast,
                );

                $meta = collect($arguments)->first(
                    fn (mixed $argument): bool => is_array($argument)
                        && array_key_exists('queue', $argument)
                        && array_key_exists('broadcast_id', $argument),
                );

                $definitions = collect($arguments)->first(
                    fn (mixed $argument): bool => is_array($argument)
                        && isset($argument[0])
                        && is_array($argument[0])
                        && array_key_exists('dispatch_key', $argument[0]),
                );

                $this->assertInstanceOf(Broadcast::class, $broadcast);
                $this->assertSame('sms', $channel);
                $this->assertSame('marketing', $purpose);
                $this->assertSame('broadcast', $scope);
                $this->assertSame([
                    'message' => 'This is an SMS broadcast.',
                ], $payload);

                $this->assertIsArray($meta);
                $this->assertSame('marketing', $meta['queue']);

                $this->assertIsArray($definitions);
                $this->assertSame(Broadcast::DEFAULT_DISPATCH_KEY, $definitions[0]['dispatch_key']);
                $this->assertSame(Broadcast::DEFAULT_MESSAGE_TYPE, $definitions[0]['message_type']);
                $this->assertSame('sms', $definitions[0]['channel']);
                $this->assertSame('marketing', $definitions[0]['purpose']);
                $this->assertSame('broadcast', $definitions[0]['scope']);
                $this->assertSame(SmsPayload::class, $definitions[0]['payload_class']);
                $this->assertSame([
                    'message' => 'This is an SMS broadcast.',
                ], $definitions[0]['payload']);
                $this->assertSame([], $definitions[0]['consent_policy']);

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                        'channel' => 'sms',
                        'purpose' => 'marketing',
                        'scope' => 'broadcast',
                        'message_type' => Broadcast::DEFAULT_MESSAGE_TYPE,
                        'payload_class' => SmsPayload::class,
                        'queue' => 'marketing',
                        'dispatch_keys' => [Broadcast::DEFAULT_DISPATCH_KEY],
                        'payload' => [
                            'message' => 'This is an SMS broadcast.',
                        ],
                    ]),
                ];
            });

        $scheduledBroadcast = app(ScheduleBroadcastAction::class)->handle($broadcast);

        $this->assertSame(Broadcast::STATUS_SCHEDULED, $scheduledBroadcast->status);
        $this->assertSame(2, $scheduledBroadcast->recipient_count);
        $this->assertSame(2, $scheduledBroadcast->scheduled_count);

        foreach ($contacts as $contact) {
            $recipient = BroadcastRecipient::query()
                ->where('broadcast_id', $broadcast->id)
                ->where('contact_id', $contact->id)
                ->first();

            $this->assertNotNull($recipient);
            $this->assertSame(BroadcastRecipient::STATUS_SCHEDULED, $recipient->status);
            $this->assertCount(1, $recipient->scheduled_message_ids);
            $this->assertNull($recipient->skip_reason);
        }
    }

    public function test_regular_email_broadcasts_do_not_request_imported_contact_permission_policy(): void
    {
        Contact::factory()->create([
            'email' => 'imported@example.com',
            'source' => 'import',
        ]);

        $broadcast = Broadcast::factory()->create([
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'message_type' => Broadcast::DEFAULT_MESSAGE_TYPE,
            'payload_class' => EmailPayload::class,
            'recipient_filter' => [
                'type' => 'all',
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (...$arguments): array {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $broadcast = $arguments['context'] ?? $arguments[6];
                $meta = $arguments['meta'] ?? $arguments[9];
                $definitions = $arguments['definitions'] ?? $arguments[11];

                $this->assertSame([], $meta['consent_policy']);
                $this->assertSame([], $definitions[0]['consent_policy']);
                $this->assertSame([], $definitions[0]['meta']['consent_policy']);

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                    ]),
                ];
            });

        app(ScheduleBroadcastAction::class)->handle($broadcast);
    }

    public function test_permission_invitation_broadcasts_request_one_time_imported_contact_permission_policy(): void
    {
        Contact::factory()->create([
            'email' => 'imported@example.com',
            'source' => 'import',
        ]);

        $broadcast = Broadcast::factory()->create([
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'dispatch_key' => Broadcast::PERMISSION_INVITATION_DISPATCH_KEY,
            'message_type' => Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'payload_class' => EmailPayload::class,
            'recipient_filter' => [
                'type' => 'imported',
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (...$arguments): array {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $broadcast = $arguments['context'] ?? $arguments[6];
                $meta = $arguments['meta'] ?? $arguments[9];
                $definitions = $arguments['definitions'] ?? $arguments[11];

                $this->assertSame([
                    'permission_invitation' => [
                        'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
                        'one_time' => true,
                    ],
                ], $meta['consent_policy']);

                $this->assertSame($meta['consent_policy'], $definitions[0]['consent_policy']);
                $this->assertSame($meta['consent_policy'], $definitions[0]['meta']['consent_policy']);

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                    ]),
                ];
            });

        app(ScheduleBroadcastAction::class)->handle($broadcast);
    }

    public function test_permission_invitation_broadcasts_only_schedule_imported_contacts_without_message_consent(): void
    {
        $eligible = Contact::factory()->create([
            'email' => 'eligible@example.com',
            'source' => 'import',
        ]);

        $alreadyConsented = Contact::factory()->create([
            'email' => 'consented@example.com',
            'source' => 'import',
        ]);

        Contact::factory()->create([
            'email' => 'not-imported@example.com',
            'source' => 'crm',
        ]);

        MessageConsent::query()->create([
            'contact_id' => $alreadyConsented->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'consented_at' => now(),
            'source' => 'test',
        ]);

        $broadcast = Broadcast::factory()->create([
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'dispatch_key' => Broadcast::PERMISSION_INVITATION_DISPATCH_KEY,
            'message_type' => Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'payload_class' => EmailPayload::class,
            'recipient_filter' => [
                'type' => 'imported',
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (...$arguments) use ($eligible): array {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $broadcast = $arguments['context'] ?? $arguments[6];

                $this->assertSame($eligible->id, $recipient->id);

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                    ]),
                ];
            });

        $scheduledBroadcast = app(ScheduleBroadcastAction::class)->handle($broadcast);

        $this->assertSame(1, $scheduledBroadcast->recipient_count);
        $this->assertSame(1, $scheduledBroadcast->scheduled_count);

        $this->assertDatabaseHas('broadcast_recipients', [
            'broadcast_id' => $broadcast->id,
            'contact_id' => $eligible->id,
            'status' => BroadcastRecipient::STATUS_SCHEDULED,
        ]);

        $this->assertDatabaseMissing('broadcast_recipients', [
            'broadcast_id' => $broadcast->id,
            'contact_id' => $alreadyConsented->id,
        ]);
    }

    public function test_permission_invitation_broadcasts_do_not_schedule_contacts_with_existing_permission_invitation(): void
    {
        $eligible = Contact::factory()->create([
            'email' => 'eligible@example.com',
            'source' => 'import',
        ]);

        $alreadyInvited = Contact::factory()->create([
            'email' => 'already-invited@example.com',
            'source' => 'import',
        ]);

        ContactPermissionInvitation::query()->create([
            'contact_id' => $alreadyInvited->id,
            'channel' => ContactPermissionInvitation::CHANNEL_EMAIL,
            'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
            'status' => ContactPermissionInvitation::STATUS_SENT,
            'claimed_at' => now()->subHour(),
            'sent_at' => now()->subMinutes(55),
        ]);

        $broadcast = Broadcast::factory()->create([
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'dispatch_key' => Broadcast::PERMISSION_INVITATION_DISPATCH_KEY,
            'message_type' => Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'payload_class' => EmailPayload::class,
            'recipient_filter' => [
                'type' => 'imported',
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (...$arguments) use ($eligible): array {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $broadcast = $arguments['context'] ?? $arguments[6];

                $this->assertSame($eligible->id, $recipient->id);

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                    ]),
                ];
            });

        $scheduledBroadcast = app(ScheduleBroadcastAction::class)->handle($broadcast);

        $this->assertSame(1, $scheduledBroadcast->recipient_count);
        $this->assertSame(1, $scheduledBroadcast->scheduled_count);

        $this->assertDatabaseHas('broadcast_recipients', [
            'broadcast_id' => $broadcast->id,
            'contact_id' => $eligible->id,
            'status' => BroadcastRecipient::STATUS_SCHEDULED,
        ]);

        $this->assertDatabaseMissing('broadcast_recipients', [
            'broadcast_id' => $broadcast->id,
            'contact_id' => $alreadyInvited->id,
        ]);
    }

    public function test_it_does_not_request_imported_contact_permission_policy_for_sms_broadcasts(): void
    {
        Contact::factory()->create([
            'phone' => '+15555550123',
            'source' => 'import',
        ]);

        config()->set('messaging.channel_availability.sms.runtime_supported', true);
        config()->set('messaging.channel_availability.sms.provider_enabled', true);
        config()->set('messaging.channel_availability.sms.surfaces.broadcasts', true);
        config()->set('messaging.channel_availability.sms.purpose_scopes', [
            'marketing:broadcast' => true,
        ]);

        $broadcast = Broadcast::factory()->create([
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'payload_class' => SmsPayload::class,
            'recipient_filter' => [
                'type' => 'all',
            ],
            'payload' => [
                'message' => 'Broadcast message',
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (...$arguments): array {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $broadcast = $arguments['context'] ?? $arguments[6];
                $meta = $arguments['meta'] ?? $arguments[9];
                $definitions = $arguments['definitions'] ?? $arguments[11];

                $this->assertSame([], $meta['consent_policy']);
                $this->assertSame([], $definitions[0]['consent_policy']);
                $this->assertSame([], $definitions[0]['meta']['consent_policy']);

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                    ]),
                ];
            });

        app(ScheduleBroadcastAction::class)->handle($broadcast);
    }

    public function test_it_skips_broadcast_recipients_when_channel_is_not_available_for_broadcasts(): void
    {
        $contact = Contact::factory()->create([
            'phone' => '+15555550123',
        ]);

        config()->set('messaging.channel_availability.sms.runtime_supported', true);
        config()->set('messaging.channel_availability.sms.provider_enabled', true);
        config()->set('messaging.channel_availability.sms.surfaces.broadcasts', false);
        config()->set('messaging.channel_availability.sms.purpose_scopes', [
            'marketing:broadcast' => true,
        ]);

        $broadcast = Broadcast::factory()->create([
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'dispatch_key' => Broadcast::DEFAULT_DISPATCH_KEY,
            'message_type' => Broadcast::DEFAULT_MESSAGE_TYPE,
            'payload_class' => SmsPayload::class,
            'queue' => 'marketing',
            'recipient_filter' => [
                'type' => 'all',
            ],
            'payload' => [
                'message' => 'Broadcast message',
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldNotReceive('handle');

        $scheduledBroadcast = app(ScheduleBroadcastAction::class)->handle($broadcast);

        $recipient = BroadcastRecipient::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('contact_id', $contact->id)
            ->first();

        $this->assertSame(Broadcast::STATUS_SCHEDULED, $scheduledBroadcast->status);
        $this->assertSame(1, $scheduledBroadcast->recipient_count);
        $this->assertSame(0, $scheduledBroadcast->scheduled_count);
        $this->assertSame(0, ScheduledMessage::query()->count());

        $this->assertNotNull($recipient);
        $this->assertSame(BroadcastRecipient::STATUS_SKIPPED, $recipient->status);
        $this->assertNull($recipient->scheduled_message_ids);
        $this->assertSame('broadcast_channel_unavailable', $recipient->skip_reason);
        $this->assertSame('sms', data_get($recipient->meta, 'broadcast.channel'));
        $this->assertSame('broadcasts', data_get($recipient->meta, 'broadcast.surface'));
    }

    public function test_it_marks_sms_recipient_skipped_when_contact_has_no_phone(): void
    {
        $contact = Contact::factory()->create([
            'phone' => null,
        ]);

        config()->set('messaging.channel_availability.sms.runtime_supported', true);
        config()->set('messaging.channel_availability.sms.provider_enabled', true);
        config()->set('messaging.channel_availability.sms.surfaces.broadcasts', true);
        config()->set('messaging.channel_availability.sms.purpose_scopes', [
            'marketing:broadcast' => true,
        ]);

        $broadcast = Broadcast::factory()->create([
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'dispatch_key' => Broadcast::DEFAULT_DISPATCH_KEY,
            'message_type' => Broadcast::DEFAULT_MESSAGE_TYPE,
            'payload_class' => SmsPayload::class,
            'queue' => 'marketing',
            'recipient_filter' => [
                'type' => 'all',
            ],
            'payload' => [
                'message' => 'Broadcast message',
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturn([]);

        $scheduledBroadcast = app(ScheduleBroadcastAction::class)->handle($broadcast);

        $recipient = BroadcastRecipient::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('contact_id', $contact->id)
            ->first();

        $this->assertSame(Broadcast::STATUS_SCHEDULED, $scheduledBroadcast->status);
        $this->assertSame(1, $scheduledBroadcast->recipient_count);
        $this->assertSame(0, $scheduledBroadcast->scheduled_count);

        $this->assertNotNull($recipient);
        $this->assertSame(BroadcastRecipient::STATUS_SKIPPED, $recipient->status);
        $this->assertNull($recipient->scheduled_message_ids);
        $this->assertSame('not_scheduled_by_messaging', $recipient->skip_reason);
    }

    public function test_it_schedules_a_broadcast_to_specific_contacts(): void
    {
        $included = Contact::factory()->create();
        $excluded = Contact::factory()->create();

        $broadcast = Broadcast::factory()->create([
            'recipient_filter' => [
                'type' => 'contact_ids',
                'contact_ids' => [$included->id],
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (...$arguments): array {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $broadcast = $arguments['context'] ?? $arguments[6];

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                    ]),
                ];
            });

        app(ScheduleBroadcastAction::class)->handle($broadcast);

        $this->assertDatabaseHas('broadcast_recipients', [
            'broadcast_id' => $broadcast->id,
            'contact_id' => $included->id,
            'status' => BroadcastRecipient::STATUS_SCHEDULED,
        ]);

        $this->assertDatabaseMissing('broadcast_recipients', [
            'broadcast_id' => $broadcast->id,
            'contact_id' => $excluded->id,
        ]);
    }

    public function test_it_schedules_a_broadcast_to_contacts_with_matching_tags(): void
    {
        $tagged = Contact::factory()->create();
        $untagged = Contact::factory()->create();

        ContactTag::query()->create([
            'contact_id' => $tagged->id,
            'tag' => 'homebuyer',
        ]);

        ContactTag::query()->create([
            'contact_id' => $untagged->id,
            'tag' => 'refinance',
        ]);

        $broadcast = Broadcast::factory()->create([
            'recipient_filter' => [
                'type' => 'tag',
                'tags' => ['homebuyer'],
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (...$arguments): array {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $broadcast = $arguments['context'] ?? $arguments[6];

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                    ]),
                ];
            });

        app(ScheduleBroadcastAction::class)->handle($broadcast);

        $this->assertDatabaseHas('broadcast_recipients', [
            'broadcast_id' => $broadcast->id,
            'contact_id' => $tagged->id,
            'status' => BroadcastRecipient::STATUS_SCHEDULED,
        ]);

        $this->assertDatabaseMissing('broadcast_recipients', [
            'broadcast_id' => $broadcast->id,
            'contact_id' => $untagged->id,
        ]);
    }

    public function test_it_marks_a_recipient_skipped_when_messaging_schedules_nothing(): void
    {
        $contact = Contact::factory()->create();

        $broadcast = Broadcast::factory()->create([
            'recipient_filter' => [
                'type' => 'all',
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturn([]);

        $scheduledBroadcast = app(ScheduleBroadcastAction::class)->handle($broadcast);

        $recipient = BroadcastRecipient::query()->where('broadcast_id', $broadcast->id)->first();

        $this->assertSame(Broadcast::STATUS_SCHEDULED, $scheduledBroadcast->status);
        $this->assertSame(1, $scheduledBroadcast->recipient_count);
        $this->assertSame(0, $scheduledBroadcast->scheduled_count);
        $this->assertSame($contact->id, $recipient->contact_id);
        $this->assertSame(BroadcastRecipient::STATUS_SKIPPED, $recipient->status);
        $this->assertNull($recipient->scheduled_message_ids);
        $this->assertSame('not_scheduled_by_messaging', $recipient->skip_reason);
    }

    public function test_it_applies_a_five_minute_buffer_to_send_now_broadcasts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 10:00:00'));

        Contact::factory()->create();

        $broadcast = Broadcast::factory()->create([
            'send_at' => null,
            'recipient_filter' => [
                'type' => 'all',
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (...$arguments): array {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $broadcast = $arguments['context'] ?? $arguments[6];
                $triggeredAt = $arguments['triggeredAt'] ?? $arguments[7];
                $meta = $arguments['meta'] ?? $arguments[9];

                $this->assertSame(
                    '2026-07-01 10:05:00',
                    Carbon::parse($triggeredAt)->toDateTimeString(),
                );

                $this->assertSame(
                    ScheduleBroadcastAction::SEND_BUFFER_MINUTES,
                    $meta['send_buffer_minutes'],
                );

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                        'send_at' => Carbon::parse($triggeredAt),
                    ]),
                ];
            });

        $scheduledBroadcast = app(ScheduleBroadcastAction::class)->handle($broadcast);

        $this->assertSame(
            '2026-07-01 10:05:00',
            $scheduledBroadcast->send_at->toDateTimeString(),
        );
    }

    public function test_it_keeps_a_future_scheduled_broadcast_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 10:00:00'));

        Contact::factory()->create();

        $futureSendAt = Carbon::parse('2026-07-01 11:00:00');

        $broadcast = Broadcast::factory()->create([
            'send_at' => $futureSendAt,
            'recipient_filter' => [
                'type' => 'all',
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (...$arguments): array {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $broadcast = $arguments['context'] ?? $arguments[6];
                $triggeredAt = $arguments['triggeredAt'] ?? $arguments[7];

                $this->assertSame(
                    '2026-07-01 11:00:00',
                    Carbon::parse($triggeredAt)->toDateTimeString(),
                );

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                        'send_at' => Carbon::parse($triggeredAt),
                    ]),
                ];
            });

        $scheduledBroadcast = app(ScheduleBroadcastAction::class)->handle($broadcast);

        $this->assertSame(
            '2026-07-01 11:00:00',
            $scheduledBroadcast->send_at->toDateTimeString(),
        );
    }

    public function test_it_excludes_contacts_already_scheduled_or_sent_previous_broadcasts(): void
    {
        $included = Contact::factory()->create();
        $previouslyScheduled = Contact::factory()->create();
        $previouslySent = Contact::factory()->create();
        $previouslyFailed = Contact::factory()->create();

        $previousBroadcast = Broadcast::factory()->create([
            'status' => Broadcast::STATUS_COMPLETED,
        ]);

        BroadcastRecipient::factory()->scheduled([1])->create([
            'broadcast_id' => $previousBroadcast->id,
            'contact_id' => $previouslyScheduled->id,
        ]);

        BroadcastRecipient::factory()->sent()->create([
            'broadcast_id' => $previousBroadcast->id,
            'contact_id' => $previouslySent->id,
        ]);

        BroadcastRecipient::factory()->failed()->create([
            'broadcast_id' => $previousBroadcast->id,
            'contact_id' => $previouslyFailed->id,
        ]);

        $broadcast = Broadcast::factory()->create([
            'recipient_filter' => [
                'type' => 'all',
                'exclude' => [
                    'broadcast_ids' => [$previousBroadcast->id],
                    'statuses' => [
                        BroadcastRecipient::STATUS_SCHEDULED,
                        BroadcastRecipient::STATUS_SENT,
                    ],
                ],
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->twice()
            ->andReturnUsing(function (...$arguments): array {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $broadcast = $arguments['context'] ?? $arguments[6];

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                    ]),
                ];
            });

        app(ScheduleBroadcastAction::class)->handle($broadcast);

        $this->assertDatabaseHas('broadcast_recipients', [
            'broadcast_id' => $broadcast->id,
            'contact_id' => $included->id,
            'status' => BroadcastRecipient::STATUS_SCHEDULED,
        ]);

        $this->assertDatabaseHas('broadcast_recipients', [
            'broadcast_id' => $broadcast->id,
            'contact_id' => $previouslyFailed->id,
            'status' => BroadcastRecipient::STATUS_SCHEDULED,
        ]);

        $this->assertDatabaseMissing('broadcast_recipients', [
            'broadcast_id' => $broadcast->id,
            'contact_id' => $previouslyScheduled->id,
        ]);

        $this->assertDatabaseMissing('broadcast_recipients', [
            'broadcast_id' => $broadcast->id,
            'contact_id' => $previouslySent->id,
        ]);
    }

    public function test_it_schedules_a_permission_invitation_broadcast_to_a_specific_import_batch(): void
    {
        $targetBatch = ContactImportBatch::factory()->create();
        $otherBatch = ContactImportBatch::factory()->create();

        $eligible = Contact::factory()->create([
            'email' => 'eligible@example.com',
            'source' => 'import',
            'contact_import_batch_id' => $targetBatch->id,
        ]);

        $alreadyConsented = Contact::factory()->create([
            'email' => 'consented@example.com',
            'source' => 'import',
            'contact_import_batch_id' => $targetBatch->id,
        ]);

        Contact::factory()->create([
            'email' => 'other-batch@example.com',
            'source' => 'import',
            'contact_import_batch_id' => $otherBatch->id,
        ]);

        MessageConsent::query()->create([
            'contact_id' => $alreadyConsented->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'consented_at' => now(),
            'source' => 'test',
        ]);

        $broadcast = Broadcast::factory()->create([
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'dispatch_key' => Broadcast::PERMISSION_INVITATION_DISPATCH_KEY,
            'message_type' => Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'payload_class' => EmailPayload::class,
            'recipient_filter' => [
                'type' => 'import_batch',
                'import_batch_ids' => [$targetBatch->id],
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (...$arguments) use ($eligible): array {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $broadcast = $arguments['context'] ?? $arguments[6];

                $this->assertSame($eligible->id, $recipient->id);

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                    ]),
                ];
            });

        $scheduledBroadcast = app(ScheduleBroadcastAction::class)->handle($broadcast);

        $this->assertSame(1, $scheduledBroadcast->recipient_count);
        $this->assertSame(1, $scheduledBroadcast->scheduled_count);

        $this->assertDatabaseHas('broadcast_recipients', [
            'broadcast_id' => $broadcast->id,
            'contact_id' => $eligible->id,
            'status' => BroadcastRecipient::STATUS_SCHEDULED,
        ]);

        $this->assertDatabaseMissing('broadcast_recipients', [
            'broadcast_id' => $broadcast->id,
            'contact_id' => $alreadyConsented->id,
        ]);
    }
}