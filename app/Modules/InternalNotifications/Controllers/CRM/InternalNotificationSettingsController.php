<?php

namespace App\Modules\InternalNotifications\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\InternalNotifications\Actions\SaveInternalNotificationRecipientAction;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Models\TeamMemberNotificationPreference;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class InternalNotificationSettingsController extends Controller
{
    public function index(): View
    {
        return view('crm.internal-notifications.settings.index', [
            'recipients' => TeamMember::query()
                ->with('notificationPreferences')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->orderBy('email')
                ->get()
                ->map(fn (TeamMember $teamMember): array => $this->row($teamMember))
                ->values(),
        ]);
    }

    public function store(
        Request $request,
        SaveInternalNotificationRecipientAction $saveRecipient,
    ): RedirectResponse {
        $validated = $this->validated($request);

        $saveRecipient->handle(
            name: $validated['name'],
            email: $validated['email'],
            isActive: $validated['is_active'],
            receiveInboundReplies: $validated['receive_inbound_replies'],
            receiveScheduledReports: $validated['receive_scheduled_reports'],
        );

        return redirect()
            ->route('crm.internal-notifications.settings.index')
            ->with('status', 'Notification recipient added.');
    }

    public function update(
        Request $request,
        TeamMember $teamMember,
        SaveInternalNotificationRecipientAction $saveRecipient,
    ): RedirectResponse {
        $validated = $this->validated($request);

        $saveRecipient->handle(
            name: $validated['name'],
            email: $validated['email'],
            isActive: $validated['is_active'],
            receiveInboundReplies: $validated['receive_inbound_replies'],
            receiveScheduledReports: $validated['receive_scheduled_reports'],
            teamMember: $teamMember,
        );

        return redirect()
            ->route('crm.internal-notifications.settings.index')
            ->with('status', 'Notification recipient updated.');
    }

    /**
     * @return array{
     *     name: string,
     *     email: string,
     *     is_active: bool,
     *     receive_inbound_replies: bool,
     *     receive_scheduled_reports: bool
     * }
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'receive_inbound_replies' => ['required', 'boolean'],
            'receive_scheduled_reports' => ['required', 'boolean'],
        ]);

        return [
            'name' => trim((string) $validated['name']),
            'email' => mb_strtolower(trim((string) $validated['email'])),
            'is_active' => (bool) $validated['is_active'],
            'receive_inbound_replies' => (bool) $validated['receive_inbound_replies'],
            'receive_scheduled_reports' => (bool) $validated['receive_scheduled_reports'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(TeamMember $teamMember): array
    {
        return [
            'team_member' => $teamMember,
            'receive_inbound_replies' => $this->explicitEmailPreference(
                $teamMember,
                TeamMemberNotificationPreference::TYPE_INBOUND_REPLIES,
            ),
            'receive_scheduled_reports' => $this->explicitEmailPreference(
                $teamMember,
                TeamMemberNotificationPreference::TYPE_SCHEDULED_REPORT,
            ),
        ];
    }

    private function explicitEmailPreference(
        TeamMember $teamMember,
        string $type,
    ): bool {
        $preference = $teamMember->notificationPreferences
            ->first(fn (TeamMemberNotificationPreference $preference): bool =>
                $preference->channel === TeamMemberNotificationPreference::CHANNEL_EMAIL
                && $preference->purpose === $type
                && $preference->scope === null
            );

        return $preference?->is_enabled === true;
    }
}