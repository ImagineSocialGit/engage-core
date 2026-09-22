<?php

namespace App\Modules\Reporting\Actions;

use App\Modules\Reporting\Models\ScheduledReportSubscription;
use App\Modules\Reporting\Services\ScheduledReportRecipientRegistry;
use App\Modules\Reporting\Services\ScheduledReportRegistry;
use App\Modules\Reporting\Services\ScheduledReportScheduleCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveScheduledReportSubscriptionAction
{
    public function __construct(
        private readonly ScheduledReportRegistry $reports,
        private readonly ScheduledReportRecipientRegistry $recipients,
        private readonly ScheduledReportScheduleCalculator $schedule,
    ) {}

    /**
     * @param array<int, string> $recipientKeys
     * @param array<int, int|string> $daysOfWeek
     * @param array<string, mixed> $parameters
     */
    public function handle(
        string $reportKey,
        string $name,
        array $recipientKeys,
        array $daysOfWeek,
        string $sendTime,
        string $timezone,
        array $parameters,
        bool $isEnabled,
        ?ScheduledReportSubscription $subscription = null,
    ): ScheduledReportSubscription {
        $provider = $this->reports->find($reportKey);

        if ($provider === null) {
            throw ValidationException::withMessages([
                'report_key' => 'That report is not currently available.',
            ]);
        }

        $options = $this->recipients->options();
        $selected = collect($recipientKeys)
            ->map(fn (mixed $key): string => trim((string) $key))
            ->filter()
            ->unique()
            ->map(fn (string $key) => $options->get($key))
            ->filter()
            ->values();

        if ($selected->count() !== collect($recipientKeys)->filter()->unique()->count()
            || $selected->isEmpty()
        ) {
            throw ValidationException::withMessages([
                'recipient_keys' => 'Choose at least one available report recipient.',
            ]);
        }

        $days = collect($daysOfWeek)
            ->map(fn (mixed $day): int => (int) $day)
            ->filter(fn (int $day): bool => $day >= 1 && $day <= 7)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($days === []) {
            throw ValidationException::withMessages([
                'days_of_week' => 'Choose at least one day of the week.',
            ]);
        }

        $normalizedParameters = $provider->normalizeParameters($parameters);

        return DB::transaction(function () use (
            $subscription,
            $reportKey,
            $name,
            $selected,
            $days,
            $sendTime,
            $timezone,
            $normalizedParameters,
            $isEnabled,
        ): ScheduledReportSubscription {
            $subscription ??= new ScheduledReportSubscription();

            $subscription->forceFill([
                'report_key' => $reportKey,
                'name' => trim($name),
                'channel' => 'email',
                'days_of_week' => $days,
                'send_time' => $sendTime,
                'timezone' => $timezone,
                'parameters' => $normalizedParameters,
                'is_enabled' => $isEnabled,
                'next_send_at' => $isEnabled
                    ? $this->schedule->next(
                        daysOfWeek: $days,
                        sendTime: $sendTime,
                        timezone: $timezone,
                    )
                    : null,
            ])->save();

            $subscription->recipients()->delete();

            foreach ($selected as $option) {
                $subscription->recipients()->create([
                    'recipient_type' => $option->recipientType,
                    'recipient_id' => $option->recipientId,
                ]);
            }

            return $subscription->refresh()->load('recipients');
        }, 3);
    }
}