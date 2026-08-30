<?php

namespace App\Modules\Broadcasts\Actions;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Broadcasts\Services\BroadcastMessageTemplateVersionService;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Models\ContactPermissionInvitation;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\BulkMessageDeliveryPolicy;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ScheduleBroadcastRecipientChunkAction
{
    public function __construct(
        private readonly DispatchMessageAction $dispatchMessageAction,
        private readonly MessageChannelAvailability $messageChannelAvailability,
        private readonly BulkMessageDeliveryPolicy $bulkDeliveryPolicy,
        private readonly BroadcastMessageTemplateVersionService $messageTemplateVersions,
    ) {}

    /**
     * @return array{
     *     processed_count: int,
     *     scheduled_count: int,
     *     skipped_count: int,
     *     has_more: bool,
     *     release_at: Carbon|null,
     * }
     */
    public function handle(
        int $broadcastId,
        bool $bulk = true,
    ): array {
        return DB::transaction(function () use ($broadcastId, $bulk): array {
            $broadcast = Broadcast::query()
                ->lockForUpdate()
                ->findOrFail($broadcastId);

            if (! in_array($broadcast->status, [
                Broadcast::STATUS_SCHEDULED,
                Broadcast::STATUS_SENDING,
            ], true)) {
                return $this->emptyResult();
            }

            $messageTemplateVersion = $this->messageTemplateVersions->resolvePinned($broadcast);
            $messagePayload = $messageTemplateVersion->payload();
            $bulkSettings = $this->bulkSettings($broadcast);
            $chunkSize = $bulk
                ? $bulkSettings['chunk_size']
                : max(1, (int) $broadcast->recipient_count);
            $chunkIndex = $this->nextChunkIndex($broadcast, $bulk);

            $releaseAt = $bulk
                ? $this->bulkDeliveryPolicy->releaseAt(
                    baseSendAt: $broadcast->send_at ?? now(),
                    chunkIndex: $chunkIndex,
                    releaseIntervalSeconds: $bulkSettings['release_interval_seconds'],
                )
                : Carbon::parse($broadcast->send_at ?? now());

            $deliveryQueue = $bulk
                ? $bulkSettings['queue']
                : $this->originalQueue($broadcast);

            $recipients = BroadcastRecipient::query()
                ->where('broadcast_id', $broadcast->getKey())
                ->where('status', BroadcastRecipient::STATUS_PENDING)
                ->with('contact')
                ->orderBy('id')
                ->limit($chunkSize)
                ->lockForUpdate()
                ->get();

            if ($recipients->isEmpty()) {
                $this->finalizeScheduling($broadcast);

                return $this->emptyResult();
            }

            $scheduledCount = 0;
            $skippedCount = 0;
            $consentPolicy = $this->consentPolicy($broadcast);

            foreach ($recipients as $recipient) {
                $contact = $recipient->contact;

                if (! $contact) {
                    $this->markSkipped(
                        recipient: $recipient,
                        reason: 'broadcast_recipient_contact_missing',
                        meta: [
                            'broadcast' => [
                                'attempted_at' => now()->toISOString(),
                            ],
                        ],
                    );
                    $skippedCount++;

                    continue;
                }

                if (! $this->messageChannelAvailability->isVisibleForSurface(
                    channel: $broadcast->channel,
                    surface: 'broadcasts',
                    purpose: $broadcast->purpose,
                    scope: $broadcast->scope,
                )) {
                    $this->markSkipped(
                        recipient: $recipient,
                        reason: 'broadcast_channel_unavailable',
                        meta: [
                            'broadcast' => [
                                'attempted_at' => now()->toISOString(),
                                'channel' => $broadcast->channel,
                                'purpose' => $broadcast->purpose,
                                'scope' => $broadcast->scope,
                                'surface' => 'broadcasts',
                            ],
                        ],
                    );
                    $skippedCount++;

                    continue;
                }

                $messageMeta = [
                    'queue' => $deliveryQueue,
                    'dispatch_keys' => [$broadcast->dispatch_key],
                    'send_buffer_minutes' => ScheduleBroadcastAction::SEND_BUFFER_MINUTES,
                    'consent_policy' => $consentPolicy,
                ];
                $definitionMeta = [
                    'source' => 'broadcast',
                    'consent_policy' => $consentPolicy,
                ];

                $scheduledMessages = $this->dispatchMessageAction->handle(
                    recipient: $contact,
                    channel: $broadcast->channel,
                    purpose: $broadcast->purpose,
                    scope: $broadcast->scope,
                    dispatchKeys: $broadcast->dispatch_key,
                    payload: $messagePayload,
                    context: $broadcast,
                    triggeredAt: now(),
                    sendAt: $releaseAt,
                    behaviorOwner: $broadcast,
                    occurrenceKey: 'broadcast:'.$broadcast->getKey(),
                    meta: $messageMeta,
                    definitions: [
                        [
                            'dispatch_key' => $broadcast->dispatch_key,
                            'message_type' => $broadcast->message_type,
                            'channel' => $broadcast->channel,
                            'purpose' => $broadcast->purpose,
                            'scope' => $broadcast->scope,
                            'payload_class' => $broadcast->payload_class,
                            'queue' => $deliveryQueue,
                            'message_template_version_id' => $messageTemplateVersion->getKey(),
                            'payload' => $messagePayload,
                            'consent_policy' => $consentPolicy,
                            'meta' => $definitionMeta,
                        ],
                    ],
                );

                $scheduledMessage = $this->singleScheduledMessage($scheduledMessages);

                if (! $scheduledMessage instanceof ScheduledMessage) {
                    $this->markSkipped(
                        recipient: $recipient,
                        reason: 'not_scheduled_by_messaging',
                        meta: [
                            'broadcast' => [
                                'attempted_at' => now()->toISOString(),
                            ],
                        ],
                    );
                    $skippedCount++;

                    continue;
                }

                $recipient->forceFill([
                    'status' => BroadcastRecipient::STATUS_SCHEDULED,
                    'scheduled_message_id' => $scheduledMessage->getKey(),
                    'sent_at' => null,
                    'terminal_reason' => null,
                    'meta' => array_replace_recursive($recipient->meta ?? [], [
                        'broadcast' => [
                            'scheduled_at' => now()->toISOString(),
                        ],
                    ]),
                ])->save();

                $scheduledCount++;
            }

            $newScheduledCount = (int) $broadcast->scheduled_count + $scheduledCount;
            $newSkippedCount = (int) data_get(
                $broadcast->meta,
                'scheduling.skipped_recipient_count',
                0,
            ) + $skippedCount;

            $broadcast->forceFill([
                'scheduled_count' => $newScheduledCount,
                'meta' => array_replace_recursive($broadcast->meta ?? [], [
                    'scheduling' => [
                        'state' => 'processing',
                        'last_chunk_processed_at' => now()->toISOString(),
                        'last_chunk_index' => $chunkIndex,
                        'last_chunk_release_at' => $releaseAt->toISOString(),
                        'scheduled_recipient_count' => $newScheduledCount,
                        'skipped_recipient_count' => $newSkippedCount,
                    ],
                ]),
            ])->save();

            $hasMore = BroadcastRecipient::query()
                ->where('broadcast_id', $broadcast->getKey())
                ->where('status', BroadcastRecipient::STATUS_PENDING)
                ->exists();

            if (! $hasMore) {
                $this->finalizeScheduling($broadcast->refresh());
            }

            return [
                'processed_count' => $recipients->count(),
                'scheduled_count' => $scheduledCount,
                'skipped_count' => $skippedCount,
                'has_more' => $hasMore,
                'release_at' => $releaseAt,
            ];
        }, 3);
    }

