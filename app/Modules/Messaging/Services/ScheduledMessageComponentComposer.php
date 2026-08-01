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
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $componentPayload
     * @return array<string, mixed>
     */
    private function place(
        array $payload,
        array $componentPayload,
        string $placementKey,
    ): array {
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