<?php

namespace App\Modules\Messaging\Actions;

use App\Models\User;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class PublishMessageChainVersionAction
{
    /**
     * @param array<int|string, mixed> $steps
     * @param array<string, mixed> $exitConditions
     */
    public function handle(
        MessageChain $messageChain,
        array $steps,
        array $exitConditions = [],
        ?User $createdBy = null,
        Carbon|string|null $publishedAt = null,
    ): MessageChainVersion {
        return DB::transaction(function () use (
            $messageChain,
            $steps,
            $exitConditions,
            $createdBy,
            $publishedAt,
        ): MessageChainVersion {
            $chain = MessageChain::query()
                ->whereKey($messageChain->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $normalizedExitConditions = $this->nullableArray($exitConditions);
            $normalizedSteps = $this->normalizeSteps($steps);

            $this->assertTemplateVersions($normalizedSteps);

            $contentHash = $this->contentHash(
                exitConditions: $normalizedExitConditions,
                steps: $normalizedSteps,
            );

            $version = MessageChainVersion::query()
                ->where('message_chain_id', $chain->getKey())
                ->where('content_hash', $contentHash)
                ->first();

            if (! $version instanceof MessageChainVersion) {
                $version = MessageChainVersion::query()->create([
                    'message_chain_id' => $chain->getKey(),
                    'version' => $this->nextVersion($chain),
                    'exit_conditions' => $normalizedExitConditions,
                    'content_hash' => $contentHash,
                    'published_at' => null,
                    'created_by' => $createdBy?->getKey(),
                ]);

                $this->createSteps(
                    version: $version,
                    steps: $normalizedSteps,
                );

                $version->forceFill([
                    'published_at' => $publishedAt !== null
                        ? Carbon::parse($publishedAt)
                        : now(),
                ])->save();
            }

            if (! $version->isPublished()) {
                throw new RuntimeException(
                    "MessageChainVersion [{$version->getKey()}] is not published.",
                );
            }

            if ((int) $chain->current_version_id !== (int) $version->getKey()) {
                $chain->forceFill([
                    'current_version_id' => $version->getKey(),
                ])->save();
            }

            $version->load('steps.variants');

            $messageChain->setRawAttributes($chain->getAttributes(), true);
            $messageChain->setRelation('currentVersion', $version);

            return $version;
        }, 3);
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     */
    private function createSteps(
        MessageChainVersion $version,
        array $steps,
    ): void {
        foreach ($steps as $stepDefinition) {
            $variants = $stepDefinition['variants'];
            unset($stepDefinition['variants']);

            $step = $version->steps()->create($stepDefinition);

            foreach ($variants as $variantDefinition) {
                $step->variants()->create($variantDefinition);
            }
        }
    }

    /**
     * @param array<int|string, mixed> $steps
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSteps(array $steps): array
    {
        if ($steps === []) {
            throw new InvalidArgumentException(
                'MessageChainVersion requires at least one step.',
            );
        }

        $normalized = [];

        foreach ($steps as $position => $step) {
            if (! is_array($step)) {
                throw new InvalidArgumentException(
                    'MessageChainVersion steps must be arrays.',
                );
            }

            $fallbackKey = is_string($position) ? $position : null;
            $normalized[] = $this->normalizeStep(
                step: $step,
                fallbackKey: $fallbackKey,
                defaultSortOrder: is_int($position) ? $position : count($normalized),
            );
        }

        $this->assertUniqueKeys(
            values: $normalized,
            label: 'MessageChainVersion step',
        );

        usort(
            $normalized,
            fn (array $left, array $right): int =>
                [$left['sort_order'], $left['key']]
                <=> [$right['sort_order'], $right['key']],
        );

        return array_values($normalized);
    }

    /**
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    private function normalizeStep(
        array $step,
        ?string $fallbackKey,
        int $defaultSortOrder,
    ): array {
        $key = $this->requiredSegment(
            $step['key'] ?? $fallbackKey,
            'MessageChainStep key',
        );
        $timingType = $this->allowedValue(
            value: $step['timing_type'] ?? MessageChainStep::TIMING_IMMEDIATE,
            allowed: [
                MessageChainStep::TIMING_IMMEDIATE,
                MessageChainStep::TIMING_DELAY,
                MessageChainStep::TIMING_ANCHORED,
                MessageChainStep::TIMING_NEXT_DAY_AT,
            ],
            label: "MessageChainStep [{$key}] timing_type",
        );
        $variantStrategy = $this->allowedValue(
            value: $step['variant_strategy']
                ?? MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE,
            allowed: [
                MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE,
                MessageChainStep::VARIANT_STRATEGY_SEND_ALL_ELIGIBLE,
                MessageChainStep::VARIANT_STRATEGY_DEPENDENCY_AWARE,
            ],
            label: "MessageChainStep [{$key}] variant_strategy",
        );
        $advancePolicy = $this->allowedValue(
            value: $step['advance_policy']
                ?? MessageChainStep::ADVANCE_ALL_TERMINAL,
            allowed: [
                MessageChainStep::ADVANCE_ALL_TERMINAL,
                MessageChainStep::ADVANCE_FIRST_SENT,
                MessageChainStep::ADVANCE_FIRST_TERMINAL,
            ],
            label: "MessageChainStep [{$key}] advance_policy",
        );

        $timing = $this->normalizedTiming(
            key: $key,
            timingType: $timingType,
            anchorKey: $step['anchor_key'] ?? null,
            offsetSeconds: $step['offset_seconds'] ?? 0,
            dayOffset: $step['day_offset'] ?? 0,
            localTime: $step['local_time'] ?? null,
        );

        $variants = $this->normalizeVariants(
            stepKey: $key,
            variants: is_array($step['variants'] ?? null)
                ? $step['variants']
                : [],
        );

        return [
            'key' => $key,
            'name' => $this->nullableString($step['name'] ?? null),
            'sort_order' => $this->nonNegativeInteger(
                $step['sort_order'] ?? $defaultSortOrder,
                "MessageChainStep [{$key}] sort_order",
            ),
            ...$timing,
            'variant_strategy' => $variantStrategy,
            'advance_policy' => $advancePolicy,
            'conditions' => $this->nullableArray($step['conditions'] ?? null),
            'is_active' => (bool) ($step['is_active'] ?? true),
            'variants' => $variants,
        ];
    }

    /**
     * @param array<int|string, mixed> $variants
     * @return array<int, array<string, mixed>>
     */
    private function normalizeVariants(
        string $stepKey,
        array $variants,
    ): array {
        if ($variants === []) {
            throw new InvalidArgumentException(
                "MessageChainStep [{$stepKey}] requires at least one variant.",
            );
        }

        $normalized = [];

        foreach ($variants as $position => $variant) {
            if (! is_array($variant)) {
                throw new InvalidArgumentException(
                    "MessageChainStep [{$stepKey}] variants must be arrays.",
                );
            }

            $fallbackKey = is_string($position) ? $position : null;
            $normalized[] = $this->normalizeVariant(
                stepKey: $stepKey,
                variant: $variant,
                fallbackKey: $fallbackKey,
                defaultSortOrder: is_int($position) ? $position : count($normalized),
            );
        }

        $this->assertUniqueKeys(
            values: $normalized,
            label: "MessageChainStep [{$stepKey}] variant",
        );

        usort(
            $normalized,
            fn (array $left, array $right): int =>
                [$left['sort_order'], $left['key']]
                <=> [$right['sort_order'], $right['key']],
        );

        return array_values($normalized);
    }

    /**
     * @param array<string, mixed> $variant
     * @return array<string, mixed>
     */
    private function normalizeVariant(
        string $stepKey,
        array $variant,
        ?string $fallbackKey,
        int $defaultSortOrder,
    ): array {
        $key = $this->requiredSegment(
            $variant['key'] ?? $fallbackKey,
            "MessageChainStep [{$stepKey}] variant key",
        );

        $templateVersionId = $this->positiveInteger(
            $variant['message_template_version_id'] ?? null,
            "MessageChainStep [{$stepKey}] variant [{$key}] message_template_version_id",
        );

        $normalized = [
            'key' => $key,
            'sort_order' => $this->nonNegativeInteger(
                $variant['sort_order'] ?? $defaultSortOrder,
                "MessageChainStep [{$stepKey}] variant [{$key}] sort_order",
            ),
            'message_template_version_id' => $templateVersionId,
            'channel' => $this->requiredSegment(
                $variant['channel'] ?? null,
                "MessageChainStep [{$stepKey}] variant [{$key}] channel",
            ),
            'purpose' => $this->requiredSegment(
                $variant['purpose'] ?? null,
                "MessageChainStep [{$stepKey}] variant [{$key}] purpose",
            ),
            'scope' => $this->requiredSegment(
                $variant['scope'] ?? null,
                "MessageChainStep [{$stepKey}] variant [{$key}] scope",
            ),
            'message_type' => $this->requiredSegment(
                $variant['message_type'] ?? null,
                "MessageChainStep [{$stepKey}] variant [{$key}] message_type",
            ),
            'queue' => $this->nullableSegment($variant['queue'] ?? null),
            'dependency_policy' => $this->nullableArray(
                $variant['dependency_policy'] ?? null,
            ),
            'conditions' => $this->nullableArray(
                $variant['conditions'] ?? null,
            ),
            'is_active' => (bool) ($variant['is_active'] ?? true),
        ];

        $replyProfileKey = $this->nullableSegment(
            $variant['reply_profile_key'] ?? null,
        );

        if ($replyProfileKey !== null) {
            $normalized['reply_profile_key'] = $replyProfileKey;
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     */
    private function assertTemplateVersions(array $steps): void
    {
        $versionIds = collect($steps)
            ->flatMap(fn (array $step): array => array_column(
                $step['variants'],
                'message_template_version_id',
            ))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        /** @var Collection<int, MessageTemplateVersion> $versions */
        $versions = MessageTemplateVersion::query()
            ->with('messageTemplate:id,channel')
            ->whereIn('id', $versionIds->all())
            ->get()
            ->keyBy('id');

        $missing = $versionIds
            ->reject(fn (int $id): bool => $versions->has($id))
            ->values()
            ->all();

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'MessageChainVersion references missing MessageTemplateVersion IDs: '
                .implode(', ', $missing).'.',
            );
        }

        foreach ($steps as $step) {
            foreach ($step['variants'] as $variant) {
                $version = $versions->get(
                    $variant['message_template_version_id'],
                );
                $templateChannel = $this->requiredSegment(
                    $version?->messageTemplate?->channel,
                    'MessageTemplate channel',
                );

                if ($templateChannel !== $variant['channel']) {
                    throw new InvalidArgumentException(sprintf(
                        'MessageChainStep variant [%s] channel [%s] does not match MessageTemplate channel [%s].',
                        $variant['key'],
                        $variant['channel'],
                        $templateChannel,
                    ));
                }
            }
        }
    }

    /**
     * @return array{
     *     timing_type: string,
     *     anchor_key: string|null,
     *     offset_seconds: int,
     *     day_offset: int,
     *     local_time: string|null
     * }
     */
    private function normalizedTiming(
        string $key,
        string $timingType,
        mixed $anchorKey,
        mixed $offsetSeconds,
        mixed $dayOffset,
        mixed $localTime,
    ): array {
        return match ($timingType) {
            MessageChainStep::TIMING_IMMEDIATE => [
                'timing_type' => $timingType,
                'anchor_key' => null,
                'offset_seconds' => 0,
                'day_offset' => 0,
                'local_time' => null,
            ],
            MessageChainStep::TIMING_DELAY => [
                'timing_type' => $timingType,
                'anchor_key' => null,
                'offset_seconds' => $this->nonNegativeInteger(
                    $offsetSeconds,
                    "MessageChainStep [{$key}] offset_seconds",
                ),
                'day_offset' => 0,
                'local_time' => null,
            ],
            MessageChainStep::TIMING_ANCHORED => [
                'timing_type' => $timingType,
                'anchor_key' => $this->requiredSegment(
                    $anchorKey,
                    "MessageChainStep [{$key}] anchor_key",
                ),
                'offset_seconds' => $this->integer(
                    $offsetSeconds,
                    "MessageChainStep [{$key}] offset_seconds",
                ),
                'day_offset' => 0,
                'local_time' => null,
            ],
            MessageChainStep::TIMING_NEXT_DAY_AT => [
                'timing_type' => $timingType,
                'anchor_key' => $this->requiredSegment(
                    $anchorKey,
                    "MessageChainStep [{$key}] anchor_key",
                ),
                'offset_seconds' => 0,
                'day_offset' => $this->smallInteger(
                    $dayOffset,
                    "MessageChainStep [{$key}] day_offset",
                ),
                'local_time' => $this->requiredLocalTime(
                    $localTime,
                    "MessageChainStep [{$key}] local_time",
                ),
            ],
        };
    }

    /**
     * @param array<int, array<string, mixed>> $values
     */
    private function assertUniqueKeys(array $values, string $label): void
    {
        $keys = array_column($values, 'key');

        if (count($keys) === count(array_unique($keys))) {
            return;
        }

        throw new InvalidArgumentException(
            "{$label} keys must be unique.",
        );
    }

    /**
     * @param array<string, mixed>|null $exitConditions
     * @param array<int, array<string, mixed>> $steps
     */
    private function contentHash(
        ?array $exitConditions,
        array $steps,
    ): string {
        try {
            $encoded = json_encode([
                'exit_conditions' => $exitConditions,
                'steps' => $steps,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'MessageChainVersion content could not be encoded.',
                previous: $exception,
            );
        }

        return hash('sha256', $encoded);
    }

    private function nextVersion(MessageChain $messageChain): int
    {
        $currentMaximum = MessageChainVersion::query()
            ->where('message_chain_id', $messageChain->getKey())
            ->max('version');

        return ((int) $currentMaximum) + 1;
    }

    /**
     * @param array<string, mixed>|null $values
     * @return array<string, mixed>|null
     */
    private function nullableArray(mixed $values): ?array
    {
        if ($values === null || $values === []) {
            return null;
        }

        if (! is_array($values)) {
            throw new InvalidArgumentException(
                'MessageChainVersion conditions must be arrays.',
            );
        }

        return $this->normalizeArray($values);
    }

    /**
     * @param array<mixed> $values
     * @return array<mixed>
     */
    private function normalizeArray(array $values): array
    {
        if (! array_is_list($values)) {
            ksort($values);
        }

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->normalizeArray($value);
            }
        }

        return $values;
    }

    /**
     * @param array<int, string> $allowed
     */
    private function allowedValue(
        mixed $value,
        array $allowed,
        string $label,
    ): string {
        $value = $this->requiredSegment($value, $label);

        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(
                "{$label} [{$value}] is unsupported.",
            );
        }

        return $value;
    }

    private function requiredSegment(mixed $value, string $label): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$label} is required.");
        }

        return str_replace('-', '_', strtolower(trim($value)));
    }

    private function nullableSegment(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return str_replace('-', '_', strtolower(trim($value)));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function positiveInteger(mixed $value, string $label): int
    {
        $value = $this->integer($value, $label);

        if ($value <= 0) {
            throw new InvalidArgumentException(
                "{$label} must be greater than zero.",
            );
        }

        return $value;
    }

    private function nonNegativeInteger(mixed $value, string $label): int
    {
        $value = $this->integer($value, $label);

        if ($value < 0) {
            throw new InvalidArgumentException(
                "{$label} cannot be negative.",
            );
        }

        return $value;
    }

    private function smallInteger(mixed $value, string $label): int
    {
        $value = $this->integer($value, $label);

        if ($value < -32768 || $value > 32767) {
            throw new InvalidArgumentException(
                "{$label} must fit a signed small integer.",
            );
        }

        return $value;
    }

    private function integer(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new InvalidArgumentException(
            "{$label} must be an integer.",
        );
    }

    private function requiredLocalTime(mixed $value, string $label): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$label} is required.");
        }

        $value = trim($value);

        if (preg_match(
            '/^(?<hour>[01]\d|2[0-3]):(?<minute>[0-5]\d)(?::(?<second>[0-5]\d))?$/',
            $value,
            $matches,
        ) !== 1) {
            throw new InvalidArgumentException(
                "{$label} must use HH:MM or HH:MM:SS.",
            );
        }

        return sprintf(
            '%s:%s:%s',
            $matches['hour'],
            $matches['minute'],
            $matches['second'] !== '' ? $matches['second'] : '00',
        );
    }
}