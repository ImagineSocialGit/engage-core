<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use App\Modules\Webinars\Data\WebinarMessageAreaDefinition;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class WebinarScheduleProfileChainDefinitionBuilder
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $resolvedDefinitionCache = [];

    public function __construct(
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
        private readonly WebinarScheduleProfileDefinitionResolver $profileDefinitionResolver,
        private readonly WebinarMessageAreaRegistry $messageAreaRegistry,
    ) {}

    /**
     * @return array<string, array{
     *     key: string,
     *     name: string,
     *     description: string,
     *     dispatch_key: string,
     *     surface: string,
     *     message_area_keys: array<int, string>,
     *     steps: array<int, array<string, mixed>>
     * }>
     */
    public function build(WebinarScheduleProfile $profile): array
    {
        $profile->loadMissing('items');
        $this->resolvedDefinitionCache = [];

        /** @var Collection<int, array{item: WebinarScheduleProfileItem, area: WebinarMessageAreaDefinition, definition: array<string, mixed>}> $resolvedItems */
        $resolvedItems = $profile->items
            ->filter(fn (WebinarScheduleProfileItem $item): bool =>
                $item->is_active && $item->is_enabled
            )
            ->map(function (WebinarScheduleProfileItem $item) use ($profile): ?array {
                $area = $this->messageAreaRegistry->areaForScheduleItem($item);

                if (! $area?->enabled || ! $area->isTemplate()) {
                    return null;
                }

                if (! is_string($area->chainKey) || $area->chainKey === '') {
                    throw new InvalidArgumentException(
                        "Webinar message area [{$area->key}] requires a chain key.",
                    );
                }

                return [
                    'item' => $item,
                    'area' => $area,
                    'definition' => $this->definitionForItem($profile, $item),
                ];
            })
            ->filter()
            ->values();

        $chains = [];

        foreach ($resolvedItems->groupBy(
            fn (array $resolved): string => $resolved['area']->chainKey,
        ) as $chainKey => $chainItems) {
            $chains[$chainKey] = $this->chainDefinition(
                profile: $profile,
                chainKey: $chainKey,
                resolvedItems: $chainItems->values(),
            );
        }

        ksort($chains);

        return $chains;
    }

    /**
     * @param Collection<int, array{item: WebinarScheduleProfileItem, area: WebinarMessageAreaDefinition, definition: array<string, mixed>}> $resolvedItems
     * @return array{
     *     key: string,
     *     name: string,
     *     description: string,
     *     dispatch_key: string,
     *     surface: string,
     *     message_area_keys: array<int, string>,
     *     steps: array<int, array<string, mixed>>
     * }
     */
    private function chainDefinition(
        WebinarScheduleProfile $profile,
        string $chainKey,
        Collection $resolvedItems,
    ): array {
        $dispatchKeys = $resolvedItems
            ->map(fn (array $resolved): string =>
                $this->normalizeSegment($resolved['item']->dispatch_key)
            )
            ->unique()
            ->values();
        $surfaces = $resolvedItems
            ->map(fn (array $resolved): string =>
                $this->normalizeSegment((string) $resolved['item']->surface)
            )
            ->unique()
            ->values();

        if ($dispatchKeys->count() !== 1 || $surfaces->count() !== 1) {
            throw new InvalidArgumentException(
                "Webinar chain group [{$profile->key}:{$chainKey}] must use one dispatch key and one surface.",
            );
        }

        $stepGroups = $resolvedItems->groupBy(
            fn (array $resolved): string => $this->stepSignature(
                item: $resolved['item'],
                area: $resolved['area'],
            ),
        );
        $steps = $stepGroups
            ->map(fn (Collection $group, string $signature): array =>
                $this->stepDefinition($group->values(), $signature)
            )
            ->sortBy(fn (array $step): array => [
                $step['sort_order'],
                $step['key'],
            ])
            ->values()
            ->all();
        $messageAreaKeys = $resolvedItems
            ->map(fn (array $resolved): string => $resolved['area']->key)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $label = str_replace('_', ' ', $chainKey);

        return [
            'key' => $chainKey,
            'name' => $this->boundedString(
                $profile->name.' — '.ucwords($label),
                191,
            ),
            'description' => $this->boundedString(
                "Immutable Webinar message chain synced from schedule profile [{$profile->key}].",
                65535,
            ),
            'dispatch_key' => $dispatchKeys->first(),
            'surface' => $surfaces->first(),
            'message_area_keys' => $messageAreaKeys,
            'steps' => $steps,
        ];
    }

    /**
     * @param Collection<int, array{item: WebinarScheduleProfileItem, area: WebinarMessageAreaDefinition, definition: array<string, mixed>}> $resolvedItems
     * @return array<string, mixed>
     */
    private function stepDefinition(
        Collection $resolvedItems,
        string $signature,
    ): array {
        $first = $resolvedItems->first();
        $firstItem = $first['item'];
        $firstArea = $first['area'];
        $timing = $this->timingDefinition($firstItem);
        $conditions = $this->conditionsFor($firstItem);
        $stepKey = $this->stepKey(
            areaKey: $firstArea->key,
            item: $firstItem,
            signature: $signature,
        );
        $channelCounts = $resolvedItems
            ->map(fn (array $resolved): string =>
                $this->normalizeSegment($resolved['item']->channel)
            )
            ->countBy();
        $variants = $resolvedItems
            ->sortBy(fn (array $resolved): array => [
                (int) $resolved['item']->sort_order,
                $resolved['item']->key,
            ])
            ->values()
            ->map(function (array $resolved) use ($channelCounts): array {
                $item = $resolved['item'];
                $area = $resolved['area'];
                $definition = $resolved['definition'];
                $channel = $this->normalizeSegment($item->channel);
                $variantKey = ((int) $channelCounts->get($channel, 0)) === 1
                    ? $channel
                    : $this->normalizeSegment($item->key);
                $templateVersionId = $definition['message_template_version_id'] ?? null;

                if (! is_numeric($templateVersionId) || (int) $templateVersionId < 1) {
                    throw new RuntimeException(
                        "Webinar schedule profile item [{$item->webinarScheduleProfile?->key}:{$item->key}] has no canonical MessageTemplateVersion.",
                    );
                }

                return [
                    'key' => $variantKey,
                    'sort_order' => (int) $item->sort_order,
                    'message_template_version_id' => (int) $templateVersionId,
                    'channel' => $channel,
                    'purpose' => $this->normalizeSegment($item->purpose),
                    'scope' => $this->normalizeSegment($item->scope),
                    'message_type' => $this->normalizeSegment($item->message_type),
                    'queue' => $this->nullableSegment($definition['queue'] ?? null),
                    'dependency_policy' => null,
                    'conditions' => $this->variantConditions(
                        area: $area,
                        channel: $channel,
                    ),
                    'is_active' => true,
                ];
            })
            ->all();

        return [
            'key' => $stepKey,
            'name' => $this->boundedString(
                $firstItem->label
                    ?: ucwords(str_replace('_', ' ', $stepKey)),
                191,
            ),
            'sort_order' => (int) $resolvedItems
                ->min(fn (array $resolved): int =>
                    (int) $resolved['item']->sort_order
                ),
            ...$timing,
            'variant_strategy' => count($variants) > 1
                ? MessageChainStep::VARIANT_STRATEGY_SEND_ALL_ELIGIBLE
                : MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE,
            'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
            'conditions' => $conditions,
            'is_active' => true,
            'variants' => $variants,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function definitionForItem(
        WebinarScheduleProfile $profile,
        WebinarScheduleProfileItem $item,
    ): array {
        $routeKey = implode('|', [
            $this->normalizeSegment($item->channel),
            $this->normalizeSegment($item->purpose),
            $this->normalizeSegment($item->scope),
        ]);

        if (! array_key_exists($routeKey, $this->resolvedDefinitionCache)) {
            $this->resolvedDefinitionCache[$routeKey] =
                $this->messageDefinitionResolver->resolve(
                    channel: $item->channel,
                    purpose: $item->purpose,
                    scope: $item->scope,
                );
        }

        $resolved = $this->profileDefinitionResolver->applyProfile(
            profile: $profile,
            definitions: $this->resolvedDefinitionCache[$routeKey],
            dispatchKeys: $item->dispatch_key,
            surface: $item->surface,
        );

        foreach ($resolved as $definition) {
            $owner = $definition['behavior_owner'] ?? null;

            if (
                $owner instanceof WebinarScheduleProfileItem
                && (int) $owner->getKey() === (int) $item->getKey()
            ) {
                return $definition;
            }
        }

        throw new RuntimeException(sprintf(
            'Webinar schedule profile item [%s:%s] could not resolve template set [%s] leaf [%s].',
            $profile->key,
            $item->key,
            $profile->message_template_set_key ?: 'default',
            $item->message_template_key,
        ));
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
    private function timingDefinition(
        WebinarScheduleProfileItem $item,
    ): array {
        if ($item->timing === 'immediate') {
            return [
                'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                'anchor_key' => null,
                'offset_seconds' => 0,
                'day_offset' => 0,
                'local_time' => null,
            ];
        }

        $schedule = is_array($item->schedule) ? $item->schedule : [];
        $type = $this->normalizeSegment((string) ($schedule['type'] ?? ''));

        return match ($type) {
            'delay' => [
                'timing_type' => MessageChainStep::TIMING_DELAY,
                'anchor_key' => null,
                'offset_seconds' => $this->minutes($schedule, $item) * 60,
                'day_offset' => 0,
                'local_time' => null,
            ],
            'anchored' => [
                'timing_type' => MessageChainStep::TIMING_ANCHORED,
                'anchor_key' => $this->anchorKey($item),
                'offset_seconds' => $this->minutes($schedule, $item) * 60,
                'day_offset' => 0,
                'local_time' => null,
            ],
            'next_day_at' => [
                'timing_type' => MessageChainStep::TIMING_NEXT_DAY_AT,
                'anchor_key' => $this->anchorKey($item),
                'offset_seconds' => 0,
                'day_offset' => 1,
                'local_time' => $this->localTime($schedule, $item),
            ],
            default => throw new InvalidArgumentException(
                "Webinar schedule profile item [{$item->key}] has unsupported chain timing [{$type}].",
            ),
        };
    }

    /**
     * @return array<int, array{field: string, operator: string}>|null
     */
    private function variantConditions(
        WebinarMessageAreaDefinition $area,
        string $channel,
    ): ?array {
        $field = match ($area->key) {
            'confirmation', 'reminders' =>
                "webinar_registration.accepted_channels.transactional.{$channel}",
            'waitlist' =>
                "webinar_waitlist_signup.accepted_channels.marketing.{$channel}",
            'post_attended', 'post_missed' =>
                "webinar_post_event.allowed_channels.{$channel}",
            default => null,
        };

        return $field !== null
            ? [[
                'field' => $field,
                'operator' => 'truthy',
            ]]
            : null;
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function conditionsFor(
        WebinarScheduleProfileItem $item,
    ): ?array {
        $conditions = is_array($item->conditions)
            ? $item->conditions
            : [];

        if ((bool) data_get($item->meta, 'skip_when_join_clicked', false)) {
            $conditions[] = [
                'field' => 'webinar_registration.join_clicked_at',
                'operator' => 'blank',
            ];
        }

        return $conditions !== [] ? $conditions : null;
    }

    private function stepSignature(
        WebinarScheduleProfileItem $item,
        WebinarMessageAreaDefinition $area,
    ): string {
        try {
            return hash('sha256', json_encode([
                'message_area_key' => $area->key,
                'timing' => $this->timingDefinition($item),
                'conditions' => $this->normalizedArray(
                    $this->conditionsFor($item) ?? [],
                ),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Webinar schedule profile item [{$item->key}] could not be hashed.",
                previous: $exception,
            );
        }
    }

    private function stepKey(
        string $areaKey,
        WebinarScheduleProfileItem $item,
        string $signature,
    ): string {
        $timing = $this->timingDefinition($item);
        $suffix = match ($timing['timing_type']) {
            MessageChainStep::TIMING_IMMEDIATE => 'immediate',
            MessageChainStep::TIMING_DELAY =>
                'delay_'.$this->signedNumber((int) $timing['offset_seconds']),
            MessageChainStep::TIMING_ANCHORED =>
                'anchored_'.$this->signedNumber((int) $timing['offset_seconds']),
            MessageChainStep::TIMING_NEXT_DAY_AT =>
                'next_day_at_'.str_replace(':', '_', (string) $timing['local_time']),
            default => 'step',
        };
        $base = $this->normalizeSegment($areaKey.'_'.$suffix);
        $key = $base.'_'.substr($signature, 0, 8);

        return mb_strlen($key) <= 128
            ? $key
            : mb_substr($base, 0, 119).'_'.substr($signature, 0, 8);
    }

    private function anchorKey(WebinarScheduleProfileItem $item): string
    {
        return match ($this->normalizeSegment($item->dispatch_key)) {
            'registration_created', 'webinar_added' => 'webinar.starts_at',
            'webinar_ended' => 'webinar.ends_at',
            default => throw new InvalidArgumentException(
                "Webinar schedule profile item [{$item->key}] has no chain anchor mapping for dispatch key [{$item->dispatch_key}].",
            ),
        };
    }

    /** @param array<string, mixed> $schedule */
    private function minutes(
        array $schedule,
        WebinarScheduleProfileItem $item,
    ): int {
        $minutes = $schedule['minutes'] ?? null;

        if (! is_int($minutes)) {
            throw new InvalidArgumentException(
                "Webinar schedule profile item [{$item->key}] requires integer schedule minutes.",
            );
        }

        return $minutes;
    }

    /** @param array<string, mixed> $schedule */
    private function localTime(
        array $schedule,
        WebinarScheduleProfileItem $item,
    ): string {
        $time = $schedule['time'] ?? null;

        if (
            ! is_string($time)
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1
        ) {
            throw new InvalidArgumentException(
                "Webinar schedule profile item [{$item->key}] requires schedule time [HH:MM].",
            );
        }

        return $time.':00';
    }

    private function signedNumber(int $value): string
    {
        return $value < 0 ? 'm'.abs($value) : 'p'.$value;
    }

    /**
     * @param array<mixed> $values
     * @return array<mixed>
     */
    private function normalizedArray(array $values): array
    {
        if (! array_is_list($values)) {
            ksort($values);
        }

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->normalizedArray($value);
            }
        }

        return $values;
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }

    private function nullableSegment(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->normalizeSegment($value);
    }

    private function boundedString(string $value, int $length): string
    {
        return mb_substr(trim($value), 0, $length);
    }
}