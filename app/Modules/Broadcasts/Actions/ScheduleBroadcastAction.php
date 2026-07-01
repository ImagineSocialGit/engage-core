<?php

namespace App\Modules\Broadcasts\Actions;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Broadcasts\Services\BroadcastRecipientResolver;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ScheduleBroadcastAction
{
    public const SEND_BUFFER_MINUTES = 5;

    public function __construct(
        private readonly BroadcastRecipientResolver $recipientResolver,
        private readonly DispatchMessageAction $dispatchMessageAction,
    ) {}

    public function handle(Broadcast $broadcast): Broadcast
    {
        return DB::transaction(function () use ($broadcast): Broadcast {
            $sendAt = $this->resolveSendAt($broadcast);
            $contacts = $this->recipientResolver->resolve($broadcast);
            $scheduledRecipientCount = 0;
            $consentPolicy = $this->consentPolicy($broadcast);

            foreach ($contacts as $contact) {
                $recipient = BroadcastRecipient::query()->firstOrCreate(
                    [
                        'broadcast_id' => $broadcast->getKey(),
                        'contact_id' => $contact->getKey(),
                    ],
                    [
                        'status' => BroadcastRecipient::STATUS_PENDING,
                        'scheduled_message_ids' => null,
                        'skip_reason' => null,
                        'meta' => [],
                    ],
                );

                $scheduledMessages = $this->dispatchMessageAction->handle(
                    recipient: $contact,
                    channel: $broadcast->channel,
                    purpose: $broadcast->purpose,
                    scope: $broadcast->scope,
                    dispatchKeys: $broadcast->dispatch_key,
                    payload: $broadcast->payload ?? [],
                    context: $broadcast,
                    triggeredAt: $sendAt,
                    meta: [
                        'queue' => $broadcast->queue,
                        'dispatch_keys' => [$broadcast->dispatch_key],
                        'broadcast_id' => $broadcast->getKey(),
                        'broadcast_recipient_id' => $recipient->getKey(),
                        'send_buffer_minutes' => self::SEND_BUFFER_MINUTES,
                        'consent_policy' => $consentPolicy,
                    ],
                    definitions: [
                        [
                            'dispatch_key' => $broadcast->dispatch_key,
                            'message_type' => $broadcast->message_type,
                            'channel' => $broadcast->channel,
                            'purpose' => $broadcast->purpose,
                            'scope' => $broadcast->scope,
                            'timing' => 'scheduled',
                            'payload_class' => $broadcast->payload_class,
                            'queue' => $broadcast->queue,
                            'conditions' => [],
                            'schedule' => [
                                'type' => 'delay',
                                'minutes' => 0,
                            ],
                            'payload' => $broadcast->payload ?? [],
                            'consent_policy' => $consentPolicy,
                            'meta' => [
                                'source' => 'broadcast',
                                'broadcast_id' => $broadcast->getKey(),
                                'consent_policy' => $consentPolicy,
                            ],
                        ],
                    ],
                );

                $scheduledMessageIds = $this->scheduledMessageIds($scheduledMessages);

                if ($scheduledMessageIds === []) {
                    $recipient->forceFill([
                        'status' => BroadcastRecipient::STATUS_SKIPPED,
                        'scheduled_message_ids' => null,
                        'skip_reason' => 'not_scheduled_by_messaging',
                        'meta' => array_replace_recursive($recipient->meta ?? [], [
                            'broadcast' => [
                                'attempted_at' => now()->toISOString(),
                            ],
                        ]),
                    ])->save();

                    continue;
                }

                $recipient->forceFill([
                    'status' => BroadcastRecipient::STATUS_SCHEDULED,
                    'scheduled_message_ids' => $scheduledMessageIds,
                    'skip_reason' => null,
                    'meta' => array_replace_recursive($recipient->meta ?? [], [
                        'broadcast' => [
                            'scheduled_at' => now()->toISOString(),
                        ],
                    ]),
                ])->save();

                $scheduledRecipientCount++;
            }

            $broadcast->forceFill([
                'status' => Broadcast::STATUS_SCHEDULED,
                'send_at' => $sendAt,
                'recipient_count' => $contacts->count(),
                'scheduled_count' => $scheduledRecipientCount,
            ])->save();

            return $broadcast->refresh();
        });
    }

    private function resolveSendAt(Broadcast $broadcast): Carbon
    {
        $minimumSendAt = now()->addMinutes(self::SEND_BUFFER_MINUTES);

        if (! $broadcast->send_at) {
            return $minimumSendAt;
        }

        $sendAt = Carbon::parse($broadcast->send_at);

        return $sendAt->gt($minimumSendAt) ? $sendAt : $minimumSendAt;
    }

    /**
     * @return array<string, mixed>
     */
    private function consentPolicy(Broadcast $broadcast): array
    {
        if ($broadcast->message_type !== Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION) {
            return [];
        }

        if ($broadcast->channel !== ContactPermissionInvitation::CHANNEL_EMAIL) {
            return [];
        }

        return [
            'permission_invitation' => [
                'source' => ContactPermissionInvitation::SOURCE_IMPORTED_CONTACT,
                'one_time' => true,
            ],
        ];
    }

    /**
     * @param array<int, ScheduledMessage> $scheduledMessages
     * @return array<int, int>
     */
    private function scheduledMessageIds(array $scheduledMessages): array
    {
        return array_values(array_filter(array_map(
            fn (ScheduledMessage $scheduledMessage): ?int => $scheduledMessage->getKey()
                ? (int) $scheduledMessage->getKey()
                : null,
            $scheduledMessages,
        )));
    }
}