    /**
     * @return array{
     *     processed_count: int,
     *     scheduled_count: int,
     *     skipped_count: int,
     *     has_more: bool,
     *     release_at: null,
     * }
     */
    private function emptyResult(): array
    {
        return [
            'processed_count' => 0,
            'scheduled_count' => 0,
            'skipped_count' => 0,
            'has_more' => false,
            'release_at' => null,
        ];
    }

    private function finalizeScheduling(Broadcast $broadcast): void
    {
        if (! in_array($broadcast->status, [
            Broadcast::STATUS_SCHEDULED,
            Broadcast::STATUS_SENDING,
        ], true)) {
            return;
        }

        $scheduledCount = (int) $broadcast->scheduled_count;
        $skippedCount = (int) data_get(
            $broadcast->meta,
            'scheduling.skipped_recipient_count',
            0,
        );
        $eligibleCount = (int) $broadcast->recipient_count;
        $completedWithoutScheduledMessages = $scheduledCount === 0;

        $outcome = match (true) {
            $eligibleCount === 0 => 'no_eligible_recipients',
            $completedWithoutScheduledMessages => 'no_messages_scheduled',
            default => 'messages_scheduled',
        };

        $broadcast->forceFill([
            'status' => $completedWithoutScheduledMessages
                ? Broadcast::STATUS_COMPLETED
                : Broadcast::STATUS_SCHEDULED,
            'completed_at' => $completedWithoutScheduledMessages
                ? now()
                : null,
            'meta' => array_replace_recursive($broadcast->meta ?? [], [
                'scheduling' => [
                    'state' => 'complete',
                    'evaluated_at' => now()->toISOString(),
                    'outcome' => $outcome,
                    'eligible_recipient_count' => $eligibleCount,
                    'scheduled_recipient_count' => $scheduledCount,
                    'skipped_recipient_count' => $skippedCount,
                ],
            ]),
        ])->save();
    }

