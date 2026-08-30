<?php

namespace App\Modules\Broadcasts\Actions;

use App\Models\User;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Services\BroadcastMessageTemplateVersionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DuplicateBroadcastAction
{
    public function __construct(
        private readonly BroadcastMessageTemplateVersionService $messageTemplates,
    ) {}

    public function handle(Broadcast $broadcast, ?User $actor = null): Broadcast
    {
        if (! $broadcast->isRegularBroadcast()) {
            throw new InvalidArgumentException('Only regular Broadcasts can be duplicated.');
        }

        return DB::transaction(function () use ($broadcast, $actor): Broadcast {
            $copy = Broadcast::query()->create([
                'user_id' => $actor?->getKey(),
                'message_template_id' => null,
                'message_template_version_id' => null,
                'name' => Str::limit('Copy of '.$broadcast->name, 255, ''),
                'channel' => $broadcast->channel,
                'purpose' => $broadcast->purpose,
                'scope' => $broadcast->scope,
                'dispatch_key' => $broadcast->dispatch_key,
                'message_type' => $broadcast->message_type,
                'payload_class' => $broadcast->payload_class,
                'queue' => $broadcast->queue,
                'status' => Broadcast::STATUS_DRAFT,
                'send_at' => null,
                'recipient_filter' => [
                    'type' => 'criteria',
                    'criteria' => [],
                ],
                'recipient_count' => 0,
                'scheduled_count' => 0,
                'cancelled_at' => null,
                'completed_at' => null,
                'meta' => [
                    'created_from' => 'crm',
                    'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
                ],
            ]);

            $this->messageTemplates->saveDraft(
                broadcast: $copy,
                payload: $broadcast->messagePayload(),
                createdBy: $actor,
            );

            return $copy->refresh();
        }, 3);
    }
}