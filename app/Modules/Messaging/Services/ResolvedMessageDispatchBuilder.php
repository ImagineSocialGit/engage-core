<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Data\ResolvedMessageDispatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ResolvedMessageDispatchBuilder
{
    public function __construct(
        private readonly MessageSendTimeResolver $sendTimeResolver,
    ) {}

    /**
     * Reconcile reusable Messaging template data with caller-owned behavior.
     *
     * The caller owns whether the message exists and the behavior supplied here.
     * Messaging only normalizes that already-selected behavior into one runtime
     * dispatch contract.
     *
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
        array $meta = [],
    ): ResolvedMessageDispatch {
        $triggeredAt = $triggeredAt ? Carbon::parse($triggeredAt) : now();
        $anchor = $anchor ? Carbon::parse($anchor) : null;

        $definition = $this->normalizeDefinition($template, $behavior);

        $resolvedSendAt = $sendAt
            ? Carbon::parse($sendAt)
            : $this->sendTimeResolver->resolve(
                definition: $definition,
                triggeredAt: $triggeredAt,
                anchor: $anchor,
            );

        return new ResolvedMessageDispatch(
            definition: $definition,
            sendAt: $resolvedSendAt,
            behaviorOwner: $behaviorOwner,
            meta: array_replace_recursive(
                [
                    'resolved_message_dispatch' => array_filter([
                        'timing' => $definition['timing'],
                        'schedule' => $definition['schedule'],
                        'conditions' => $definition['conditions'],
                        'skip_when_join_clicked' => $definition['skip_when_join_clicked'] ?? null,
                        'notification_type' => $definition['notification_type'] ?? null,
                        'behavior_owner_type' => $behaviorOwner?->getMorphClass(),
                        'behavior_owner_id' => $behaviorOwner?->getKey(),
                        'resolved_send_at' => $resolvedSendAt->toISOString(),
                    ], fn (mixed $value): bool => $value !== null),
                ],
                $meta,
            ),
        );
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $behavior
     * @return array<string, mixed>
     */
    private function normalizeDefinition(array $template, array $behavior): array
    {
        foreach (['channel', 'purpose', 'scope', 'message_type', 'payload_class', 'payload', 'dispatch_keys'] as $requiredKey) {
            if (! array_key_exists($requiredKey, $template)) {
                throw new InvalidArgumentException("Resolved message dispatch template is missing [{$requiredKey}].");
            }
        }

        $definition = array_replace($template, $behavior);

        $definition['timing'] = $this->normalizeTiming($definition['timing'] ?? 'immediate');
        $definition['schedule'] = is_array($definition['schedule'] ?? null)
            ? $definition['schedule']
            : null;
        $definition['conditions'] = is_array($definition['conditions'] ?? null)
            ? $definition['conditions']
            : [];

        if ($definition['timing'] === 'scheduled' && ! is_array($definition['schedule'])) {
            throw new InvalidArgumentException('Resolved scheduled message dispatch is missing [schedule].');
        }

        return $definition;
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
}
