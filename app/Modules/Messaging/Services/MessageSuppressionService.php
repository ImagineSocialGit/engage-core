<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\MessageSuppression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MessageSuppressionService
{
    public function suppress(
        MessageChannel|string $channel,
        string $destination,
        string $reason,
        ?string $provider = null,
        ?string $sourceEventId = null,
        ?array $meta = null,
    ): MessageSuppression {
        $channel = $this->normalizeChannel($channel);
        $destination = $this->validateDestination($destination);
        $reason = $this->normalizeReason($reason);
        $provider = $this->normalizeProvider($provider);
        $sourceEventId = $this->normalizeNullableString($sourceEventId);

        return DB::transaction(function () use ($channel, $destination, $reason, $provider, $sourceEventId, $meta): MessageSuppression {
            if ($sourceEventId !== null) {
                $existingEventSuppression = MessageSuppression::query()
                    ->where('channel', $channel)
                    ->where('destination', $destination)
                    ->where('provider', $provider)
                    ->where('source_event_id', $sourceEventId)
                    ->lockForUpdate()
                    ->first();

                if ($existingEventSuppression) {
                    return $existingEventSuppression;
                }
            }

            $activeSuppression = MessageSuppression::query()
                ->active()
                ->forDestination($channel, $destination)
                ->lockForUpdate()
                ->first();

            if ($activeSuppression) {
                return $activeSuppression;
            }

            return MessageSuppression::query()->create([
                'channel' => $channel,
                'destination' => $destination,
                'reason' => $reason,
                'provider' => $provider,
                'source_event_id' => $sourceEventId,
                'suppressed_at' => now(),
                'released_at' => null,
                'meta' => $meta,
            ]);
        });
    }

    public function release(
        MessageChannel|string $channel,
        string $destination,
        ?string $provider = null,
        ?string $sourceEventId = null,
        ?array $meta = null,
    ): ?MessageSuppression {
        $channel = $this->normalizeChannel($channel);
        $destination = $this->validateDestination($destination);
        $provider = $this->normalizeProvider($provider);
        $sourceEventId = $this->normalizeNullableString($sourceEventId);

        return DB::transaction(function () use ($channel, $destination, $provider, $sourceEventId, $meta): ?MessageSuppression {
            $suppression = MessageSuppression::query()
                ->active()
                ->forDestination($channel, $destination)
                ->lockForUpdate()
                ->first();

            if (! $suppression) {
                return null;
            }

            $suppression->forceFill([
                'released_at' => now(),
                'meta' => array_filter([
                    ...($suppression->meta ?? []),
                    'release' => array_filter([
                        'provider' => $provider,
                        'source_event_id' => $sourceEventId,
                        'meta' => $meta,
                    ], static fn ($value) => $value !== null),
                ]),
            ])->save();

            return $suppression;
        });
    }

    public function isSuppressed(MessageChannel|string $channel, string $destination): bool
    {
        $channel = $this->normalizeChannel($channel);
        $destination = $this->validateDestination($destination);

        return MessageSuppression::query()
            ->active()
            ->forDestination($channel, $destination)
            ->exists();
    }

    private function validateDestination(string $destination): string
    {
        $destination = trim($destination);

        if ($destination === '') {
            throw new InvalidArgumentException('Message suppression destination is required.');
        }

        return $destination;
    }

    private function normalizeChannel(MessageChannel|string $channel): string
    {
        $channel = $channel instanceof MessageChannel
            ? $channel->value
            : strtolower(trim($channel));

        if (! in_array($channel, MessageChannel::values(), true)) {
            throw new InvalidArgumentException('Invalid message suppression channel.');
        }

        return $channel;
    }

    private function normalizeReason(string $reason): string
    {
        $reason = strtolower(trim($reason));

        if (! in_array($reason, [
            MessageSuppression::REASON_BOUNCE,
            MessageSuppression::REASON_COMPLAINT,
            MessageSuppression::REASON_MANUAL,
            MessageSuppression::REASON_PROVIDER,
            MessageSuppression::REASON_INVALID_DESTINATION,
            MessageSuppression::REASON_REPEATED_FAILURE,
        ], true)) {
            throw new InvalidArgumentException('Invalid message suppression reason.');
        }

        return $reason;
    }

    private function normalizeProvider(?string $provider): ?string
    {
        $provider = $this->normalizeNullableString($provider);

        if ($provider === null) {
            return null;
        }

        if (! in_array($provider, [
            MessageSuppression::PROVIDER_TWILIO,
            MessageSuppression::PROVIDER_TELNYX,
            MessageSuppression::PROVIDER_RESEND,
        ], true)) {
            throw new InvalidArgumentException('Invalid message suppression provider.');
        }

        return $provider;
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

