<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageComponent;
use RuntimeException;

class ScheduledMessageComponentComposer
{
    /**
     * @param array<string, mixed> $primaryPayload
     * @return array<string, mixed>
     */
    public function compose(
        ScheduledMessage $scheduledMessage,
        array $primaryPayload,
    ): array {
        $components = $scheduledMessage->relationLoaded('components')
            ? $scheduledMessage->getRelation('components')
            : $scheduledMessage->components()->with('messageTemplateVersion')->get();

        foreach ($components as $component) {
            if (! $component instanceof ScheduledMessageComponent) {
                continue;
            }

            $version = $component->relationLoaded('messageTemplateVersion')
                ? $component->getRelation('messageTemplateVersion')
                : $component->messageTemplateVersion()->first();

            if (! $version instanceof MessageTemplateVersion) {
                throw new RuntimeException(
                    "ScheduledMessageComponent [{$component->getKey()}] has no immutable template version.",
                );
            }

            $primaryPayload = $this->place(
                payload: $primaryPayload,
                componentPayload: $version->payload(),
                placementKey: (string) $component->placement_key,
            );
        }

        return $primaryPayload;
    }

    /**
     * Preserve component-owned missing-field behavior when a component adds
     * copy to the provider-ready message. A primary-message policy wins if the
     * same dynamic field is governed in both places.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $componentPayload
     * @return array<string, mixed>
     */
    private function mergeTokenFallbacks(
        array $payload,
        array $componentPayload,
    ): array {
        $primary = is_array($payload['token_fallbacks'] ?? null)
            && array_is_list($payload['token_fallbacks'])
                ? $payload['token_fallbacks']
                : [];
        $component = is_array($componentPayload['token_fallbacks'] ?? null)
            && array_is_list($componentPayload['token_fallbacks'])
                ? $componentPayload['token_fallbacks']
                : [];

        if ($component === []) {
            return $payload;
        }

        $seen = [];

        foreach ($primary as $policy) {
            if (is_array($policy)
                && is_string($policy['token'] ?? null)
                && trim($policy['token']) !== ''
            ) {
                $seen[trim($policy['token'])] = true;
            }
        }

        foreach ($component as $policy) {
            if (! is_array($policy)
                || ! is_string($policy['token'] ?? null)
                || trim($policy['token']) === ''
            ) {
                continue;
            }

            $token = trim($policy['token']);

            if (isset($seen[$token])) {
                continue;
            }

            $primary[] = $policy;
            $seen[$token] = true;
        }

        if ($primary !== []) {
            $payload['token_fallbacks'] = array_values($primary);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $componentPayload
     * @return array<string, mixed>
     */
    private function place(
        array $payload,
        array $componentPayload,
        string $placementKey,
    ): array {
        $payload = $this->mergeTokenFallbacks($payload, $componentPayload);

        [$payloadKey, $position] = match ($placementKey) {
            'email_body_append' => ['body', 'append'],
            'email_body_prepend' => ['body', 'prepend'],
            'sms_message_append' => ['message', 'append'],
            'sms_message_prepend' => ['message', 'prepend'],
            default => throw new RuntimeException(
                "Unsupported scheduled-message component placement [{$placementKey}].",
            ),
        };

        $primary = $payload[$payloadKey] ?? null;
        $component = $componentPayload[$payloadKey] ?? null;

        if (! is_string($primary) || trim($primary) === '') {
            throw new RuntimeException(
                "Scheduled-message primary template is missing [{$payloadKey}] for component composition.",
            );
        }

        if (! is_string($component) || trim($component) === '') {
            throw new RuntimeException(
                "Scheduled-message component template is missing [{$payloadKey}].",
            );
        }

        $separator = "\n\n";
        $payload[$payloadKey] = $position === 'prepend'
            ? $component.$separator.$primary
            : $primary.$separator.$component;

        return $payload;
    }
}