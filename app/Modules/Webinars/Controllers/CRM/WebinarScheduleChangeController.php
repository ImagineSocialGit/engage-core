<?php

namespace App\Modules\Webinars\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Webinars\Actions\ProcessWebinarScheduleChangeAction;
use App\Modules\Webinars\Jobs\ProcessWebinarScheduleChangeJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarScheduleChange;
use App\Modules\Webinars\Services\WebinarScheduleChangeCopy;
use App\Modules\Webinars\Services\WebinarTimeChangeTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WebinarScheduleChangeController extends Controller
{
    public function show(
        Request $request,
        Webinar $webinar,
        UserAccessService $access,
        MessageChannelAvailability $availability,
        WebinarScheduleChangeCopy $copy,
        ProcessWebinarScheduleChangeAction $processor,
    ): View {
        $this->authorizeRecipients($request, $access);

        $changes = WebinarScheduleChange::query()
            ->where('webinar_id', $webinar->getKey())
            ->orderByDesc('id')->limit(20)->get();
        $latest = $changes->first();

        return view('crm.webinars.schedule-changes', [
            'webinar' => $webinar,
            'changes' => $changes,
            'latest' => $latest,
            'copy' => $copy,
            'current' => $latest && $processor->isCurrent($latest, $webinar),
            'channels' => $availability->visibleChannelsForSurface(
                surface: 'webinar_registrations',
                purpose: 'transactional',
                scope: 'webinar',
                requireProvider: true,
            ),
            'registrationsCount' => $webinar->registrations()
                ->whereNull('cancelled_at')->where('status', '!=', 'cancelled')->count(),
            'series' => $webinar->webinarSeries,
        ]);
    }

    public function store(
        Request $request,
        Webinar $webinar,
        WebinarScheduleChange $change,
        UserAccessService $access,
        MessageChannelAvailability $availability,
        ProcessWebinarScheduleChangeAction $processor,
        WebinarTimeChangeTemplates $templates,
    ): RedirectResponse {
        $this->authorizeRecipients($request, $access);
        abort_unless((int) $change->webinar_id === (int) $webinar->getKey(), 404);

        $input = $request->validate([
            'channels' => ['required', 'array', 'min:1', 'max:2'],
            'channels.*' => ['required', 'string', 'distinct', 'in:email,sms'],
            'confirm' => ['required', 'accepted'],
        ]);
        $allowed = $availability->visibleChannelsForSurface(
            surface: 'webinar_registrations', purpose: 'transactional',
            scope: 'webinar', requireProvider: true,
        );

        if (array_diff($input['channels'], $allowed)) {
            throw ValidationException::withMessages(['channels' => 'Select currently available channels.']);
        }

        DB::transaction(function () use ($webinar, $change, $input, $processor, $templates): void {
            $lockedWebinar = Webinar::query()->lockForUpdate()->findOrFail($webinar->getKey());
            $lockedChange = WebinarScheduleChange::query()->lockForUpdate()->findOrFail($change->getKey());
            $newer = WebinarScheduleChange::query()
                ->where('webinar_id', $lockedWebinar->getKey())
                ->where('id', '>', $lockedChange->getKey())->exists();

            if ($newer || $lockedChange->status !== WebinarScheduleChange::STATUS_PENDING
                || ! $processor->isCurrent($lockedChange, $lockedWebinar)) {
                throw ValidationException::withMessages([
                    'schedule_change' => 'The webinar schedule has changed. Refresh this page before sending notices.',
                ]);
            }

            $lockedChange->update([
                'status' => WebinarScheduleChange::STATUS_DISPATCHING,
                'channels' => array_values($input['channels']),
                'notification_mode' => 'manual',
                'template_version_ids' => $lockedWebinar->webinarSeries
                    ? $templates->versionsFor($lockedWebinar->webinarSeries, $input['channels'])
                    : [],
                'queued_at' => now(),
            ]);

            ProcessWebinarScheduleChangeJob::dispatch((int) $lockedChange->getKey())->afterCommit();
        }, 3);

        return redirect()->route('crm.webinars.schedule-changes.show', $webinar)
            ->with('status', 'Time-change notices are being prepared. Delivery appears in outbound messages.');
    }

    private function authorizeRecipients(Request $request, UserAccessService $access): void
    {
        abort_unless($access->allows($request->user(), 'contacts.view_all')
            && $access->allows($request->user(), 'contacts.manage'), 403);
    }
}