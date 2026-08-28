<?php

namespace App\Modules\Broadcasts\Actions;

use App\Modules\Broadcasts\Jobs\ScheduleBroadcastChunkJob;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Broadcasts\Services\BroadcastMessageTokenValidator;
use App\Modules\Broadcasts\Services\BroadcastRecipientResolver;
use App\Modules\Messaging\Services\BulkMessageDeliveryPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ScheduleBroadcastAction
{
    public const SEND_BUFFER_MINUTES = 5;

    public function __construct(
        private readonly BroadcastRecipientResolver $recipientResolver,
        private readonly ScheduleBroadcastRecipientChunkAction $scheduleRecipientChunk,
        private readonly BulkMessageDeliveryPolicy $bulkDeliveryPolicy,
        private readonly BroadcastMessageTokenValidator $messageTokenValidator,
    ) {}

    public function handle(Broadcast $broadcast): Broadcast
    {
        return DB::transaction(function () use ($broadcast): Broadcast {
            $broadcast = Broadcast::query()
                ->lockForUpdate()
                ->findOrFail($broadcast->getKey());

            $this->messageTokenValidator->assertBroadcastValid($broadcast);

            $sendAt = $this->resolveSendAt($broadcast);
            $eligibleRecipientCount = $this->recipientResolver->count($broadcast);
            $evaluatedAt = now();

            if ($eligibleRecipientCount === 0) {
                $broadcast->forceFill([
                    'status' => Broadcast::STATUS_COMPLETED,
                    'send_at' => $sendAt,
                    'recipient_count' => 0,
                    'scheduled_count' => 0,
                    'completed_at' => $evaluatedAt,
                    'meta' => array_replace_recursive($broadcast->meta ?? [], [
                        'scheduling' => [
                            'state' => 'complete',
                            'evaluated_at' => $evaluatedAt->toISOString(),
                            'outcome' => 'no_eligible_recipients',
                            'eligible_recipient_count' => 0,
                            'scheduled_recipient_count' => 0,
                            'skipped_recipient_count' => 0,
                        ],
                    ]),
                ])->save();

                return $broadcast->refresh();
            }

            $this->recipientResolver->snapshot($broadcast);

            $snapshottedRecipientCount = BroadcastRecipient::query()
                ->where('broadcast_id', $broadcast->getKey())
                ->count();
            $bulk = $this->bulkDeliveryPolicy->shouldChunk(
                $snapshottedRecipientCount,
            );
            $bulkSettings = $bulk
                ? $this->bulkDeliveryPolicy->snapshot()
                : null;

            $schedulingMeta = [
                'state' => $bulk ? 'queued' : 'processing',
                'queued_at' => $evaluatedAt->toISOString(),
                'eligible_recipient_count' => $snapshottedRecipientCount,
                'scheduled_recipient_count' => 0,
                'skipped_recipient_count' => 0,
            ];

            if ($bulkSettings !== null) {
                $schedulingMeta['bulk'] = [
                    'enabled' => true,
                    'queue' => $bulkSettings['queue'],
                    'chunk_size' => $bulkSettings['chunk_size'],
                    'release_interval_seconds' => $bulkSettings['release_interval_seconds'],
                    'first_release_at' => $sendAt->toISOString(),
                ];
            }

            $broadcast->forceFill([
                'status' => Broadcast::STATUS_SCHEDULED,
                'send_at' => $sendAt,
                'recipient_count' => $snapshottedRecipientCount,
                'scheduled_count' => 0,
                'completed_at' => null,
                'meta' => array_replace_recursive($broadcast->meta ?? [], [
                    'scheduling' => $schedulingMeta,
                ]),
            ])->save();

            if ($bulk) {
                ScheduleBroadcastChunkJob::dispatch(
                    (int) $broadcast->getKey(),
                )
                    ->delay($sendAt)
                    ->afterCommit()
                    ->onQueue($bulkSettings['queue']);

                return $broadcast->refresh();
            }

            $this->scheduleRecipientChunk->handle(
                broadcastId: (int) $broadcast->getKey(),
                bulk: false,
            );

            return $broadcast->refresh();
        }, 3);
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
}