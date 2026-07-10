<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Data\ResolvedMessageDispatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ResolvedMessageDispatchBuilder
{
    private const BEHAVIOR_KEYS = [
        'timing',
        'schedule',
        'conditions',
        'skip_when_join_clicked',
        'notification_type',
    ];

    public function __construct(
        private readonly MessageSendTimeResolver $sendTimeResolver,
    ) {}

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $behavior
     * @param array<string, mixed> $meta
     */
    public function build(
        array $template,
        Carbon|string|null $triggeredAt = null,
        Carbon|string|null $anchor = null,
        Carbon|string|null $sendAt = null,
        array $behavior = [],
        ?Model $behaviorOwner = null,
        ?string $occurrenceKey = null,
        array $meta = [],
    ): ResolvedMessageDispatch {
        $this->assertContentOnlyTemplate($template);

        $triggeredAt = $triggeredAt ? Carbon::parse($triggeredAt) : now();
        $anchor = $anchor ? Carbon::parse($anchor) : null;
        $behavior = $this->normalizeBehavior($behavior, $sendAt !== null);
        $definition = array_replace($template, $behavior);

        $resolvedSendAt = $sendAt !== null
            ? Carbon::parse($sendAt)
            : $this->sendTimeResolver->resolve(
                definition: $definition,
                triggeredAt: $triggeredAt,
                anchor: $anchor,
            );

        $occurrenceKey = $this->nullableString($occurrenceKey);

        return new ResolvedMessageDispatch(
            definition: $definition,
            sendAt: $resolvedSendAt,
            behaviorOwner: $behaviorOwner,
            occurrenceKey: $occurrenceKey,
            meta: array_replace_recursive(
                [
                    'resolved_message_dispatch' => array_filter([
                        'behavior_owner_type' => $behaviorOwner?->getMorphClass(),
                        'behavior_owner_id' => $behaviorOwner?->getKey(),
                        'occurrence_key' => $occurrenceKey,
                        'resolved_send_at' => $resolvedSendAt->toISOString(),
                    ], fn (mixed $value): bool => $value !== null),
                ],
                $meta,
            ),
        );
    }

    /** @param array<string, mixed> $template */
    private function assertContentOnlyTemplate(array $template): void
    {
        foreach (['channel', 'purpose', 'scope', 'message_type', 'payload_class', 'payload', 'dispatch_keys'] as $requiredKey) {
            if (! array_key_exists($requiredKey, $template)) {
                throw new InvalidArgumentException("Resolved message dispatch template is missing [{$requiredKey}].");
            }
        }

        foreach (self::BEHAVIOR_KEYS as $behaviorKey) {
            if (array_key_exists($behaviorKey, $template)) {
                throw new InvalidArgumentException(
                    "Resolved message dispatch template must not own [{$behaviorKey}]. Supply it through resolved behavior instead."
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $behavior
     * @return array<string, mixed>
     */
    private function normalizeBehavior(array $behavior, bool $hasExactSendAt): array
    {
        if ($behavior === []) {
            if (! $hasExactSendAt) {
                throw new InvalidArgumentException(
                    'Resolved message dispatch requires exact [sendAt] or explicit caller-owned [behavior].'
                );
            }

            return [];
        }

        if (! array_key_exists('timing', $behavior)) {
            if (! $hasExactSendAt) {
                throw new InvalidArgumentException('Resolved message dispatch behavior is missing [timing].');
            }
        } else {
            $behavior['timing'] = $this->normalizeTiming($behavior['timing']);
        }

        if (($behavior['timing'] ?? null) === 'scheduled') {
            if (! is_array($behavior['schedule'] ?? null)) {
                throw new InvalidArgumentException('Resolved scheduled message dispatch is missing [schedule].');
            }
        } elseif (array_key_exists('schedule', $behavior) && $behavior['schedule'] !== null && ! is_array($behavior['schedule'])) {
            throw new InvalidArgumentException('Resolved message dispatch has invalid [schedule].');
        }

        if (array_key_exists('conditions', $behavior) && ! is_array($behavior['conditions'])) {
            throw new InvalidArgumentException('Resolved message dispatch has invalid [conditions].');
        }

        $behavior['schedule'] = is_array($behavior['schedule'] ?? null) ? $behavior['schedule'] : null;
        $behavior['conditions'] = is_array($behavior['conditions'] ?? null) ? $behavior['conditions'] : [];

        return $behavior;
    }

    private function normalizeTiming(mixed $timing): string
    {
        if (! is_string($timing)) {
            throw new InvalidArgumentException('Resolved message dispatch has invalid [timing].');
        }

        $timing = str_replace('-', '_', strtolower(trim($timing)));

        if (! in_array($timing, ['immediate', 'scheduled'], true)) {
            throw new InvalidArgumentException('Resolved message dispatch has invalid [timing].');
        }

        return $timing;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
