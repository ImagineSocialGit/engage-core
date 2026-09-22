<?php

namespace App\Modules\Reporting\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Actions\SaveScheduledReportSubscriptionAction;
use App\Modules\Reporting\Actions\SendScheduledReportNowAction;
use App\Modules\Reporting\Models\ScheduledReportSubscription;
use App\Modules\Reporting\Services\ScheduledReportRecipientRegistry;
use App\Modules\Reporting\Services\ScheduledReportRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class ScheduledReportController extends Controller
{
    public function index(
        ScheduledReportRegistry $reports,
        ScheduledReportRecipientRegistry $recipients,
    ): View {
        $recipientOptions = $recipients->options();

        return view('crm.reporting.scheduled-reports.index', [
            'availableReports' => $reports->all()
                ->map(fn ($provider): array => [
                    'key' => $provider->key(),
                    'label' => $provider->label(),
                    'description' => $provider->description(),
                    'settings_view' => $provider->settingsView(),
                    'settings_data' => $provider->settingsData(
                        $provider->defaultParameters(),
                    ),
                    'parameters' => $provider->defaultParameters(),
                ])
                ->values(),
            'recipientOptions' => $recipientOptions->values(),
            'subscriptions' => ScheduledReportSubscription::query()
                ->with('recipients')
                ->orderByDesc('is_enabled')
                ->orderBy('name')
                ->get()
                ->map(function (
                    ScheduledReportSubscription $subscription,
                ) use ($reports): array {
                    $provider = $reports->find($subscription->report_key);

                    return [
                        'subscription' => $subscription,
                        'report_label' => $provider?->label()
                            ?? $subscription->report_key,
                        'settings_view' => $provider?->settingsView(),
                        'settings_data' => $provider?->settingsData(
                            is_array($subscription->parameters)
                                ? $subscription->parameters
                                : [],
                        ) ?? [],
                        'parameters' => is_array($subscription->parameters)
                            ? $subscription->parameters
                            : [],
                        'recipient_keys' => $subscription->recipients
                            ->map(fn ($recipient): string =>
                                $recipient->recipient_type.':'.$recipient->recipient_id
                            )
                            ->all(),
                    ];
                })
                ->values(),
            'timezoneDefault' => (string) config(
                'client.timezone',
                config('app.timezone', 'UTC'),
            ),
            'timezones' => timezone_identifiers_list(),
            'weekdays' => [
                1 => 'Monday',
                2 => 'Tuesday',
                3 => 'Wednesday',
                4 => 'Thursday',
                5 => 'Friday',
                6 => 'Saturday',
                7 => 'Sunday',
            ],
        ]);
    }

    public function store(
        Request $request,
        ScheduledReportRegistry $reports,
        SaveScheduledReportSubscriptionAction $save,
    ): RedirectResponse {
        $reportKey = trim((string) $request->input('report_key'));
        $provider = $reports->find($reportKey);

        if ($provider === null) {
            throw ValidationException::withMessages([
                'report_key' => 'That report is not currently available.',
            ]);
        }

        $validated = $this->validatedSchedule($request);

        $save->handle(
            reportKey: $reportKey,
            name: $validated['name'],
            recipientKeys: $validated['recipient_keys'],
            daysOfWeek: $validated['days_of_week'],
            sendTime: $validated['send_time'],
            timezone: $validated['timezone'],
            parameters: (array) $request->input('parameters', []),
            isEnabled: $validated['is_enabled'],
        );

        return redirect()
            ->route('crm.reporting.scheduled-reports.index')
            ->with('status', 'Scheduled report created.');
    }

    public function update(
        Request $request,
        ScheduledReportSubscription $scheduledReportSubscription,
        SaveScheduledReportSubscriptionAction $save,
    ): RedirectResponse {
        $validated = $this->validatedSchedule($request);

        $save->handle(
            reportKey: $scheduledReportSubscription->report_key,
            name: $validated['name'],
            recipientKeys: $validated['recipient_keys'],
            daysOfWeek: $validated['days_of_week'],
            sendTime: $validated['send_time'],
            timezone: $validated['timezone'],
            parameters: (array) $request->input('parameters', []),
            isEnabled: $validated['is_enabled'],
            subscription: $scheduledReportSubscription,
        );

        return redirect()
            ->route('crm.reporting.scheduled-reports.index')
            ->with('status', 'Scheduled report updated.');
    }

    public function destroy(
        ScheduledReportSubscription $scheduledReportSubscription,
    ): RedirectResponse {
        $scheduledReportSubscription->delete();

        return redirect()
            ->route('crm.reporting.scheduled-reports.index')
            ->with('status', 'Scheduled report deleted.');
    }

    public function sendNow(
        ScheduledReportSubscription $scheduledReportSubscription,
        SendScheduledReportNowAction $send,
    ): RedirectResponse {
        $count = $send->handle($scheduledReportSubscription);

        return redirect()
            ->route('crm.reporting.scheduled-reports.index')
            ->with(
                'status',
                $count > 0
                    ? 'Report email queued for '.number_format($count).' recipient(s).'
                    : 'No report email could be queued for the selected recipients.',
            );
    }

    /**
     * @return array{
     *     name: string,
     *     recipient_keys: array<int, string>,
     *     days_of_week: array<int, int>,
     *     send_time: string,
     *     timezone: string,
     *     is_enabled: bool
     * }
     */
    private function validatedSchedule(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'recipient_keys' => ['required', 'array', 'min:1'],
            'recipient_keys.*' => ['required', 'string', 'max:320', 'distinct'],
            'days_of_week' => ['required', 'array', 'min:1'],
            'days_of_week.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'send_time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'timezone'],
            'is_enabled' => ['required', 'boolean'],
        ]);

        return [
            'name' => trim((string) $validated['name']),
            'recipient_keys' => array_values($validated['recipient_keys']),
            'days_of_week' => array_values(array_map(
                'intval',
                $validated['days_of_week'],
            )),
            'send_time' => (string) $validated['send_time'],
            'timezone' => (string) $validated['timezone'],
            'is_enabled' => (bool) $validated['is_enabled'],
        ];
    }
}