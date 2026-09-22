<?php

namespace App\Modules\Reporting\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Actions\ProjectReportingDailyMetricsAction;
use App\Modules\Reporting\Services\ReportingWorkspaceReadService;
use App\Modules\Reporting\Services\SchedulingReportingWorkspaceReadService;
use App\Support\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ReportingController extends Controller
{
    private const RANGE_OPTIONS = [7, 30, 90];

    public function index(
        Request $request,
        ReportingWorkspaceReadService $workspace,
        SchedulingReportingWorkspaceReadService $schedulingWorkspace,
        ModuleManager $modules,
    ): View {
        $days = $this->normalizedDays($request);
        $occurrenceId = $this->normalizedOccurrenceId($request);

        $schedulingReport = $schedulingWorkspace->publicBooking($days);
        $showSchedulingReport = $modules->enabled('scheduling')
            || $schedulingReport['has_data'];

        return view('crm.reporting.index', [
            'title' => 'Reporting',
            'heading' => 'Reporting',
            'subheading' => 'See where real visitors move forward, get stuck, or fail to complete public actions.',
            'report' => $workspace->webinarRegistration($days, $occurrenceId),
            'schedulingReport' => $showSchedulingReport
                ? $schedulingReport
                : null,
            'rangeOptions' => self::RANGE_OPTIONS,
        ]);
    }

    public function refresh(
        Request $request,
        ProjectReportingDailyMetricsAction $project,
    ): RedirectResponse {
        $days = $this->normalizedDays($request);
        $occurrenceId = $this->normalizedOccurrenceId($request);
        $timezone = $this->reportingTimezone();
        $through = CarbonImmutable::now($timezone)->startOfDay();

        $project->handle(
            fromDate: $through->subDay(),
            throughDate: $through,
        );

        return redirect()
            ->route('crm.reporting.index', array_filter([
                'days' => $days,
                'webinar_id' => $occurrenceId,
            ]))
            ->with('success', 'Recent Reporting data refreshed.');
    }

    private function normalizedDays(Request $request): int
    {
        $days = $request->integer('days', 30);

        return in_array($days, self::RANGE_OPTIONS, true)
            ? $days
            : 30;
    }

    private function normalizedOccurrenceId(Request $request): ?int
    {
        $occurrenceId = $request->integer('webinar_id');

        return $occurrenceId > 0
            ? $occurrenceId
            : null;
    }

    private function reportingTimezone(): string
    {
        $timezone = config(
            'client.timezone',
            config('app.timezone', 'UTC'),
        );

        return is_string($timezone) && trim($timezone) !== ''
            ? trim($timezone)
            : 'UTC';
    }
}