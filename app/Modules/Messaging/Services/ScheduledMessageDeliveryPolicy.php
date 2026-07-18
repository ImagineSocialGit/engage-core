<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\ScheduledMessage;
use Carbon\CarbonInterface;

class ScheduledMessageDeliveryPolicy
{
    public function leaseSeconds(): int
    {
        return max(60, (int) config('messaging.delivery.claim_lease_seconds', 900));
    }

    public function leaseExpiresAt(CarbonInterface $from): CarbonInterface
    {
        return $from->copy()->addSeconds($this->leaseSeconds());
    }

    public function recoveryBatchSize(): int
    {
        return min(1000, max(1, (int) config(
            'messaging.delivery.stale_recovery_batch_size',
            100,
        )));
    }

    public function canSafelyRetryProviderSubmission(
        ScheduledMessage $message,
    ): bool {
        if ($message->provider_submission_started_at === null) {
            return true;
        }

        $providerConfig = $this->providerIdempotencyConfig($message);

        if (! is_array($providerConfig)
            || ($providerConfig['enabled'] ?? false) !== true
        ) {
            return false;
        }

        $windowSeconds = max(0, (int) (
            $providerConfig['safe_retry_window_seconds'] ?? 0
        ));

        return $windowSeconds > 0
            && $message->provider_submission_started_at
                ->copy()
                ->addSeconds($windowSeconds)
                ->isFuture();
    }

    private function providerIdempotencyConfig(
        ScheduledMessage $message,
    ): mixed {
        $provider = match ($message->channel) {
            MessageChannel::Email->value => config('messaging.email.provider'),
            MessageChannel::Sms->value => config('sms.provider'),
            default => null,
        };

        if (! is_string($provider) || trim($provider) === '') {
            return false;
        }

        return config(sprintf(
            'messaging.delivery.provider_idempotency.%s.%s',
            $message->channel,
            trim($provider),
        ));
    }
}