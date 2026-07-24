<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PendingMessageDeliveryConsolidator
{
    public function __construct(
        private readonly ScheduledMessagePayloadCanonicalizer $payloadCanonicalizer,
    ) {}

    /**
     * @param array<string, mixed> $incomingAttributes
     */
    public function merge(
        ScheduledMessage $scheduledMessage,
        array $incomingAttributes,
    ): ScheduledMessage {
        $incomingMeta = is_array($incomingAttributes['meta'] ?? null)
            ? $incomingAttributes['meta']
            : [];

        $incomingConsolidation = data_get(
            $incomingMeta,
            'delivery_consolidation',
        );

        if (! is_array($incomingConsolidation)) {
            return $scheduledMessage;
        }

        return DB::transaction(function () use (
            $scheduledMessage,
            $incomingAttributes,
            $incomingMeta,
            $incomingConsolidation,
        ): ScheduledMessage {
            $locked = ScheduledMessage::query()
                ->lockForUpdate()
                ->find($scheduledMessage->getKey());

            if (! $locked instanceof ScheduledMessage) {
                throw new RuntimeException(
                    'Pending message consolidation target no longer exists.',
                );
            }

            if ($locked->status !== ScheduledMessage::STATUS_PENDING) {
                throw new RuntimeException(
                    'Pending message consolidation target is no longer pending.',
                );
            }

            $existingMeta = is_array($locked->meta) ? $locked->meta : [];
            $existingConsolidation = data_get(
                $existingMeta,
                'delivery_consolidation',
                [],
            );

            if (! is_array($existingConsolidation)) {
                $existingConsolidation = [];
            }

            $this->assertCompatibleConsolidation(
                existing: $existingConsolidation,
                incoming: $incomingConsolidation,
            );

            $existingPayload = is_array($locked->payload)
                ? $locked->payload
                : [];

            $incomingPayload = is_array($incomingAttributes['payload'] ?? null)
                ? $incomingAttributes['payload']
                : [];

            $mergedPayload = $this->mergePayload(
                existing: $existingPayload,
                incoming: $incomingPayload,
                existingConsolidation: $existingConsolidation,
                incomingConsolidation: $incomingConsolidation,
            );

            $mergedConsolidation = $this->mergeConsolidationMeta(
                existing: $existingConsolidation,
                incoming: $incomingConsolidation,
            );

            $mergedMeta = array_replace_recursive(
                $incomingMeta,
                $existingMeta,
            );

            data_set(
                $mergedMeta,
                'delivery_consolidation',
                $mergedConsolidation,
            );

            $mergedPayload = $this->payloadCanonicalizer->canonicalize(
                payloadClass: (string) $locked->payload_class,
                payload: $mergedPayload,
                conditions: is_array($mergedMeta['conditions'] ?? null)
                    ? $mergedMeta['conditions']
                    : [],
            );

            $locked->forceFill([
                'payload' => $mergedPayload,
                'meta' => $mergedMeta,
            ])->save();

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $existingConsolidation
     * @param array<string, mixed> $incomingConsolidation
     * @return array<string, mixed>
     */
    private function mergePayload(
        array $existing,
        array $incoming,
        array $existingConsolidation,
        array $incomingConsolidation,
    ): array {
        $merged = array_replace_recursive($incoming, $existing);

        $existingTokens = is_array($existing['tokens'] ?? null)
            ? $existing['tokens']
            : [];

        $incomingTokens = is_array($incoming['tokens'] ?? null)
            ? $incoming['tokens']
            : [];

        if ($existingTokens !== [] || $incomingTokens !== []) {
            $merged['tokens'] = array_replace_recursive(
                $existingTokens,
                $incomingTokens,
            );
        }

        $payloadKey = $this->nullableString(
            $incomingConsolidation['payload_key']
                ?? $existingConsolidation['payload_key']
                ?? null,
        );

        if ($payloadKey === null) {
            return $merged;
        }

        $currentValue = data_get($existing, $payloadKey);

        if (! is_string($currentValue) || trim($currentValue) === '') {
            $currentValue = data_get($incoming, $payloadKey);
        }

        if (! is_string($currentValue) || trim($currentValue) === '') {
            return $merged;
        }

        $fragmentTokens = $this->stringList(
            $incomingConsolidation['fragment_tokens'] ?? [],
        );

        $missingPlaceholders = [];

        foreach ($fragmentTokens as $token) {
            $placeholder = '{'.$token.'}';

            if (! str_contains($currentValue, $placeholder)) {
                $missingPlaceholders[] = $placeholder;
            }
        }

        if ($missingPlaceholders !== []) {
            $separator = is_string(
                $incomingConsolidation['separator'] ?? null,
            )
                ? $incomingConsolidation['separator']
                : "\n\n";

            $position = $this->normalizeSegment(
                (string) (
                    $incomingConsolidation['position']
                        ?? $existingConsolidation['position']
                        ?? 'append'
                ),
            );

            $fragmentBlock = implode($separator, $missingPlaceholders);

            $currentValue = $position === 'prepend'
                ? $fragmentBlock.$separator.$currentValue
                : $currentValue.$separator.$fragmentBlock;
        }

        data_set($merged, $payloadKey, $currentValue);

        return $merged;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeConsolidationMeta(
        array $existing,
        array $incoming,
    ): array {
        $merged = array_replace_recursive($incoming, $existing);

        foreach ([
            'intent_keys',
            'consent_ids',
            'fragment_tokens',
        ] as $listKey) {
            $merged[$listKey] = array_values(array_unique([
                ...$this->listValues($existing[$listKey] ?? []),
                ...$this->listValues($incoming[$listKey] ?? []),
            ], SORT_REGULAR));
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $incoming
     */
    private function assertCompatibleConsolidation(
        array $existing,
        array $incoming,
    ): void {
        foreach (['policy', 'group'] as $key) {
            $existingValue = $this->nullableString($existing[$key] ?? null);
            $incomingValue = $this->nullableString($incoming[$key] ?? null);

            if (
                $existingValue !== null
                && $incomingValue !== null
                && $existingValue !== $incomingValue
            ) {
                throw new RuntimeException(
                    "Pending message consolidation [{$key}] does not match the existing delivery.",
                );
            }
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function listValues(mixed $values): array
    {
        return is_array($values)
            ? array_values(array_filter(
                $values,
                fn (mixed $value): bool =>
                    is_scalar($value)
                    && trim((string) $value) !== '',
            ))
            : [];
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $values): array
    {
        return array_values(array_unique(array_map(
            fn (mixed $value): string => trim((string) $value),
            $this->listValues($values),
        )));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}