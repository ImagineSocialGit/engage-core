<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use App\Modules\Webinars\Services\WebinarMessageAreaRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SyncWebinarScheduleProfilesAction
{
    public function __construct(
        private readonly WebinarMessageAreaRegistry $messageAreaRegistry,
    ) {}

    /**
     * @return array{
     *     profiles_created: int,
     *     profiles_updated: int,
     *     profiles_skipped: int,
     *     items_created: int,
     *     items_updated: int,
     *     items_skipped: int,
     *     items_disabled: int
     * }
     */
    public function handle(bool $force = false): array
    {
        $profiles = config('webinars.schedule_profiles', []);

        if (! is_array($profiles)) {
            $profiles = [];
        }

        $profiles = $this->validatedProfiles($profiles);

        return DB::transaction(function () use ($profiles, $force): array {
            $result = [
                'profiles_created' => 0,
                'profiles_updated' => 0,
                'profiles_skipped' => 0,
                'items_created' => 0,
                'items_updated' => 0,
                'items_skipped' => 0,
                'items_disabled' => 0,
            ];

            foreach ($profiles as $key => $profileConfig) {
                $profileWasCustomized = WebinarScheduleProfile::query()
                    ->where('key', $key)
                    ->value('is_customized') === true;

                $profile = $this->syncProfile($key, $profileConfig, $force);

                if ($profile->wasRecentlyCreated) {
                    $result['profiles_created']++;
                } elseif ($profileWasCustomized && ! $force) {
                    $result['profiles_skipped']++;
                } else {
                    $result['profiles_updated']++;
                }

                $syncedItemKeys = [];

                foreach ($profileConfig['items'] as $index => $itemConfig) {
                    $itemKey = $this->normalizeSegment($this->requiredString(
                        $itemConfig,
                        'key',
                        "webinars.schedule_profiles.{$profile->key}.items.{$index}.key",
                    ));

                    $itemWasCustomized = $profile->items()
                        ->where('key', $itemKey)
                        ->value('is_customized') === true;

                    $item = $this->syncItem(
                        profile: $profile,
                        config: $itemConfig,
                        index: (int) $index,
                        force: $force,
                    );

                    $syncedItemKeys[] = $item->key;

                    if ($item->wasRecentlyCreated) {
                        $result['items_created']++;
                    } elseif ($itemWasCustomized && ! $force) {
                        $result['items_skipped']++;
                    } else {
                        $result['items_updated']++;
                    }
                }

                $staleItems = $profile->items()
                    ->where('is_customized', false);

                if ($syncedItemKeys !== []) {
                    $staleItems->whereNotIn('key', $syncedItemKeys);
                }

                $result['items_disabled'] += $staleItems->update([
                    'is_active' => false,
                ]);
            }

            return $result;
        });
    }

    /**
     * @param array<string, mixed> $profiles
     * @return array<string, array<string, mixed>>
     */
    private function validatedProfiles(array $profiles): array
    {
        $normalizedProfiles = [];
        $activeDefaultKeys = [];

        foreach ($profiles as $key => $profileConfig) {
            if (! is_string($key) || trim($key) === '' || ! is_array($profileConfig)) {
                continue;
            }

            $normalizedKey = $this->normalizeSegment($key);

            if (array_key_exists($normalizedKey, $normalizedProfiles)) {
                throw new InvalidArgumentException(
                    "Duplicate normalized Webinar schedule profile key [{$normalizedKey}]."
                );
            }

            $items = $profileConfig['items'] ?? [];

            if (! is_array($items)) {
                throw new InvalidArgumentException(
                    "Webinar schedule profile [{$normalizedKey}] items must be an array."
                );
            }

            $seenItemKeys = [];

            foreach ($items as $index => $itemConfig) {
                if (! is_array($itemConfig)) {
                    continue;
                }

                $itemKey = $this->normalizeSegment($this->requiredString(
                    $itemConfig,
                    'key',
                    "webinars.schedule_profiles.{$normalizedKey}.items.{$index}.key",
                ));

                if (array_key_exists($itemKey, $seenItemKeys)) {
                    throw new InvalidArgumentException(
                        "Webinar schedule profile [{$normalizedKey}] contains duplicate normalized item key [{$itemKey}]."
                    );
                }

                if (! $this->messageAreaRegistry->areaForScheduleItem($itemConfig)) {
                    throw new InvalidArgumentException(
                        "Webinar schedule profile [{$normalizedKey}] item [{$itemKey}] does not map to a configured Webinar message area."
                    );
                }

                $seenItemKeys[$itemKey] = true;
            }

            $profileConfig['items'] = $items;
            $normalizedProfiles[$normalizedKey] = $profileConfig;

            $status = $this->normalizeSegment((string) (
                $profileConfig['status'] ?? WebinarScheduleProfile::STATUS_ACTIVE
            ));

            if (
                (bool) ($profileConfig['is_default'] ?? false)
                && (bool) ($profileConfig['is_active'] ?? true)
                && $status === WebinarScheduleProfile::STATUS_ACTIVE
            ) {
                $activeDefaultKeys[] = $normalizedKey;
            }
        }

        if (count($activeDefaultKeys) > 1) {
            throw new InvalidArgumentException(
                'Only one active default Webinar schedule profile may be configured. Found: '
                .implode(', ', $activeDefaultKeys).'.'
            );
        }

        return $normalizedProfiles;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function syncProfile(string $key, array $config, bool $force): WebinarScheduleProfile
    {
        $profile = WebinarScheduleProfile::query()->where('key', $key)->first();

        $attributes = [
            'key' => $key,
            'name' => $this->requiredString($config, 'name', "webinars.schedule_profiles.{$key}.name"),
            'description' => $this->nullableString($config['description'] ?? null),
            'status' => $this->normalizeSegment((string) ($config['status'] ?? WebinarScheduleProfile::STATUS_ACTIVE)),
            'is_default' => (bool) ($config['is_default'] ?? false),
            'is_active' => (bool) ($config['is_active'] ?? true),
            'source' => 'config',
            'source_config_path' => "webinars.schedule_profiles.{$key}",
            'source_version' => is_numeric($config['source_version'] ?? null) ? (int) $config['source_version'] : null,
            'last_synced_at' => now(),
            'meta' => is_array($config['meta'] ?? null) ? $config['meta'] : [],
        ];

        if (! $profile instanceof WebinarScheduleProfile) {
            return WebinarScheduleProfile::query()->create([
                ...$attributes,
                'is_customized' => false,
                'customized_at' => null,
            ]);
        }

        if ($profile->is_customized && ! $force) {
            return $profile;
        }

        $profile->forceFill([
            ...$attributes,
            'is_customized' => false,
            'customized_at' => null,
        ])->save();

        return $profile;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function syncItem(
        WebinarScheduleProfile $profile,
        array $config,
        int $index,
        bool $force,
    ): WebinarScheduleProfileItem {
        $key = $this->requiredString(
            $config,
            'key',
            "webinars.schedule_profiles.{$profile->key}.items.{$index}.key",
        );

        $normalizedKey = $this->normalizeSegment($key);
        $messageArea = $this->messageAreaRegistry->areaForScheduleItem($config);

        if (! $messageArea) {
            throw new InvalidArgumentException(
                "Webinar schedule profile item [{$profile->key}:{$normalizedKey}] does not map to a configured Webinar message area."
            );
        }

        $timing = $this->normalizeSegment((string) ($config['timing'] ?? 'immediate'));
        $schedule = is_array($config['schedule'] ?? null) ? $config['schedule'] : null;

        if (! in_array($timing, ['immediate', 'scheduled'], true)) {
            throw new InvalidArgumentException(
                "Webinar schedule profile item [{$profile->key}:{$normalizedKey}] has invalid [timing]."
            );
        }

        if ($timing === 'scheduled') {
            $this->validateSchedule($schedule, $profile->key, $normalizedKey);
        }

        $attributes = [
            'webinar_schedule_profile_id' => $profile->getKey(),
            'key' => $normalizedKey,
            'label' => $this->nullableString($config['label'] ?? null),
            'context_key' => $this->normalizeSegment($this->requiredString(
                $config,
                'context_key',
                "webinars.schedule_profiles.{$profile->key}.items.{$index}.context_key",
            )),
            'channel' => $this->normalizeSegment($this->requiredString(
                $config,
                'channel',
                "webinars.schedule_profiles.{$profile->key}.items.{$index}.channel",
            )),
            'purpose' => $this->normalizeSegment($this->requiredString(
                $config,
                'purpose',
                "webinars.schedule_profiles.{$profile->key}.items.{$index}.purpose",
            )),
            'scope' => $this->normalizeSegment($this->requiredString(
                $config,
                'scope',
                "webinars.schedule_profiles.{$profile->key}.items.{$index}.scope",
            )),
            'surface' => $this->nullableNormalizedString($config['surface'] ?? null),
            'message_type' => $this->normalizeSegment($this->requiredString(
                $config,
                'message_type',
                "webinars.schedule_profiles.{$profile->key}.items.{$index}.message_type",
            )),
            'dispatch_key' => $this->normalizeSegment($this->requiredString(
                $config,
                'dispatch_key',
                "webinars.schedule_profiles.{$profile->key}.items.{$index}.dispatch_key",
            )),
            'message_template_key' => $this->normalizeSegment($this->requiredString(
                $config,
                'message_template_key',
                "webinars.schedule_profiles.{$profile->key}.items.{$index}.message_template_key",
            )),
            'source_config_path' => $this->nullableString($config['source_config_path'] ?? null),
            'is_enabled' => (bool) ($config['is_enabled'] ?? true) && $messageArea->enabled,
            'is_active' => (bool) ($config['is_active'] ?? true),
            'sort_order' => is_numeric($config['sort_order'] ?? null) ? (int) $config['sort_order'] : $index,
            'timing' => $timing,
            'schedule' => $schedule,
            'conditions' => is_array($config['conditions'] ?? null) ? $config['conditions'] : [],
            'meta' => array_replace_recursive(
                is_array($config['meta'] ?? null) ? $config['meta'] : [],
                [
                    'source' => 'config',
                    'source_config_path' => "webinars.schedule_profiles.{$profile->key}.items.{$index}",
                    'webinar_message_area' => [
                        'key' => $messageArea->key,
                        'label' => $messageArea->label,
                        'enabled' => $messageArea->enabled,
                    ],
                ],
            ),
        ];

        $item = $profile->items()->where('key', $normalizedKey)->first();

        if (! $item instanceof WebinarScheduleProfileItem) {
            return WebinarScheduleProfileItem::query()->create([
                ...$attributes,
                'is_customized' => false,
                'customized_at' => null,
            ]);
        }

        if ($item->is_customized && ! $force) {
            return $item;
        }

        $item->forceFill([
            ...$attributes,
            'is_customized' => false,
            'customized_at' => null,
        ])->save();

        return $item;
    }

    /**
     * @param array<string, mixed>|null $schedule
     */
    private function validateSchedule(?array $schedule, string $profileKey, string $itemKey): void
    {
        if (! is_array($schedule)) {
            throw new InvalidArgumentException(
                "Webinar schedule profile item [{$profileKey}:{$itemKey}] is missing [schedule]."
            );
        }

        $type = $schedule['type'] ?? null;

        if (! in_array($type, ['delay', 'anchored', 'next_day_at'], true)) {
            throw new InvalidArgumentException(
                "Webinar schedule profile item [{$profileKey}:{$itemKey}] has invalid [schedule.type]."
            );
        }

        if (in_array($type, ['delay', 'anchored'], true)) {
            if (! is_int($schedule['minutes'] ?? null)) {
                throw new InvalidArgumentException(
                    "Webinar schedule profile item [{$profileKey}:{$itemKey}] has invalid [schedule.minutes]."
                );
            }

            return;
        }

        $time = $schedule['time'] ?? null;

        if (
            ! is_string($time)
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1
        ) {
            throw new InvalidArgumentException(
                "Webinar schedule profile item [{$profileKey}:{$itemKey}] has invalid [schedule.time]. Expected [HH:MM]."
            );
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function requiredString(array $config, string $key, string $path): string
    {
        if (! is_string($config[$key] ?? null) || trim($config[$key]) === '') {
            throw new InvalidArgumentException(
                "Webinar schedule profile config [{$path}] must be a non-empty string."
            );
        }

        return trim($config[$key]);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function nullableNormalizedString(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value !== null ? $this->normalizeSegment($value) : null;
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
