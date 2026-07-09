<?php

namespace App\Modules\Webinars\Validation;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Support\Collection;
use Throwable;

class WebinarsSetupValidationContributor implements SetupValidationContributor
{
    private const SOURCE = 'webinars.schedule_profiles';
    private const MODULE = 'webinars';

    public function __construct(
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
        private readonly MessageChannelAvailability $messageChannelAvailability,
    ) {}

    public function findings(): iterable
    {
        yield from $this->validateConfigProfiles();
        yield from $this->validateRuntimeProfiles();
        yield from $this->validateSelectedProfiles();
    }

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateConfigProfiles(): iterable
    {
        $profiles = config('webinars.schedule_profiles', []);

        if (! is_array($profiles)) {
            yield $this->error(
                code: 'webinars.schedule_profiles.config_invalid',
                message: 'Webinar schedule profiles config must be an array.',
                path: self::SOURCE,
            );

            return;
        }

        $seenProfileKeys = [];
        $activeDefaultKeys = [];

        foreach ($profiles as $profileKey => $profileConfig) {
            $path = self::SOURCE.'.'.(is_string($profileKey) ? $profileKey : (string) $profileKey);

            if (! $this->filledString($profileKey) || ! is_array($profileConfig)) {
                yield $this->error(
                    code: 'webinars.schedule_profiles.profile_invalid',
                    message: 'Webinar schedule profile keys must be non-empty strings and profile definitions must be arrays.',
                    path: $path,
                );

                continue;
            }

            $normalizedProfileKey = $this->normalizeSegment($profileKey);

            if (isset($seenProfileKeys[$normalizedProfileKey])) {
                yield $this->error(
                    code: 'webinars.schedule_profiles.duplicate_profile_key',
                    message: "Duplicate normalized Webinar schedule profile key [{$normalizedProfileKey}].",
                    path: $path,
                    context: [
                        'profile_key' => $profileKey,
                        'normalized_profile_key' => $normalizedProfileKey,
                        'first_profile_key' => $seenProfileKeys[$normalizedProfileKey],
                    ],
                );
            } else {
                $seenProfileKeys[$normalizedProfileKey] = $profileKey;
            }

            if (! $this->filledString($profileConfig['name'] ?? null)) {
                yield $this->error(
                    code: 'webinars.schedule_profiles.name_missing',
                    message: "Webinar schedule profile [{$normalizedProfileKey}] is missing a non-empty [name].",
                    path: "{$path}.name",
                    context: [
                        'profile_key' => $normalizedProfileKey,
                    ],
                );
            }

            $status = $this->normalizeSegment((string) (
                $profileConfig['status'] ?? WebinarScheduleProfile::STATUS_ACTIVE
            ));

            if (! in_array($status, [
                WebinarScheduleProfile::STATUS_ACTIVE,
                WebinarScheduleProfile::STATUS_INACTIVE,
            ], true)) {
                yield $this->error(
                    code: 'webinars.schedule_profiles.status_invalid',
                    message: "Webinar schedule profile [{$normalizedProfileKey}] has invalid status [{$status}].",
                    path: "{$path}.status",
                    context: [
                        'profile_key' => $normalizedProfileKey,
                    ],
                );
            }

            foreach (['is_default', 'is_active'] as $booleanField) {
                if (array_key_exists($booleanField, $profileConfig) && ! is_bool($profileConfig[$booleanField])) {
                    yield $this->error(
                        code: 'webinars.schedule_profiles.activation_flag_invalid',
                        message: "Webinar schedule profile [{$normalizedProfileKey}] [{$booleanField}] must be boolean.",
                        path: "{$path}.{$booleanField}",
                        context: [
                            'profile_key' => $normalizedProfileKey,
                            'field' => $booleanField,
                        ],
                    );
                }
            }

            if (
                ($profileConfig['is_default'] ?? false) === true
                && ($profileConfig['is_active'] ?? true) === true
                && $status === WebinarScheduleProfile::STATUS_ACTIVE
            ) {
                $activeDefaultKeys[] = $normalizedProfileKey;
            }

            $items = $profileConfig['items'] ?? null;

            if (! is_array($items)) {
                yield $this->error(
                    code: 'webinars.schedule_profiles.items_invalid',
                    message: "Webinar schedule profile [{$normalizedProfileKey}] [items] must be an array.",
                    path: "{$path}.items",
                    context: [
                        'profile_key' => $normalizedProfileKey,
                    ],
                );

                continue;
            }

            yield from $this->validateConfigItems(
                profileKey: $normalizedProfileKey,
                items: $items,
                basePath: "{$path}.items",
            );
        }

        if (count($activeDefaultKeys) > 1) {
            yield $this->error(
                code: 'webinars.schedule_profiles.multiple_active_defaults',
                message: 'Only one active default Webinar schedule profile may be configured.',
                path: self::SOURCE,
                context: [
                    'profile_keys' => $activeDefaultKeys,
                ],
            );
        }
    }

