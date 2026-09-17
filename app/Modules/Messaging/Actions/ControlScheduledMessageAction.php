<?php

namespace App\Modules\Messaging\Actions;

use App\Models\User;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageOperationalEvent;
use App\Modules\Messaging\Services\ScheduledMessageEventOutbox;
use App\Support\Queues\QueueContract;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ControlScheduledMessageAction
{
    public function __construct(
        private readonly ScheduledMessageEventOutbox $eventOutbox,
        private readonly QueueContract $queueContract,
    ) {}

    public function hold(int|ScheduledMessage $message, User $actor, ?string $reason = null): ScheduledMessage
    {
        return $this->change($message, $actor, 'hold', null, $reason);
    }

    public function resume(int|ScheduledMessage $message, User $actor, ?string $reason = null): ScheduledMessage
    {
        return $this->change($message, $actor, 'resume', null, $reason);
    }

    public function cancel(int|ScheduledMessage $message, User $actor, ?string $reason = null): ScheduledMessage
    {
        return $this->change($message, $actor, 'cancel', null, $reason);
    }

    public function reschedule(
        int|ScheduledMessage $message,
        User $actor,
        CarbonInterface|string $sendAt,
        ?string $reason = null,
    ): ScheduledMessage {
        try {
            $sendAt = Carbon::parse($sendAt)->utc();
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('A valid send time is required.', previous: $exception);
        }

        return $this->change($message, $actor, 'reschedule', $sendAt, $reason);
    }

    private function change(
        int|ScheduledMessage $message,
        User $actor,
        string $action,
        ?Carbon $sendAt,
        ?string $reason,
    ): ScheduledMessage {
        if (! $actor->exists) {
            throw new InvalidArgumentException('A persisted operator is required.');
        }

        $reason = trim((string) $reason);

        if (mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('The operational reason must be at most 1000 characters.');
        }

        $id = $message instanceof ScheduledMessage ? (int) $message->getKey() : $message;

        return DB::transaction(function () use ($id, $actor, $action, $sendAt, $reason): ScheduledMessage {
            $locked = ScheduledMessage::query()->lockForUpdate()->findOrFail($id);
            $state = (string) $locked->operational_state;

            if ($locked->status !== ScheduledMessage::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'scheduled_message' => 'Only an unsent pending message can be controlled.',
                ]);
            }

            $allowed = match ($action) {
                'hold' => $state === ScheduledMessage::OPERATIONAL_ACTIVE,
                'resume' => $state === ScheduledMessage::OPERATIONAL_HELD,
                'cancel', 'reschedule' => in_array($state, [
                    ScheduledMessage::OPERATIONAL_ACTIVE,
                    ScheduledMessage::OPERATIONAL_HELD,
                ], true),
                default => false,
            };

            if (! $allowed) {
                throw ValidationException::withMessages([
                    'scheduled_message' => 'This message cannot make the requested state change.',
                ]);
            }

            $queue = in_array($action, ['resume', 'reschedule'], true)
                && ($action === 'resume' || $state === ScheduledMessage::OPERATIONAL_ACTIVE)
                    ? $this->queueContract->assertDispatchable($locked->queue)
                    : null;

            if ($action === 'reschedule' && $sendAt === null) {
                throw new InvalidArgumentException('A send time is required to reschedule.');
            }

            $previousSendAt = $locked->send_at?->copy();
            $previousState = $state;
            $occurredAt = now();

            $locked->forceFill(match ($action) {
                'hold' => ['operational_state' => ScheduledMessage::OPERATIONAL_HELD],
                'resume' => ['operational_state' => ScheduledMessage::OPERATIONAL_ACTIVE],
                'cancel' => [
                    'operational_state' => ScheduledMessage::OPERATIONAL_CANCELLED,
                    'status' => ScheduledMessage::STATUS_CANCELLED,
                ],
                'reschedule' => [
                    'send_at' => $sendAt,
                    'manual_schedule_override_at' => $occurredAt,
                ],
            })->save();

            ScheduledMessageOperationalEvent::query()->create([
                'scheduled_message_id' => $locked->getKey(),
                'actor_id' => $actor->getKey(),
                'actor_email' => $actor->email,
                'action' => $action,
                'reason' => $reason !== '' ? $reason : null,
                'previous_send_at' => $previousSendAt,
                'current_send_at' => $locked->send_at,
                'previous_operational_state' => $previousState,
                'current_operational_state' => $locked->operational_state,
                'occurred_at' => $occurredAt,
            ]);

            if ($action === 'cancel') {
                $this->eventOutbox->record(
                    scheduledMessage: $locked,
                    eventType: ScheduledMessage::STATUS_CANCELLED,
                    occurredAt: $occurredAt,
                    reasonCode: 'cancelled_by_operator',
                    reason: $reason !== '' ? $reason : 'Cancelled by operator.',
                );
            }

            if ($queue !== null) {
                SendScheduledMessageJob::dispatch(
                    scheduledMessageId: (int) $locked->getKey(),
                )->delay($locked->send_at)->afterCommit()->onQueue($queue);
            }

            return $locked;
        }, 3);
    }
}