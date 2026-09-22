<?php

namespace App\Support\ModuleIntegrations\Reporting\DailyFollowUp;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\Reporting\Contracts\ScheduledReportProvider;
use App\Modules\Reporting\Data\ScheduledReportResult;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DailyFollowUpScheduledReportProvider implements ScheduledReportProvider
{
    public function __construct(
        private readonly DailyFollowUpReportBuilder $builder,
    ) {}

    public function key(): string
    {
        return 'daily_follow_up';
    }

    public function label(): string
    {
        return 'Daily follow-up';
    }

    public function description(): string
    {
        return 'Replies, new leads, due work, appointments, missing next actions, and stale prospects.';
    }

    public function settingsView(): string
    {
        return 'crm.reporting.scheduled-reports.parameters.daily-follow-up';
    }

    public function defaultParameters(): array
    {
        return [
            'include_replies' => true,
            'include_tasks' => true,
            'include_appointments' => true,
            'new_lead_status_keys' => [],
            'incomplete_application_status_keys' => [],
            'next_action_status_keys' => [],
            'follow_up_status_days' => [],
            'section_limit' => 20,
        ];
    }

    public function settingsData(array $parameters = []): array
    {
        return [
            'status_options' => ContactStatus::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (ContactStatus $status): array => [
                    'key' => (string) $status->key,
                    'name' => (string) $status->name,
                ])
                ->values()
                ->all(),
        ];
    }

    public function normalizeParameters(array $input): array
    {
        $allowed = ContactStatus::query()
            ->where('is_active', true)
            ->pluck('key')
            ->map(fn ($key): string => (string) $key)
            ->all();

        $normalizeKeys = function (mixed $value) use ($allowed): array {
            if (! is_array($value)) {
                return [];
            }

            return collect($value)
                ->map(fn (mixed $key): string => trim((string) $key))
                ->filter(fn (string $key): bool =>
                    $key !== '' && in_array($key, $allowed, true)
                )
                ->unique()
                ->values()
                ->all();
        };

        $followUp = [];

        if (is_array($input['follow_up_status_days'] ?? null)) {
            foreach ($input['follow_up_status_days'] as $key => $days) {
                $key = trim((string) $key);

                if (! in_array($key, $allowed, true)
                    || $days === null
                    || $days === ''
                ) {
                    continue;
                }

                if (! is_numeric($days)
                    || (int) $days < 1
                    || (int) $days > 3650
                ) {
                    throw ValidationException::withMessages([
                        "parameters.follow_up_status_days.{$key}" =>
                            'Follow-up days must be between 1 and 3650.',
                    ]);
                }

                $followUp[$key] = (int) $days;
            }
        }

        ksort($followUp);

        return [
            'include_replies' => filter_var(
                $input['include_replies'] ?? false,
                FILTER_VALIDATE_BOOLEAN,
            ),
            'include_tasks' => filter_var(
                $input['include_tasks'] ?? false,
                FILTER_VALIDATE_BOOLEAN,
            ),
            'include_appointments' => filter_var(
                $input['include_appointments'] ?? false,
                FILTER_VALIDATE_BOOLEAN,
            ),
            'new_lead_status_keys' => $normalizeKeys(
                $input['new_lead_status_keys'] ?? [],
            ),
            'incomplete_application_status_keys' => $normalizeKeys(
                $input['incomplete_application_status_keys'] ?? [],
            ),
            'next_action_status_keys' => $normalizeKeys(
                $input['next_action_status_keys'] ?? [],
            ),
            'follow_up_status_days' => $followUp,
            'section_limit' => 20,
        ];
    }

    public function build(
        array $parameters,
        CarbonInterface $generatedAt,
        string $timezone,
    ): ScheduledReportResult {
        $digest = $this->builder->build(
            parameters: $parameters,
            now: $generatedAt,
            timezone: $timezone,
        );
        $total = (int) $digest['total_count'];
        $body = [
            $total > 0
                ? 'Here are the people and work that need attention.'
                : 'Nothing currently matches this report’s saved follow-up rules.',
        ];

        foreach ($digest['sections'] as $section) {
            $body[] = '';
            $body[] = strtoupper($section['label'])
                .' — '.number_format((int) $section['count']);

            foreach ($section['items'] as $item) {
                $line = '• '.$item['title'];

                if (filled($item['detail'] ?? null)) {
                    $line .= ' — '.$item['detail'];
                }

                if (filled($item['url'] ?? null)) {
                    $line .= ' — '.$item['url'];
                }

                $body[] = $line;
            }

            $remaining = (int) $section['count']
                - count($section['items']);

            if ($remaining > 0) {
                $body[] = '• And '.number_format($remaining).' more.';
            }
        }

        return new ScheduledReportResult(
            subject: $total > 0
                ? 'Daily CRM follow-up: '.number_format($total).' '
                    .Str::plural('item', $total).' need attention'
                : 'Daily CRM follow-up: nothing needs attention',
            headline: 'Today’s follow-up',
            preheader: $total > 0
                ? number_format($total).' '
                    .Str::plural('item', $total).' need attention.'
                : 'No follow-up items are currently waiting.',
            body: $body,
            details: collect($digest['sections'])
                ->mapWithKeys(fn (array $section): array => [
                    $section['label'] => number_format(
                        (int) $section['count'],
                    ),
                ])
                ->all(),
            cta: [
                'label' => 'Open CRM Dashboard',
                'url' => route('crm.index'),
            ],
            meta: [
                'generated_at' => $digest['generated_at']->toIso8601String(),
                'total_count' => $total,
            ],
        );
    }
}