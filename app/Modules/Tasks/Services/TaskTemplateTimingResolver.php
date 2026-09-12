<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Core\Services\BusinessCalendar\BusinessCalendarDateCalculator;
use App\Modules\Tasks\Models\TaskTemplate;
use Carbon\CarbonImmutable;

final class TaskTemplateTimingResolver
{
    public const UNITS = ['minutes', 'hours', 'days', 'business_days', 'weeks'];

    public function __construct(
        private readonly BusinessCalendarDateCalculator $businessDays,
    ) {}

    public function dueAt(TaskTemplate $template): ?CarbonImmutable
    {
        $timing = data_get($template->meta, 'timing');

        if (is_array($timing)) {
            $mode = $timing['mode'] ?? null;

            if ($mode === 'none') {
                return null;
            }

            if ($mode === 'immediately') {
                return CarbonImmutable::now('UTC');
            }

            $value = is_numeric($timing['value'] ?? null) ? (int) $timing['value'] : null;
            $unit = is_string($timing['unit'] ?? null) ? $timing['unit'] : null;

            if ($mode === 'after' && $value !== null && $value >= 0 && in_array($unit, self::UNITS, true)) {
                $from = CarbonImmutable::now(config('client.timezone', config('app.timezone', 'UTC')));

                return match ($unit) {
                    'minutes' => $from->addMinutes($value)->utc(),
                    'hours' => $from->addHours($value)->utc(),
                    'days' => $from->addDays($value)->utc(),
                    'business_days' => $this->businessDays->addBusinessDays($from, $value),
                    'weeks' => $from->addWeeks($value)->utc(),
                };
            }
        }

        return $template->due_offset_minutes === null
            ? null
            : CarbonImmutable::now('UTC')->addMinutes($template->due_offset_minutes);
    }

    /** @return array{due_offset_minutes: ?int, meta: array<string, mixed>} */
    public function attributes(array $validated, ?array $existingMeta = null): array
    {
        $mode = $validated['due_timing'] ?? 'none';
        $value = $mode === 'after' ? (int) ($validated['due_offset_value'] ?? 0) : null;
        $unit = $mode === 'after' ? (string) ($validated['due_offset_unit'] ?? 'days') : null;
        $minutes = match (true) {
            $mode === 'immediately' => 0,
            $mode !== 'after' => null,
            $unit === 'minutes' => $value,
            $unit === 'hours' => $value * 60,
            $unit === 'days' => $value * 1440,
            $unit === 'weeks' => $value * 10080,
            default => null,
        };

        $meta = is_array($existingMeta) ? $existingMeta : [];
        $meta['timing'] = [
            'mode' => $mode,
            'value' => $value,
            'unit' => $unit,
        ];

        return ['due_offset_minutes' => $minutes, 'meta' => $meta];
    }

    /** @return array{mode: string, value: ?int, unit: string} */
    public function formState(TaskTemplate $template): array
    {
        $timing = data_get($template->meta, 'timing');

        if (is_array($timing) && in_array($timing['mode'] ?? null, ['none', 'immediately', 'after'], true)) {
            return [
                'mode' => $timing['mode'],
                'value' => is_numeric($timing['value'] ?? null) ? (int) $timing['value'] : null,
                'unit' => in_array($timing['unit'] ?? null, self::UNITS, true) ? $timing['unit'] : 'days',
            ];
        }

        $minutes = $template->due_offset_minutes;

        if ($minutes === null) {
            return ['mode' => 'none', 'value' => null, 'unit' => 'days'];
        }

        if ($minutes === 0) {
            return ['mode' => 'immediately', 'value' => null, 'unit' => 'days'];
        }

        foreach ([10080 => 'weeks', 1440 => 'days', 60 => 'hours'] as $divisor => $unit) {
            if ($minutes % $divisor === 0) {
                return ['mode' => 'after', 'value' => intdiv($minutes, $divisor), 'unit' => $unit];
            }
        }

        return ['mode' => 'after', 'value' => $minutes, 'unit' => 'minutes'];
    }
}