<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\ScheduledMessage;

final class CampaignEmailFooter
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function apply(ScheduledMessage $message, array $payload): array
    {
        if ($message->channel !== 'email'
            || $message->purpose !== 'marketing'
            || filled($payload['footer'] ?? null)
        ) {
            return $payload;
        }

        $key = $this->campaignKey($message);
        $footers = config('messaging.campaign_email_footers', []);
        $footer = is_string($key) && is_array($footers)
            ? ($footers[$key] ?? null)
            : null;

        if (is_string($footer) && trim($footer) !== '') {
            $payload['footer'] = trim($footer);
        }

        return $payload;
    }

    private function campaignKey(ScheduledMessage $message): ?string
    {
        $legacyKey = data_get($message->meta, 'campaign_key');

        if ($message->message_type === 'campaign_step'
            && is_string($legacyKey)
            && trim($legacyKey) !== ''
        ) {
            return trim($legacyKey);
        }

        if ($message->message_chain_enrollment_id === null) {
            return null;
        }

        $chain = MessageChain::query()
            ->whereHas(
                'versions.enrollments',
                fn ($query) => $query
                    ->whereKey((int) $message->message_chain_enrollment_id)
                    ->whereIn('surface', [
                        'campaigns',
                        MessageChainEnrollment::TESTING_SURFACE_PREFIX.'campaigns',
                    ]),
            )
            ->first();

        if (! $chain instanceof MessageChain) {
            return null;
        }

        $chainKey = is_string($chain->key)
            ? trim($chain->key)
            : '';

        if (! str_starts_with($chainKey, 'campaign.')) {
            return null;
        }

        $campaignKey = trim(substr($chainKey, strlen('campaign.')));

        return $campaignKey !== ''
            ? $campaignKey
            : null;
    }
}