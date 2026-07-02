<?php

namespace App\Modules\Broadcasts\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Broadcasts\Actions\CancelBroadcastAction;
use App\Modules\Broadcasts\Actions\ScheduleBroadcastAction;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Broadcasts\Requests\StoreBroadcastRequest;
use App\Modules\Broadcasts\Requests\UpdateBroadcastRequest;
use App\Modules\Broadcasts\Services\BroadcastRecipientResolver;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Services\Contacts\ContactFilterResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class BroadcastController extends Controller
{
    public function __construct(
        private readonly ContactFilterResolver $contactFilterResolver,
        private readonly BroadcastRecipientResolver $broadcastRecipientResolver,
    ) {}

    public function index(Request $request): View
    {
        $broadcasts = Broadcast::query()
            ->latest()
            ->limit(50)
            ->get();

        return view('crm.broadcasts.index', [
            'title' => 'Broadcasts',
            'heading' => 'Broadcasts',
            'broadcasts' => $broadcasts,
            'permissionInvitationPreview' => $this->newPermissionInvitationPreview($request),
            'importBatches' => $this->importBatches(),
            'selectedImportBatchIds' => $this->selectedImportBatchIds($request->session()->getOldInput('import_batch_ids', [])),
            'regularBroadcasts' => $broadcasts
                ->filter(fn (Broadcast $broadcast): bool => $broadcast->isRegularBroadcast())
                ->values(),
            'permissionInvitationBroadcasts' => $broadcasts
                ->filter(fn (Broadcast $broadcast): bool => $broadcast->isPermissionInvitation())
                ->values(),
            'selectedRecipientContacts' => $this->selectedContactOptions(
                $request->session()->getOldInput('contact_ids', []),
            ),
            'excludableBroadcasts' => $broadcasts
                ->filter(fn (Broadcast $broadcast): bool => $broadcast->isRegularBroadcast()
                    && in_array($broadcast->status, [
                        Broadcast::STATUS_SCHEDULED,
                        Broadcast::STATUS_SENDING,
                        Broadcast::STATUS_COMPLETED,
                    ], true))
                ->values(),
        ]);
    }

    public function store(
        StoreBroadcastRequest $request,
        ScheduleBroadcastAction $scheduleBroadcastAction,
    ): RedirectResponse {
        $broadcast = Broadcast::query()->create($request->broadcastAttributes());

        if ($request->shouldSchedule()) {
            if ($broadcast->isPermissionInvitation()) {
                $preview = $this->permissionInvitationPreview($broadcast);

                if (($preview['eligible_contacts_count'] ?? 0) < 1) {
                    return redirect()
                        ->route('crm.broadcasts.show', $broadcast)
                        ->with('error', 'No imported contacts are currently eligible for this opt-in invitation.');
                }
            }

            $broadcast = $scheduleBroadcastAction->handle($broadcast);

            return redirect()
                ->route('crm.broadcasts.show', $broadcast)
                ->with('success', $broadcast->isPermissionInvitation()
                    ? 'Opt-in invitation scheduled. Each imported contact can only receive this invitation once.'
                    : 'Broadcast scheduled. Immediate sends use a 5-minute safety buffer.');
        }

        return redirect()
            ->route('crm.broadcasts.show', $broadcast)
            ->with('success', $broadcast->isPermissionInvitation()
                ? 'Opt-in invitation draft saved.'
                : 'Broadcast draft saved.');
    }

    public function show(Broadcast $broadcast): View
    {
        $broadcast->loadCount([
            'recipients',
            'recipients as sent_recipients_count' => fn ($query) => $query->where('status', BroadcastRecipient::STATUS_SENT),
            'recipients as skipped_recipients_count' => fn ($query) => $query->where('status', BroadcastRecipient::STATUS_SKIPPED),
            'recipients as failed_recipients_count' => fn ($query) => $query->where('status', BroadcastRecipient::STATUS_FAILED),
            'recipients as cancelled_recipients_count' => fn ($query) => $query->where('status', BroadcastRecipient::STATUS_CANCELLED),
        ]);

        $recipients = $broadcast->recipients()
            ->with('contact')
            ->orderBy('id')
            ->limit(250)
            ->get();

        $scheduledMessages = $broadcast->scheduledMessages()
            ->latest()
            ->limit(250)
            ->get();

        return view('crm.broadcasts.show', [
            'title' => $broadcast->name,
            'heading' => $broadcast->name,
            'broadcast' => $broadcast,
            'recipients' => $recipients,
            'recipientFilterContacts' => $this->recipientFilterContacts($broadcast),
            'permissionInvitationPreview' => $this->permissionInvitationPreview($broadcast),
            'scheduledMessages' => $scheduledMessages,
        ]);
    }

    public function edit(Broadcast $broadcast): View|RedirectResponse
    {
        if ($broadcast->status !== Broadcast::STATUS_DRAFT) {
            return redirect()
                ->route('crm.broadcasts.show', $broadcast)
                ->with('error', 'Only draft broadcasts can be edited.');
        }

        return view('crm.broadcasts.edit', [
            'title' => $broadcast->isPermissionInvitation() ? 'Edit Opt-In Invitation' : 'Edit Broadcast',
            'heading' => $broadcast->isPermissionInvitation() ? 'Edit Opt-In Invitation' : 'Edit Broadcast',
            'broadcast' => $broadcast,
            'selectedRecipientContacts' => $this->selectedContactOptions(
                session()->getOldInput('contact_ids', $broadcast->recipient_filter['contact_ids'] ?? []),
            ),
            'excludableBroadcasts' => Broadcast::query()
                ->whereKeyNot($broadcast->getKey())
                ->where('message_type', '!=', Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION)
                ->whereIn('status', [
                    Broadcast::STATUS_SCHEDULED,
                    Broadcast::STATUS_SENDING,
                    Broadcast::STATUS_COMPLETED,
                ])
                ->latest()
                ->limit(50)
                ->get(['id', 'name', 'channel', 'status', 'send_at']),
            'permissionInvitationPreview' => $this->permissionInvitationPreview($broadcast),
            'importBatches' => $this->importBatches(),
            'selectedImportBatchIds' => $this->selectedImportBatchIds(
                session()->getOldInput('import_batch_ids', $broadcast->recipient_filter['import_batch_ids'] ?? []),
            ),
        ]);
    }

    public function update(
        UpdateBroadcastRequest $request,
        Broadcast $broadcast,
    ): RedirectResponse {
        if ($broadcast->status !== Broadcast::STATUS_DRAFT) {
            return redirect()
                ->route('crm.broadcasts.show', $broadcast)
                ->with('error', 'Only draft broadcasts can be edited.');
        }

        $broadcast->forceFill($request->broadcastAttributes())->save();

        return redirect()
            ->route('crm.broadcasts.show', $broadcast)
            ->with('success', $broadcast->isPermissionInvitation()
                ? 'Opt-in invitation draft updated.'
                : 'Broadcast draft updated.');
    }

    public function schedule(
        Request $request,
        Broadcast $broadcast,
        ScheduleBroadcastAction $scheduleBroadcastAction,
    ): RedirectResponse {
        if ($broadcast->status !== Broadcast::STATUS_DRAFT) {
            return redirect()
                ->route('crm.broadcasts.show', $broadcast)
                ->with('error', 'Only draft broadcasts can be scheduled.');
        }

        $validated = $request->validate([
            'send_at' => ['nullable', 'date'],
        ]);

        $broadcast->forceFill([
            'send_at' => $validated['send_at'] ?? $broadcast->send_at,
        ])->save();

        if ($broadcast->isPermissionInvitation()) {
            $preview = $this->permissionInvitationPreview($broadcast);

            if (($preview['eligible_contacts_count'] ?? 0) < 1) {
                return redirect()
                    ->route('crm.broadcasts.show', $broadcast)
                    ->with('error', 'No imported contacts are currently eligible for this opt-in invitation.');
            }
        }

        $broadcast = $scheduleBroadcastAction->handle($broadcast);

        return redirect()
            ->route('crm.broadcasts.show', $broadcast)
            ->with('success', $broadcast->isPermissionInvitation()
                ? 'Opt-in invitation scheduled. Each imported contact can only receive this invitation once.'
                : 'Broadcast scheduled. Immediate sends use a 5-minute safety buffer.');
    }

    public function cancel(
        Broadcast $broadcast,
        CancelBroadcastAction $cancelBroadcastAction,
    ): RedirectResponse {
        if (in_array($broadcast->status, [
            Broadcast::STATUS_COMPLETED,
            Broadcast::STATUS_CANCELLED,
        ], true)) {
            return redirect()
                ->route('crm.broadcasts.show', $broadcast)
                ->with('error', 'This broadcast is already terminal.');
        }

        $broadcast = $cancelBroadcastAction->handle($broadcast);

        return redirect()
            ->route('crm.broadcasts.show', $broadcast)
            ->with('success', $broadcast->isPermissionInvitation()
                ? 'Opt-in invitation cancelled.'
                : 'Broadcast cancelled.');
    }


    /**
     * @return array{
     *     imported_contacts_count: int,
     *     already_consented_count: int,
     *     already_invited_count: int,
     *     ineligible_contacts_count: int,
     *     eligible_contacts_count: int,
     *     excluded_by_prior_broadcast_count: int
     * }|null
     */
    private function permissionInvitationPreview(Broadcast $broadcast): ?array
    {
        if (! $broadcast->isPermissionInvitation()) {
            return null;
        }

        return $this->broadcastRecipientResolver->permissionInvitationPreview($broadcast);
    }

    /**
     * @return array{
     *     imported_contacts_count: int,
     *     already_consented_count: int,
     *     already_invited_count: int,
     *     ineligible_contacts_count: int,
     *     eligible_contacts_count: int,
     *     excluded_by_prior_broadcast_count: int
     * }
     */
    private function newPermissionInvitationPreview(Request $request): array
    {
        $broadcast = new Broadcast([
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'permission_invitation',
            'dispatch_key' => Broadcast::PERMISSION_INVITATION_DISPATCH_KEY,
            'message_type' => Broadcast::MESSAGE_TYPE_IMPORTED_CONTACT_PERMISSION_INVITATION,
            'recipient_filter' => $this->permissionInvitationRecipientFilterFromOldInput($request),
            'send_at' => Carbon::now()->addMinutes(5),
            'meta' => [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_PERMISSION_INVITATION,
            ],
        ]);

        return $this->broadcastRecipientResolver->permissionInvitationPreview($broadcast);
    }

    /**
     * @param array<int, mixed> $contactIds
     * @return Collection<int, Contact>
     */
    private function selectedContactOptions(array $contactIds): Collection
    {
        return $this->contactFilterResolver->resolve([
            'type' => 'contact_ids',
            'contact_ids' => $contactIds,
        ]);
    }

    /**
     * @return Collection<int, Contact>
     */
    private function recipientFilterContacts(Broadcast $broadcast): Collection
    {
        $recipientFilter = $broadcast->recipient_filter ?? [];

        if (($recipientFilter['type'] ?? null) !== 'contact_ids') {
            return new Collection();
        }

        return $this->selectedContactOptions($recipientFilter['contact_ids'] ?? []);
    }

    /**
     * @return Collection<int, ContactImportBatch>
     */
    private function importBatches(): Collection
    {
        return ContactImportBatch::query()
            ->latest('imported_at')
            ->latest()
            ->limit(50)
            ->get();
    }

    /**
     * @return array<int, int>
     */
    private function selectedImportBatchIds(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null,
            $values,
        ), fn (?int $value): bool => $value !== null && $value > 0)));
    }

    /**
     * @return array<string, mixed>
     */
    private function permissionInvitationRecipientFilterFromOldInput(Request $request): array
    {
        $type = $request->session()->getOldInput('recipient_filter_type', 'imported');

        if ($type !== 'import_batch') {
            return [
                'type' => 'imported',
            ];
        }

        $importBatchIds = $this->selectedImportBatchIds(
            $request->session()->getOldInput('import_batch_ids', []),
        );

        return $importBatchIds === []
            ? ['type' => 'imported']
            : [
                'type' => 'import_batch',
                'import_batch_ids' => $importBatchIds,
            ];
    }
}