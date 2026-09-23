<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use App\Modules\Messaging\Models\ScheduledMessageEmailOpenSignal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class RecordScheduledMessageEmailOpenSignalAction
{
    public function handle(
        string $provider,
        string $providerMessageId,
        CarbonInterface $occurredAt,
    ): bool {
        $provider = strtolower(trim($provider));
        $providerMessageId = trim($providerMessageId);

        if ($provider === '' || $providerMessageId === '') {
            return false;
        }

        $occurredAt = CarbonImmutable::instance($occurredAt)->utc();

        return DB::transaction(function () use (
            $provider,
            $providerMessageId,
            $occurredAt,
        ): bool {
            $attempts = ScheduledMessageDeliveryAttempt::query()
                ->with('scheduledMessage')
                ->where('provider', $provider)
                ->where('provider_message_id', $providerMessageId)
                ->lockForUpdate()
                ->limit(2)
                ->get();

            if ($attempts->count() !== 1) {
                return false;
            }

            $attempt = $attempts->first();
            $message = $attempt?->scheduledMessage;

            if (! $attempt instanceof ScheduledMessageDeliveryAttempt
                || ! $message instanceof ScheduledMessage
                || $message->channel !== 'email'
                || $message->isTestingRuntime()
            ) {
                return false;
            }

            $signal = ScheduledMessageEmailOpenSignal::query()
                ->where('delivery_attempt_id', $attempt->getKey())
                ->lockForUpdate()
                ->first();

            if (! $signal instanceof ScheduledMessageEmailOpenSignal) {
                ScheduledMessageEmailOpenSignal::query()->create([
                    'scheduled_message_id' => $message->getKey(),
                    'delivery_attempt_id' => $attempt->getKey(),
                    'provider' => $provider,
                    'provider_message_id' => $providerMessageId,
                    'occurrence_count' => 1,
                    'first_occurred_at' => $occurredAt,
                    'last_occurred_at' => $occurredAt,
                ]);

                return true;
            }

            $firstOccurredAt = CarbonImmutable::instance(
                $signal->first_occurred_at,
            )->utc();
            $lastOccurredAt = CarbonImmutable::instance(
                $signal->last_occurred_at,
            )->utc();

            $signal->forceFill([
                'occurrence_count' => max(1, (int) $signal->occurrence_count) + 1,
                'first_occurred_at' => $occurredAt->lessThan($firstOccurredAt)
                    ? $occurredAt
                    : $firstOccurredAt,
                'last_occurred_at' => $occurredAt->greaterThan($lastOccurredAt)
                    ? $occurredAt
                    : $lastOccurredAt,
            ])->save();

            return true;
        });
    }
}