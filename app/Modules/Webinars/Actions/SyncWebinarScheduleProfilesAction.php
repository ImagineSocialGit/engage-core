<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use InvalidArgumentException;

class SyncWebinarScheduleProfilesAction
{
    /**
     * @return array{profiles_created: int, profiles_updated: int, items_created: int, items_updated: int, items_disabled: int}
     */
    public function handle(bool $force = false): array
    {
        $profiles = config('webinars.schedule_profiles', []);

        if (! is_array($profiles)) {
            $profiles = [];
        }

        $result = [
            'profiles_created' => 0,
            'profiles_updated' => 0,
            'items_created' => 0,
            'items_updated' => 0,
            'items_disabled' => 0,
        ];

        foreach ($profiles as $key => $profileConfig) {
            if (! is_string($key) || trim($key) === '' || ! is_array($profileConfig)) {
                continue;
            }

            $profile = $this->syncProfile($key, $profileConfig, $force);
            $result[$profile->wasRecentlyCreated ? 'profiles_created' : 'profiles_updated']++;

            $syncedItemKeys = [];

            foreach (($profileConfig['items'] ?? []) as $index => $itemConfig) {
                if (! is_array($itemConfig)) {
                    continue;
                }

                $item = $this->syncItem($profile, $itemConfig, (int) $index);
                $syncedItemKeys[] = $item->key;
                $result[$item->wasRecentlyCreated ? 'items_created' : 'items_updated']++;
            }

            $disabled = $profile->items()
                ->whereNotIn('key', $syncedItemKeys)
                ->update(['is_active' => false]);

            $result['items_disabled'] += $disabled;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function syncProfile(string $key, array $config, bool $force): WebinarScheduleProfile
    {
        $normalizedKey = $this->normalizeSegment($key);
        $profile = WebinarScheduleProfile::query()->where('key', $normalizedKey)->first();
        $attributes = [
            'key' => $normalizedKey,
            'name' => $this->requiredString($config, 'name', "webinars.schedule_profiles.{$key}.name"),
            'description' => $this->nullableString($config['description'] ?? null),
            'status' => $this->normalizeSegment((string) ($config['status'] ?? WebinarScheduleProfile::STATUS_ACTIVE)),
            'is_default' => (bool) ($config['is_default'] ?? false),
            'is_active' => (bool) ($config['is_active'] ?? true),
            'source' => 'config',
            'source_config_path' => "webinars.schedule_profiles.{$normalizedKey}",
            'source_version' => is_numeric($config['source_version'] ?? null) ? (int) $config['source_version'] : null,
            'last_synced_at' => now(),
            'meta' => is_array($config['meta'] ?? null) ? $config['meta'] : [],
        ];

        if (! $profile instanceof WebinarScheduleProfile) {
            return WebinarScheduleProfile::query()->create($attributes);
        }

        $profile->forceFill($attributes)->save();

        return $profile;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function syncItem(WebinarScheduleProfile $profile, array $config, int $index): WebinarScheduleProfileItem
    {
        $key = $this->requiredString($config, 'key', "webinars.schedule_profiles.{$profile->key}.items.{$index}.key");
        $timing = $this->normalizeSegment((string) ($config['timing'] ?? 'immediate'));
        $schedule = is_array($config['schedule'] ?? null) ? $config['schedule'] : null;

        if (! in_array($timing, ['immediate', 'scheduled'], true)) {
            throw new InvalidArgumentException("Webinar schedule profile item [{$profile->key}:{$key}] has invalid [timing].");
        }

        if ($timing === 'scheduled') {
            $this->validateSchedule($schedule, $profile->key, $key);
        }

        $attributes = [
            'webinar_schedule_profile_id' => $profile->getKey(),
            'key' => $this->normalizeSegment($key),
            'label' => $this->nullableString($config['label'] ?? null),
            'context_key' => $this->normalizeSegment($this->requiredString($config, 'context_key', "webinars.schedule_profiles.{$profile->key}.items.{$index}.context_key")),
            'channel' => $this->normalizeSegment($this->requiredString($config, 'channel', "webinars.schedule_profiles.{$profile->key}.items.{$index}.channel")),
            'purpose' => $this->normalizeSegment($this->requiredString($config, 'purpose', "webinars.schedule_profiles.{$profile->key}.items.{$index}.purpose")),
            'scope' => $this->normalizeSegment($this->requiredString($config, 'scope', "webinars.schedule_profiles.{$profile->key}.items.{$index}.scope")),
            'surface' => $this->nullableNormalizedString($config['surface'] ?? null),
            'message_type' => $this->normalizeSegment($this->requiredString($config, 'message_type', "webinars.schedule_profiles.{$profile->key}.items.{$index}.message_type")),
            'dispatch_key' => $this->normalizeSegment($this->requiredString($config, 'dispatch_key', "webinars.schedule_profiles.{$profile->key}.items.{$index}.dispatch_key")),
            'source_config_path' => $this->nullableString($config['source_config_path'] ?? null),
            'is_enabled' => (bool) ($config['is_enabled'] ?? true),
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
                ],
            ),
        ];

        $item = $profile->items()->where('key', $attributes['key'])->first();

        if (! $item instanceof WebinarScheduleProfileItem) {
            return WebinarScheduleProfileItem::query()->create($attributes);
        }

        $item->forceFill($attributes)->save();

        return $item;
    }

    /**
     * @param array<string, mixed>|null $schedule
     */
    private function validateSchedule(?array $schedule, string $profileKey, string $itemKey): void
    {
        if (! is_array($schedule)) {
            throw new InvalidArgumentException("Webinar schedule profile item [{$profileKey}:{$itemKey}] is missing [schedule].");
        }

        if (! in_array($schedule['type'] ?? null, ['delay', 'anchored'], true)) {
            throw new InvalidArgumentException("Webinar schedule profile item [{$profileKey}:{$itemKey}] has invalid [schedule.type].");
        }

        if (! is_int($schedule['minutes'] ?? null)) {
            throw new InvalidArgumentException("Webinar schedule profile item [{$profileKey}:{$itemKey}] has invalid [schedule.minutes].");
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function requiredString(array $config, string $key, string $path): string
    {
        if (! is_string($config[$key] ?? null) || trim($config[$key]) === '') {
            throw new InvalidArgumentException("Webinar schedule profile config [{$path}] must be a non-empty string.");
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
