<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Validation\ValidationException;

final class CampaignAllocationSettingsService
{
    public const DEFAULT_RUN_EVERY_DAYS = 14;
    public const DEFAULT_ALLOCATION_SIZE_PER_MESSAGE = 50;
    public const DEFAULT_RECIPIENT_COOLDOWN_DAYS = 14;

    /**
     * @return array{
     *     run_every_days: int,
     *     allocation_size_per_message: int,
     *     recipient_cooldown_days: int
     * }
     */
    public function forCampaign(Campaign $campaign): array
    {
        return $this->normalize(
            is_array($campaign->allocation_settings)
                ? $campaign->allocation_settings
                : [],
        );
    }

    /**
     * @return array{
     *     summary: string,
     *     example: string,
     *     run_every_days: int,
     *     allocation_size_per_message: int,
     *     recipient_cooldown_days: int
     * }
     */
    public function presentation(Campaign $campaign): array
    {
        $settings = $this->forCampaign($campaign);
        $runEvery = $settings['run_every_days'];
        $perMessage = $settings['allocation_size_per_message'];
        $cooldown = $settings['recipient_cooldown_days'];

        $summary = sprintf(
            'Every %d %s · Up to %d %s per message · %s',
            $runEvery,
            $runEvery === 1 ? 'day' : 'days',
            $perMessage,
            $perMessage === 1 ? 'lead' : 'leads',
            $cooldown === 0
                ? 'No repeat wait'
                : $cooldown.'-day repeat wait',
        );

        $example = sprintf(
            'Every %d %s, this campaign starts a new round of outreach. Each message can choose up to %d eligible %s.%s',
            $runEvery,
            $runEvery === 1 ? 'day' : 'days',
            $perMessage,
            $perMessage === 1 ? 'lead' : 'leads',
            $cooldown === 0
                ? ' A lead can be chosen again in the next round.'
                : sprintf(
                    ' After a lead is chosen, the campaign waits at least %d %s before choosing that same lead again.',
                    $cooldown,
                    $cooldown === 1 ? 'day' : 'days',
                ),
        );

        return [
            'summary' => $summary,
            'example' => $example,
            ...$settings,
        ];
    }

    /**
     * Tolerant runtime normalization for already-persisted settings.
     *
     * @param array<string, mixed> $settings
     * @return array{
     *     run_every_days: int,
     *     allocation_size_per_message: int,
     *     recipient_cooldown_days: int
     * }
     */
    public function normalize(array $settings): array
    {
        return [
            'run_every_days' => $this->boundedInteger(
                $settings['run_every_days'] ?? null,
                self::DEFAULT_RUN_EVERY_DAYS,
                1,
                3650,
            ),
            'allocation_size_per_message' => $this->boundedInteger(
                $settings['allocation_size_per_message'] ?? null,
                self::DEFAULT_ALLOCATION_SIZE_PER_MESSAGE,
                1,
                100000,
            ),
            'recipient_cooldown_days' => $this->boundedInteger(
                $settings['recipient_cooldown_days'] ?? null,
                self::DEFAULT_RECIPIENT_COOLDOWN_DAYS,
                0,
                3650,
            ),
        ];
    }

    /**
     * Strict normalization for operator-authored persistence.
     *
     * @param array<string, mixed> $settings
     * @return array{
     *     run_every_days: int,
     *     allocation_size_per_message: int,
     *     recipient_cooldown_days: int
     * }
     */
    public function forPersistence(array $settings): array
    {
        $allowed = [
            'run_every_days',
            'allocation_size_per_message',
            'recipient_cooldown_days',
        ];
        $unknown = array_values(array_diff(array_keys($settings), $allowed));

        if ($unknown !== []) {
            sort($unknown);

            throw ValidationException::withMessages([
                'allocation_settings' => 'Recurring allocation settings contain unsupported field(s): '.implode(', ', $unknown).'.',
            ]);
        }

        $values = [
            'run_every_days' => $settings['run_every_days']
                ?? self::DEFAULT_RUN_EVERY_DAYS,
            'allocation_size_per_message' => $settings['allocation_size_per_message']
                ?? self::DEFAULT_ALLOCATION_SIZE_PER_MESSAGE,
            'recipient_cooldown_days' => $settings['recipient_cooldown_days']
                ?? self::DEFAULT_RECIPIENT_COOLDOWN_DAYS,
        ];

        $rules = [
            'run_every_days' => [1, 3650],
            'allocation_size_per_message' => [1, 100000],
            'recipient_cooldown_days' => [0, 3650],
        ];

        foreach ($rules as $key => [$minimum, $maximum]) {
            if (! $this->isIntegerLike($values[$key])) {
                throw ValidationException::withMessages([
                    'allocation_settings.'.$key => 'Recurring allocation settings must use whole numbers.',
                ]);
            }

            $value = (int) $values[$key];

            if ($value < $minimum || $value > $maximum) {
                throw ValidationException::withMessages([
                    'allocation_settings.'.$key => sprintf(
                        'Recurring allocation setting [%s] must be between %d and %d.',
                        $key,
                        $minimum,
                        $maximum,
                    ),
                ]);
            }

            $values[$key] = $value;
        }

        return $values;
    }

    private function boundedInteger(
        mixed $value,
        int $fallback,
        int $minimum,
        int $maximum,
    ): int {
        if (! $this->isIntegerLike($value)) {
            return $fallback;
        }

        return max($minimum, min($maximum, (int) $value));
    }

    private function isIntegerLike(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        return is_string($value)
            && preg_match('/^-?\d+$/', trim($value)) === 1;
    }
}