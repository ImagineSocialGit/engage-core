<?php

namespace App\Modules\Messaging\Support;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;

class EmailReplyAddressGenerator
{
    private const LOCAL_PREFIX = 'reply+';
    private const SIGNATURE_LENGTH = 24;

    public function forScheduledMessage(ScheduledMessage $scheduledMessage): ?string
    {
        $domain = $this->domain();

        if ($domain === null
            || $scheduledMessage->channel !== 'email'
            || $scheduledMessage->recipient_type !== Contact::class
        ) {
            return null;
        }

        $encodedId = strtolower(base_convert((string) $scheduledMessage->getKey(), 10, 36));
        $signature = $this->signature((int) $scheduledMessage->getKey());

        return self::LOCAL_PREFIX.$encodedId.'.'.$signature.'@'.$domain;
    }

    public function forScheduledMessageId(int $scheduledMessageId): ?string
    {
        if ($scheduledMessageId < 1) {
            return null;
        }

        $scheduledMessage = ScheduledMessage::query()->find($scheduledMessageId);

        return $scheduledMessage instanceof ScheduledMessage
            ? $this->forScheduledMessage($scheduledMessage)
            : null;
    }

    public function resolve(string $address): ?ScheduledMessage
    {
        $domain = $this->domain();
        $address = strtolower(trim($address));

        if ($domain === null || $address === '' || ! str_ends_with($address, '@'.$domain)) {
            return null;
        }

        $local = substr($address, 0, -1 - strlen($domain));

        if (! preg_match('/^reply\+([0-9a-z]+)\.([0-9a-f]{24})$/', $local, $matches)) {
            return null;
        }

        $decoded = base_convert($matches[1], 36, 10);

        if (! ctype_digit($decoded)) {
            return null;
        }

        $id = (int) $decoded;

        if ($id < 1 || ! hash_equals($this->signature($id), $matches[2])) {
            return null;
        }

        return ScheduledMessage::query()
            ->whereKey($id)
            ->where('channel', 'email')
            ->where('status', ScheduledMessage::STATUS_SENT)
            ->first();
    }

    private function signature(int $scheduledMessageId): string
    {
        return substr(hash_hmac(
            'sha256',
            implode('|', [
                (string) config('client.key', ''),
                (string) $scheduledMessageId,
            ]),
            (string) config('app.key', ''),
        ), 0, self::SIGNATURE_LENGTH);
    }

    private function domain(): ?string
    {
        $domain = config('messaging.email.inbound_domain');

        if (! is_string($domain)) {
            return null;
        }

        $domain = strtolower(trim($domain));

        if ($domain === '' || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        return $domain;
    }
}