<?php

namespace App\Modules\Messaging\Services\Email;

use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use InvalidArgumentException;

final class EmailPresentationResolver
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function apply(ScheduledMessage $message, array $payload): array
    {
        if ($message->channel !== 'email') {
            return $payload;
        }

        $payload['presentation'] = $this->modeFor($message);

        return $payload;
    }

    public function modeFor(ScheduledMessage $message): string
    {
        $surfaceModes = config('messaging.email.presentation.surfaces', []);

        if (! is_array($surfaceModes)) {
            throw new InvalidArgumentException(
                'Messaging email presentation surfaces must be configured as an array.',
            );
        }

        $surface = $surfaceModes !== []
            ? $this->surfaceFor($message)
            : null;

        $configured = $surface !== null && array_key_exists($surface, $surfaceModes)
            ? $surfaceModes[$surface]
            : config(
                'messaging.email.presentation.default',
                EmailPayload::PRESENTATION_CLIENT,
            );

        if (! is_string($configured)) {
            throw new InvalidArgumentException(
                'Messaging email presentation mode must be a string.',
            );
        }

        $mode = trim($configured);

        if (! in_array($mode, [
            EmailPayload::PRESENTATION_CLIENT,
            EmailPayload::PRESENTATION_STANDARD,
        ], true)) {
            throw new InvalidArgumentException(
                "Unsupported messaging email presentation mode [{$mode}].",
            );
        }

        return $mode;
    }

    private function surfaceFor(ScheduledMessage $message): ?string
    {
        $meta = is_array($message->meta)
            ? $message->meta
            : [];
        $surface = $meta['surface'] ?? null;

        if (is_string($surface) && trim($surface) !== '') {
            return trim($surface);
        }

        $enrollment = null;

        if ($message->relationLoaded('messageChainEnrollment')) {
            $candidate = $message->getRelation('messageChainEnrollment');
            $enrollment = $candidate instanceof MessageChainEnrollment
                ? $candidate
                : null;
        } elseif ($message->message_chain_enrollment_id !== null) {
            $candidate = $message->messageChainEnrollment()->first();

            if ($candidate instanceof MessageChainEnrollment) {
                $message->setRelation('messageChainEnrollment', $candidate);
                $enrollment = $candidate;
            }
        }

        if (! $enrollment instanceof MessageChainEnrollment) {
            return null;
        }

        return is_string($enrollment->surface) && trim($enrollment->surface) !== ''
            ? trim($enrollment->surface)
            : null;
    }
}