    private function nextChunkIndex(Broadcast $broadcast, bool $bulk): int
    {
        if (! $bulk) {
            return 0;
        }

        $lastChunkIndex = data_get($broadcast->meta, 'scheduling.last_chunk_index');

        return is_numeric($lastChunkIndex)
            ? max(0, (int) $lastChunkIndex + 1)
            : 0;
    }

    /**
     * @return array{queue: string, chunk_size: int, release_interval_seconds: int}
     */
    private function bulkSettings(Broadcast $broadcast): array
    {
        $queue = data_get($broadcast->meta, 'scheduling.bulk.queue');
        $chunkSize = data_get($broadcast->meta, 'scheduling.bulk.chunk_size');
        $releaseInterval = data_get(
            $broadcast->meta,
            'scheduling.bulk.release_interval_seconds',
        );

        return [
            'queue' => is_string($queue) && trim($queue) !== ''
                ? trim($queue)
                : $this->bulkDeliveryPolicy->queue(),
            'chunk_size' => is_numeric($chunkSize)
                ? min(1000, max(1, (int) $chunkSize))
                : $this->bulkDeliveryPolicy->chunkSize(),
            'release_interval_seconds' => is_numeric($releaseInterval)
                ? min(3600, max(1, (int) $releaseInterval))
                : $this->bulkDeliveryPolicy->releaseIntervalSeconds(),
        ];
    }

    private function originalQueue(Broadcast $broadcast): ?string
    {
        return is_string($broadcast->queue) && trim($broadcast->queue) !== ''
            ? trim($broadcast->queue)
            : null;
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
     */
    private function singleScheduledMessage(array $scheduledMessages): ?ScheduledMessage
    {
        $scheduledMessages = array_values(array_filter(
            $scheduledMessages,
            fn (mixed $message): bool => $message instanceof ScheduledMessage,
        ));

        if ($scheduledMessages === []) {
            return null;
        }

        if (count($scheduledMessages) !== 1) {
            throw new \RuntimeException(
                'Broadcast single-channel scheduling returned more than one ScheduledMessage.',
            );
        }

        $scheduledMessage = $scheduledMessages[0];

        if (! $scheduledMessage->getKey()) {
            throw new \RuntimeException(
                'Broadcast scheduling returned an unpersisted ScheduledMessage.',
            );
        }

        return $scheduledMessage;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function markSkipped(
        BroadcastRecipient $recipient,
        string $reason,
        array $meta,
    ): void {
        $recipient->forceFill([
            'status' => BroadcastRecipient::STATUS_SKIPPED,
            'scheduled_message_id' => null,
            'sent_at' => null,
            'terminal_reason' => $reason,
            'meta' => array_replace_recursive($recipient->meta ?? [], $meta),
        ])->save();
    }
}