    /**
     * @param array<int|string, mixed> $items
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateConfigItems(
        string $profileKey,
        array $items,
        string $basePath,
    ): iterable {
        $seenItemKeys = [];

        foreach ($items as $index => $item) {
            $path = "{$basePath}.{$index}";

            if (! is_array($item)) {
                yield $this->error(
                    code: 'webinars.schedule_profiles.item_invalid',
                    message: "Webinar schedule profile [{$profileKey}] contains a non-array item definition.",
                    path: $path,
                    context: [
                        'profile_key' => $profileKey,
                    ],
                );

                continue;
            }

            $itemKey = $item['key'] ?? null;

            if (! $this->filledString($itemKey)) {
                yield $this->error(
                    code: 'webinars.schedule_profiles.item_key_missing',
                    message: "Webinar schedule profile [{$profileKey}] contains an item without a non-empty [key].",
                    path: "{$path}.key",
                    context: [
                        'profile_key' => $profileKey,
                    ],
                );

                continue;
            }

            $normalizedItemKey = $this->normalizeSegment($itemKey);

            if (isset($seenItemKeys[$normalizedItemKey])) {
                yield $this->error(
                    code: 'webinars.schedule_profiles.duplicate_item_key',
                    message: "Webinar schedule profile [{$profileKey}] contains duplicate normalized item key [{$normalizedItemKey}].",
                    path: "{$path}.key",
                    context: [
                        'profile_key' => $profileKey,
                        'item_key' => $normalizedItemKey,
                        'first_item_index' => $seenItemKeys[$normalizedItemKey],
                        'second_item_index' => $index,
                    ],
                );
            } else {
                $seenItemKeys[$normalizedItemKey] = $index;
            }

            foreach ([
                'context_key',
                'channel',
                'purpose',
                'scope',
                'message_type',
                'dispatch_key',
            ] as $requiredField) {
                if (! $this->filledString($item[$requiredField] ?? null)) {
                    yield $this->error(
                        code: 'webinars.schedule_profiles.item_identity_missing',
                        message: "Webinar schedule profile item [{$profileKey}:{$normalizedItemKey}] is missing non-empty [{$requiredField}].",
                        path: "{$path}.{$requiredField}",
                        context: [
                            'profile_key' => $profileKey,
                            'item_key' => $normalizedItemKey,
                            'field' => $requiredField,
                        ],
                    );
                }
            }

            foreach (['is_enabled', 'is_active'] as $booleanField) {
                if (array_key_exists($booleanField, $item) && ! is_bool($item[$booleanField])) {
                    yield $this->error(
                        code: 'webinars.schedule_profiles.item_activation_flag_invalid',
                        message: "Webinar schedule profile item [{$profileKey}:{$normalizedItemKey}] [{$booleanField}] must be boolean.",
                        path: "{$path}.{$booleanField}",
                        context: [
                            'profile_key' => $profileKey,
                            'item_key' => $normalizedItemKey,
                            'field' => $booleanField,
                        ],
                    );
                }
            }

            if (array_key_exists('conditions', $item) && ! is_array($item['conditions'])) {
                yield $this->error(
                    code: 'webinars.schedule_profiles.item_conditions_invalid',
                    message: "Webinar schedule profile item [{$profileKey}:{$normalizedItemKey}] [conditions] must be an array.",
                    path: "{$path}.conditions",
                    context: [
                        'profile_key' => $profileKey,
                        'item_key' => $normalizedItemKey,
                    ],
                );
            }

            yield from $this->validateTimingAndSchedule(
                profileKey: $profileKey,
                itemKey: $normalizedItemKey,
                timing: $item['timing'] ?? 'immediate',
                schedule: $item['schedule'] ?? null,
                path: $path,
            );
        }
    }

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateRuntimeProfiles(): iterable
    {
        /** @var Collection<int, WebinarScheduleProfile> $profiles */
        $profiles = WebinarScheduleProfile::query()
            ->with('items')
            ->orderBy('key')
            ->get();

