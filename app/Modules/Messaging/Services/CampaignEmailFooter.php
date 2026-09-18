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
            || (is_array($payload['campaign_contact'] ?? null)
                && $payload['campaign_contact'] !== [])
        ) {
            return $payload;
        }

        $key = $this->campaignKey($message);

        if ($key === null) {
            return $payload;
        }

        $configured = data_get(
            config('messaging.campaign_email_contact_blocks', []),
            $key,
        );

        if ($configured === null) {
            $configured = data_get(
                config('messaging.campaign_email_footers', []),
                $key,
            );
        }

        $contact = $this->normalizeContactBlock($configured);

        if ($contact !== []) {
            $payload['campaign_contact'] = $contact;
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

    /**
     * @return array<string, string>
     */
    private function normalizeContactBlock(mixed $configured): array
    {
        if (is_string($configured)) {
            $configured = $this->legacyContactBlock($configured);
        }

        if (! is_array($configured)) {
            return [];
        }

        $phone = $this->nullableString($configured['phone'] ?? null);
        $email = $this->nullableString($configured['email'] ?? null);
        $scheduleUrl = $this->httpUrl($configured['schedule_url'] ?? null);
        $scheduleLabel = $this->nullableString($configured['schedule_label'] ?? null)
            ?? 'Schedule a consultation';

        $contact = array_filter([
            'phone' => $phone,
            'phone_url' => $phone !== null ? $this->phoneUrl($phone) : null,
            'email' => $email,
            'email_url' => $email !== null ? 'mailto:'.$email : null,
            'schedule_url' => $scheduleUrl,
            'schedule_label' => $scheduleUrl !== null ? $scheduleLabel : null,
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        return $contact !== []
            ? $contact
            : [];
    }

    /**
     * @return array<string, string>
     */
    private function legacyContactBlock(string $configured): array
    {
        $contact = [];

        foreach (preg_split('/\r\n|\n|\r/', trim($configured)) ?: [] as $line) {
            if (! is_string($line) || trim($line) === '') {
                continue;
            }

            if (preg_match('/^\s*Phone\s*[-:]\s*(.+)$/i', $line, $matches) === 1) {
                $contact['phone'] = trim($matches[1]);

                continue;
            }

            if (preg_match('/^\s*Email\s*[-:]\s*(.+)$/i', $line, $matches) === 1) {
                $contact['email'] = trim($matches[1]);

                continue;
            }

            if (preg_match('/^\s*Schedule\s*[-:]\s*(.+)$/i', $line, $matches) === 1) {
                $contact['schedule_url'] = trim($matches[1]);
            }
        }

        return $contact;
    }

    private function phoneUrl(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            $digits = '1'.$digits;
        }

        return 'tel:+'.$digits;
    }

    private function httpUrl(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));

        return in_array($scheme, ['http', 'https'], true) && $host !== ''
            ? $value
            : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}