<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCompositionLayer;
use App\Modules\Messaging\Services\MessageTemplateCompositionSchema;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use JsonException;

class UpsertMessageTemplateCompositionLayerAction
{
    public function __construct(
        private readonly MessageTemplateCompositionSchema $schema,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(
        string $scopeType,
        string $channel,
        array $payload,
        ?string $clientKey = null,
        ?string $contextKey = null,
        ?string $familyKey = null,
        ?MessageTemplate $messageTemplate = null,
        ?string $source = null,
        ?string $sourceVersion = null,
        bool $isCustomized = true,
        ?Carbon $customizedAt = null,
    ): MessageTemplateCompositionLayer {
        if ($messageTemplate instanceof MessageTemplate
            && $this->normalizeChannel((string) $messageTemplate->channel) !== $this->normalizeChannel($channel)
        ) {
            throw new InvalidArgumentException(
                'A message-level composition layer must use the MessageTemplate channel.',
            );
        }

        $normalized = $this->schema->normalize(
            scopeType: $scopeType,
            channel: $channel,
            payload: $payload,
            clientKey: $clientKey,
            contextKey: $contextKey,
            familyKey: $familyKey,
            messageTemplateId: $messageTemplate?->getKey(),
        );

        $identityKey = $this->identityKey(
            normalized: $normalized,
            messageTemplateKey: $messageTemplate?->key,
        );

        return MessageTemplateCompositionLayer::query()->updateOrCreate(
            ['identity_key' => $identityKey],
            $normalized + [
                'source' => $this->nullableString($source),
                'source_version' => $this->nullableString($sourceVersion),
                'is_customized' => $isCustomized,
                'customized_at' => $isCustomized
                    ? ($customizedAt ?? now())
                    : null,
            ],
        );
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function identityKey(
        array $normalized,
        ?string $messageTemplateKey,
    ): string {
        try {
            $json = json_encode([
                'scope_type' => $normalized['scope_type'],
                'channel' => $normalized['channel'],
                'client_key' => $normalized['client_key'],
                'context_key' => $normalized['context_key'],
                'family_key' => $normalized['family_key'],
                'message_template_key' => is_string($messageTemplateKey)
                    ? trim($messageTemplateKey)
                    : null,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Unable to encode message-template composition identity.',
                previous: $exception,
            );
        }

        return hash('sha256', $json);
    }

    private function normalizeChannel(string $channel): string
    {
        return str_replace('-', '_', strtolower(trim($channel)));
    }

    private function nullableString(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}