        $activeDefaults = $profiles
            ->filter(fn (WebinarScheduleProfile $profile): bool => $profile->is_default
                && $profile->is_active
                && $profile->status === WebinarScheduleProfile::STATUS_ACTIVE)
            ->values();

        if ($activeDefaults->count() > 1) {
            yield $this->error(
                code: 'webinars.schedule_profiles.runtime_multiple_active_defaults',
                message: 'Multiple active default Webinar schedule profiles exist in DB runtime state.',
                path: 'webinar_schedule_profiles',
                context: [
                    'profile_ids' => $activeDefaults->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                    'profile_keys' => $activeDefaults->pluck('key')->all(),
                ],
            );
        }

        foreach ($profiles as $profile) {
            $profilePath = "webinar_schedule_profiles.{$profile->getKey()}";

            if (! $this->filledString($profile->key)) {
                yield $this->error(
                    code: 'webinars.schedule_profiles.runtime_key_missing',
                    message: "Webinar schedule profile [{$profile->getKey()}] has no stable key.",
                    path: "{$profilePath}.key",
                    context: [
                        'profile_id' => $profile->getKey(),
                    ],
                );

                continue;
            }

            if (! in_array($profile->status, [
                WebinarScheduleProfile::STATUS_ACTIVE,
                WebinarScheduleProfile::STATUS_INACTIVE,
            ], true)) {
                yield $this->error(
                    code: 'webinars.schedule_profiles.runtime_status_invalid',
                    message: "Webinar schedule profile [{$profile->key}] has invalid status [{$profile->status}].",
                    path: "{$profilePath}.status",
                    context: [
                        'profile_id' => $profile->getKey(),
                        'profile_key' => $profile->key,
                    ],
                );
            }

            $duplicateItemKeys = $profile->items
                ->groupBy(fn (WebinarScheduleProfileItem $item): string => $this->normalizeSegment((string) $item->key))
                ->filter(fn (Collection $items): bool => $items->count() > 1);

            foreach ($duplicateItemKeys as $itemKey => $duplicateItems) {
                yield $this->error(
                    code: 'webinars.schedule_profiles.runtime_duplicate_item_key',
                    message: "Webinar schedule profile [{$profile->key}] contains duplicate runtime item key [{$itemKey}].",
                    path: "{$profilePath}.items",
                    context: [
                        'profile_id' => $profile->getKey(),
                        'profile_key' => $profile->key,
                        'item_key' => $itemKey,
                        'item_ids' => $duplicateItems->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                    ],
                );
            }

            foreach ($profile->items as $item) {
                if (! $item->is_active || ! $item->is_enabled) {
                    continue;
                }

                yield from $this->validateRuntimeItem($profile, $item);
            }
        }
    }

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateRuntimeItem(
        WebinarScheduleProfile $profile,
        WebinarScheduleProfileItem $item,
    ): iterable {
        $path = "webinar_schedule_profile_items.{$item->getKey()}";
        $context = [
            'profile_id' => $profile->getKey(),
            'profile_key' => $profile->key,
            'item_id' => $item->getKey(),
            'item_key' => $item->key,
        ];

        foreach ([
            'key',
            'context_key',
            'channel',
            'purpose',
            'scope',
            'surface',
            'message_type',
            'dispatch_key',
        ] as $requiredField) {
            if (! $this->filledString($item->{$requiredField})) {
                yield $this->error(
                    code: 'webinars.schedule_profiles.runtime_item_identity_missing',
                    message: "Active Webinar schedule profile item [{$profile->key}:{$item->key}] is missing [{$requiredField}].",
                    path: "{$path}.{$requiredField}",
                    context: $context + [
                        'field' => $requiredField,
                    ],
                );
            }
        }

        if (! in_array($item->channel, MessageChannel::values(), true)) {
            yield $this->error(
                code: 'webinars.schedule_profiles.runtime_channel_invalid',
                message: "Active Webinar schedule profile item [{$profile->key}:{$item->key}] has unsupported channel [{$item->channel}].",
                path: "{$path}.channel",
                context: $context,
            );

            return;
        }

        if (! in_array($item->purpose, MessagePurpose::values(), true)) {
            yield $this->error(
                code: 'webinars.schedule_profiles.runtime_purpose_invalid',
                message: "Active Webinar schedule profile item [{$profile->key}:{$item->key}] has unsupported purpose [{$item->purpose}].",
                path: "{$path}.purpose",
                context: $context,
            );

            return;
        }

        yield from $this->validateTimingAndSchedule(
            profileKey: $profile->key,
            itemKey: $item->key,
            timing: $item->timing,
            schedule: $item->schedule,
            path: $path,
            context: $context,
        );

        if (! $this->filledString($item->surface)) {
            return;
        }

        if (! $this->messageChannelAvailability->isVisibleForSurface(
            channel: $item->channel,
            surface: $item->surface,
            purpose: $item->purpose,
            scope: $item->scope,
        )) {
            yield $this->warning(
                code: 'webinars.schedule_profiles.channel_unavailable_for_surface',
                message: "Webinar schedule profile item [{$profile->key}:{$item->key}] references channel [{$item->channel}] that is not currently available for surface [{$item->surface}].",
                path: "{$path}.channel",
                context: $context + [
                    'channel' => $item->channel,
                    'surface' => $item->surface,
                    'purpose' => $item->purpose,
                    'scope' => $item->scope,
                ],
            );
        }

        try {
            $definitions = $this->messageDefinitionResolver->resolve(
                channel: $item->channel,
                purpose: $item->purpose,
                scope: $item->scope,
            );
        } catch (Throwable $exception) {
            yield $this->error(
                code: 'webinars.schedule_profiles.messaging_resolution_failed',
                message: "Webinar schedule profile item [{$profile->key}:{$item->key}] could not resolve Messaging definitions: {$exception->getMessage()}",
                path: $path,
                context: $context,
                meta: [
                    'exception' => $exception::class,
                ],
            );

            return;
        }

        if (! $this->matchingDefinitionExists($item, $definitions)) {
            yield $this->error(
                code: 'webinars.schedule_profiles.messaging_definition_missing',
                message: "Webinar schedule profile item [{$profile->key}:{$item->key}] does not resolve to a compatible Messaging definition.",
                path: $path,
                context: $context + [
                    'channel' => $item->channel,
                    'purpose' => $item->purpose,
                    'scope' => $item->scope,
                    'message_type' => $item->message_type,
                    'dispatch_key' => $item->dispatch_key,
                    'source_config_path' => $item->source_config_path,
                ],
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     */
    private function matchingDefinitionExists(
        WebinarScheduleProfileItem $item,
        array $definitions,
    ): bool {
        foreach ($definitions as $definition) {
            $messageType = $this->normalizeSegment((string) ($definition['message_type'] ?? ''));

            if ($messageType !== $this->normalizeSegment((string) $item->message_type)) {
                continue;
            }

            $dispatchKeys = $definition['dispatch_keys'] ?? [];

            if (! is_array($dispatchKeys) || ! in_array(
                $this->normalizeSegment((string) $item->dispatch_key),
                array_map(
                    fn (mixed $key): string => $this->normalizeSegment((string) $key),
                    $dispatchKeys,
                ),
                true,
            )) {
                continue;
            }

            $requiredSourcePath = $this->nullableString($item->source_config_path);

            if ($requiredSourcePath === null) {
                return true;
            }

            $definitionSourcePaths = array_values(array_filter([
                $this->nullableString($definition['source_config_path'] ?? null),
                $this->nullableString($definition['config_path'] ?? null),
                $this->nullableString(data_get($definition, 'meta.seed.config_path')),
                $this->nullableString(data_get($definition, 'meta.message_template_assignment.source_config_path')),
                $this->nullableString(data_get($definition, 'meta.message_template_preset.source_config_path')),
            ]));

            if (in_array($requiredSourcePath, $definitionSourcePaths, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateSelectedProfiles(): iterable
    {
        $activeDefaultProfile = WebinarScheduleProfile::query()
            ->active()
            ->where('is_default', true)
            ->orderBy('id')
            ->first();

        yield from $this->validateSelectedProfileOwners(
            ownerType: 'webinar_series',
            owners: WebinarSeries::query()->get(['id', 'webinar_schedule_profile_id']),
            activeDefaultProfile: $activeDefaultProfile,
        );

        yield from $this->validateSelectedProfileOwners(
            ownerType: 'webinar',
            owners: Webinar::query()->get(['id', 'webinar_schedule_profile_id']),
            activeDefaultProfile: $activeDefaultProfile,
        );
    }

    /**
     * @param Collection<int, WebinarSeries|Webinar> $owners
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateSelectedProfileOwners(
        string $ownerType,
        Collection $owners,
        ?WebinarScheduleProfile $activeDefaultProfile,
    ): iterable {
        $missingFallbackReported = false;

        foreach ($owners as $owner) {
            $profileId = $owner->webinar_schedule_profile_id;

            if ($profileId === null) {
                if ($activeDefaultProfile === null && ! $missingFallbackReported) {
                    yield $this->error(
                        code: 'webinars.schedule_profiles.default_fallback_missing',
                        message: "At least one [{$ownerType}] relies on a Webinar schedule-profile fallback, but no active default profile exists.",
                        path: 'webinar_schedule_profiles',
                        context: [
                            'owner_type' => $ownerType,
                        ],
                    );

                    $missingFallbackReported = true;
                }

                continue;
            }

            $profile = WebinarScheduleProfile::query()->find($profileId);

            if (! $profile instanceof WebinarScheduleProfile) {
                yield $this->error(
                    code: 'webinars.schedule_profiles.selected_profile_missing',
                    message: ucfirst(str_replace('_', ' ', $ownerType))." [{$owner->getKey()}] references missing Webinar schedule profile [{$profileId}].",
                    path: "{$ownerType}.{$owner->getKey()}.webinar_schedule_profile_id",
                    context: [
                        'owner_type' => $ownerType,
                        'owner_id' => $owner->getKey(),
                        'profile_id' => $profileId,
                    ],
                );

                continue;
            }

            if (! $profile->is_active || $profile->status !== WebinarScheduleProfile::STATUS_ACTIVE) {
                yield $this->error(
                    code: 'webinars.schedule_profiles.selected_profile_inactive',
                    message: ucfirst(str_replace('_', ' ', $ownerType))." [{$owner->getKey()}] references inactive Webinar schedule profile [{$profile->key}].",
                    path: "{$ownerType}.{$owner->getKey()}.webinar_schedule_profile_id",
                    context: [
                        'owner_type' => $ownerType,
                        'owner_id' => $owner->getKey(),
                        'profile_id' => $profile->getKey(),
                        'profile_key' => $profile->key,
                    ],
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateTimingAndSchedule(
        string $profileKey,
        string $itemKey,
        mixed $timing,
        mixed $schedule,
        string $path,
        array $context = [],
    ): iterable {
        $normalizedTiming = is_string($timing)
            ? $this->normalizeSegment($timing)
            : '';

        if (! in_array($normalizedTiming, ['immediate', 'scheduled'], true)) {
            yield $this->error(
                code: 'webinars.schedule_profiles.timing_invalid',
                message: "Webinar schedule profile item [{$profileKey}:{$itemKey}] has invalid [timing].",
                path: "{$path}.timing",
                context: $context,
            );

            return;
        }

        if ($normalizedTiming !== 'scheduled') {
            return;
        }

        if (! is_array($schedule)) {
            yield $this->error(
                code: 'webinars.schedule_profiles.schedule_missing',
                message: "Scheduled Webinar profile item [{$profileKey}:{$itemKey}] is missing [schedule].",
                path: "{$path}.schedule",
                context: $context,
            );

            return;
        }

        if (! in_array($schedule['type'] ?? null, ['delay', 'anchored'], true)) {
            yield $this->error(
                code: 'webinars.schedule_profiles.schedule_type_invalid',
                message: "Webinar schedule profile item [{$profileKey}:{$itemKey}] has invalid [schedule.type].",
                path: "{$path}.schedule.type",
                context: $context,
            );
        }

        if (! is_int($schedule['minutes'] ?? null)) {
            yield $this->error(
                code: 'webinars.schedule_profiles.schedule_minutes_invalid',
                message: "Webinar schedule profile item [{$profileKey}:{$itemKey}] has invalid [schedule.minutes].",
                path: "{$path}.schedule.minutes",
                context: $context,
            );
        }
    }

    private function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $meta
     */
    private function error(
        string $code,
        string $message,
        string $path,
        array $context = [],
        array $meta = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
            context: $context,
            meta: $meta,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function warning(
        string $code,
        string $message,
        string $path,
        array $context = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_WARNING,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
            context: $context,
        );
    }
}
