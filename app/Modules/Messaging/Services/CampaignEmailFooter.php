<?php

namespace App\Modules\Messaging\Services;

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
            || $message->message_type !== 'campaign_step'
            || filled($payload['footer'] ?? null)
        ) {
            return $payload;
        }

        $key = data_get($message->meta, 'campaign_key');
        $footers = config('messaging.campaign_email_footers', []);
        $footer = is_string($key) && is_array($footers)
            ? ($footers[$key] ?? null)
            : null;

        if (is_string($footer) && trim($footer) !== '') {
            $payload['footer'] = trim($footer);
        }

        return $payload;
    }
}