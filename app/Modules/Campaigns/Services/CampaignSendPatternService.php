<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Validation\ValidationException;

final class CampaignSendPatternService
{
    public const MODE_AS_DUE = 'as_due';
    public const MODE_SPREAD = 'spread';

    /**
     * @return array{
     *     mode: string,
     *     mode_label: string,
     *     daily_limit: int,
     *     days_of_week: array<int, int>,
     *     window_start: string,
     *     window_end: string,
     *     timezone: string
     * }
     */
    public function forCampaign(Campaign $campaign): array
    {
        return $this->normalize(
            is_array($campaign->send_pattern)
                ? $campaign->send_pattern
                : [],
        );
    }

    /**
     * @param array<string, mixed> $pattern
     * @return array<string, mixed>
     */
    public function normalize(array $pattern): array
    {
        $mode = trim((string) ($pattern['mode'] ?? self::MODE_AS_DUE));

        if (! in_array($mode, [self::MODE_AS_DUE, self::MODE_SPREAD], true)) {
            $mode = self::MODE_AS_DUE;
        }

        $timezone = trim((string) (
            $pattern['timezone']
            ?? config('client.timezone', config('app.timezone', 'UTC'))
        ));

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = (string) config('app.timezone', 'UTC');
        }

        $days = collect($pattern['days_of_week'] ?? [1, 2, 3, 4, 5])
            ->map(fn (mixed $day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 7)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($days === []) {
            $days = [1, 2, 3, 4, 5];
        }

        $start = $this->time(
            $pattern['window_start'] ?? null,
            '09:00',
        );
        $end = $this->time(
            $pattern['window_end'] ?? null,
            '17:00',
        );

        if ($end <= $start) {
            $start = '09:00';
            $end = '17:00';
        }

        return [
            'mode' => $mode,
            'mode_label' => $mode === self::MODE_SPREAD
                ? 'Spread throughout the day'
                : 'Send as due',
            'daily_limit' => max(
                1,
                min(100000, (int) ($pattern['daily_limit'] ?? 100)),
            ),
            'days_of_week' => $days,
            'window_start' => $start,
            'window_end' => $end,
            'timezone' => $timezone,
        ];
    }

    /**
     * @param array<string, mixed> $pattern
     * @return array<string, mixed>
     */
    public function forPersistence(array $pattern): array
    {
        $normalized = $this->normalize($pattern);

        if ($normalized['mode'] === self::MODE_SPREAD
            && $normalized['window_end'] <= $normalized['window_start']
        ) {
            throw ValidationException::withMessages([
                'window_end' => 'Stop sending must be later than start sending.',
            ]);
        }

        unset($normalized['mode_label']);

        return $normalized;
    }

    private function time(mixed $value, string $fallback): string
    {
        if (! is_string($value)) {
            return $fallback;
        }

        $value = trim($value);

        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1
            ? $value
            : $fallback;
    }
}