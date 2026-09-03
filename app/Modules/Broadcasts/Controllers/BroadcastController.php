<?php

namespace App\Modules\Broadcasts\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Broadcasts\Actions\CancelBroadcastAction;
use App\Modules\Broadcasts\Actions\DuplicateBroadcastAction;
use App\Modules\Broadcasts\Actions\ScheduleBroadcastAction;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Broadcasts\Requests\StoreBroadcastRequest;
use App\Modules\Broadcasts\Requests\PreviewBroadcastAudienceRequest;
use App\Modules\Broadcasts\Requests\SaveBroadcastMessageTemplateRequest;
use App\Modules\Broadcasts\Requests\UpdateBroadcastRequest;
use App\Modules\Broadcasts\Services\BroadcastRecipientResolver;
use App\Modules\Broadcasts\Services\BroadcastAudiencePreviewService;
use App\Modules\Broadcasts\Services\BroadcastMessageTemplateVersionService;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Services\Contacts\ContactFilterResolver;
use App\Modules\Core\Support\Contacts\ContactFilterCriterionRegistry;
use App\Modules\Messaging\Actions\CreateReusableMessageTemplateAction;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringContext;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\MessageTemplateAuthoringFieldPresenter;
use App\Modules\Messaging\Services\ReusableMessageTemplateCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;

class BroadcastController extends Controller
{
    public function __construct(
        private readonly ContactFilterResolver $contactFilterResolver,
        private readonly ContactFilterCriterionRegistry $contactFilterCriteria,
        private readonly BroadcastRecipientResolver $broadcastRecipientResolver,
        private readonly BroadcastAudiencePreviewService $broadcastAudiencePreview,
        private readonly MessageChannelAvailability $messageChannelAvailability,
        private readonly ReusableMessageTemplateCatalog $reusableMessageTemplates,
        private readonly MessageTemplateAuthoringFieldPresenter $messageTemplateAuthoringFields,
    ) {}

    public function index(Request $request): View
    {
        $broadcasts = Broadcast::query()
            ->with(['messageTemplate.currentVersion', 'messageTemplateVersion'])
            ->latest()
            ->limit(50)
            ->get();

        return view('crm.broadcasts.index', [
            'title' => 'Broadcasts',
            'heading' => 'Broadcasts',
            'broadcasts' => $broadcasts,
            'availableBroadcastChannels' => $this->availableRegularBroadcastChannels(),
            'reusableMessageTemplates' => $this->reusableMessageTemplates->definitions(
                channels: $this->availableRegularBroadcastChannels(),
                purpose: 'marketing',
                selectionContext: 'broadcasts',
            ),
            'broadcastMessageFields' => $this->messageTemplateAuthoringFields->groupsForContext(
                Broadcast::DEFAULT_DISPATCH_KEY,
            ),
            'audienceCriteria' => $this->contactFilterCriteria->definitions(),
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
        BroadcastMessageTemplateVersionService $messageTemplates,
    ): RedirectResponse {
        $broadcast = DB::transaction(function () use ($request, $messageTemplates): Broadcast {
            $broadcast = Broadcast::query()->create($request->broadcastAttributes());

            $messageTemplates->saveDraft(
                broadcast: $broadcast,
                payload: $request->messagePayload(),
                createdBy: $request->user(),
            );

            return $broadcast->refresh();
        }, 3);

        if ($request->shouldSchedule()) {
            if ($broadcast->isPermissionInvitation()) {
                $preview = $this->permissionInvitationPreview($broadcast);

                if (($preview['eligible_contacts_count'] ?? 0) < 1) {
                    return redirect()
                        ->route('crm.broadcasts.show', $broadcast)
                        ->with('error', 'No imported contacts are currently eligible for this opt-in invitation.');
                }
            }

            try {
                $broadcast = $scheduleBroadcastAction->handle($broadcast);
            } catch (InvalidArgumentException $exception) {
                return redirect()
                    ->route('crm.broadcasts.edit', $broadcast)
                    ->with('error', $exception->getMessage());
            }

            return $this->scheduledBroadcastRedirect($broadcast);
        }

        return redirect()
            ->route('crm.broadcasts.show', $broadcast)
            ->with('success', $broadcast->isPermissionInvitation()
                ? 'Opt-in invitation draft saved.'
                : 'Broadcast draft saved.');
    }

    public function previewAudience(PreviewBroadcastAudienceRequest $request): JsonResponse
    {
        return response()->json(
            $this->broadcastAudiencePreview->preview($request->recipientFilter()),
        );
    }

    public function saveMessageTemplate(
        SaveBroadcastMessageTemplateRequest $request,
        Broadcast $broadcast,
        CreateReusableMessageTemplateAction $createReusableMessageTemplate,
    ): RedirectResponse {
        if (! $broadcast->isRegularBroadcast()) {
            return redirect()
                ->route('crm.broadcasts.show', $broadcast)
                ->with('error', 'Opt-in invitations cannot be saved as reusable Broadcast messages.');
        }

        try {
            $createReusableMessageTemplate->handle(
                name: $request->templateName(),
                channel: (string) $broadcast->channel,
                payload: $broadcast->messagePayload(),
                context: $this->reusableMessageTemplateContext($broadcast),
                createdBy: $request->user(),
            );
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->route('crm.broadcasts.show', $broadcast)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('crm.broadcasts.show', $broadcast)
            ->with('success', 'Message saved to Message Templates. It is now available when composing future Broadcasts.');
    }

    public function duplicate(
        Request $request,
        Broadcast $broadcast,
        DuplicateBroadcastAction $duplicateBroadcast,
    ): RedirectResponse {
        if (! $broadcast->isRegularBroadcast()) {
            return redirect()
                ->route('crm.broadcasts.show', $broadcast)
                ->with('error', 'Opt-in invitations cannot be duplicated as regular Broadcasts.');
        }

        $copy = $duplicateBroadcast->handle(
            broadcast: $broadcast,
            actor: $request->user(),
        );

        return redirect()
            ->route('crm.broadcasts.edit', $copy)
            ->with('success', 'New Broadcast draft created from this message. Choose who should receive it before scheduling.');
    }

    public function show(Request $request, Broadcast $broadcast): View
    {
        $broadcast->loadCount([
            'recipients',
            'recipients as pending_recipients_count' => fn ($query) => $query->where('status', BroadcastRecipient::STATUS_PENDING),
            'recipients as scheduled_recipients_count' => fn ($query) => $query->where('status', BroadcastRecipient::STATUS_SCHEDULED),
            'recipients as sent_recipients_count' => fn ($query) => $query->where('status', BroadcastRecipient::STATUS_SENT),
            'recipients as skipped_recipients_count' => fn ($query) => $query->where('status', BroadcastRecipient::STATUS_SKIPPED),
            'recipients as failed_recipients_count' => fn ($query) => $query->where('status', BroadcastRecipient::STATUS_FAILED),
            'recipients as cancelled_recipients_count' => fn ($query) => $query->where('status', BroadcastRecipient::STATUS_CANCELLED),
        ]);

        $recipientStatuses = [
            BroadcastRecipient::STATUS_PENDING,
            BroadcastRecipient::STATUS_SCHEDULED,
            BroadcastRecipient::STATUS_SENT,
            BroadcastRecipient::STATUS_SKIPPED,
            BroadcastRecipient::STATUS_FAILED,
            BroadcastRecipient::STATUS_CANCELLED,
        ];
        $recipientStatus = $request->query('recipient_status');
        $recipientStatus = is_string($recipientStatus)
            && in_array($recipientStatus, $recipientStatuses, true)
                ? $recipientStatus
                : null;

        $recipients = $broadcast->recipients()
            ->with('contact')
            ->when(
                $recipientStatus !== null,
                fn ($query) => $query->where('status', $recipientStatus),
            )
            ->orderBy('id')
            ->paginate(50, ['*'], 'recipient_page')
            ->withQueryString();

        $deliveryIssues = $broadcast->recipients()
            ->with('contact')
            ->whereIn('status', [
                BroadcastRecipient::STATUS_SKIPPED,
                BroadcastRecipient::STATUS_FAILED,
            ])
            ->latest('id')
            ->limit(10)
            ->get();

        $selectedDeliveryIssue = null;
        $selectedDeliveryIssueMessages = collect();
        $selectedDeliveryIssueId = $request->integer('delivery_issue');

        if ($selectedDeliveryIssueId > 0) {
            $selectedDeliveryIssue = $broadcast->recipients()
                ->with('contact')
                ->whereKey($selectedDeliveryIssueId)
                ->whereIn('status', [
                    BroadcastRecipient::STATUS_SKIPPED,
                    BroadcastRecipient::STATUS_FAILED,
                ])
                ->first();

            if ($selectedDeliveryIssue instanceof BroadcastRecipient
                && is_numeric($selectedDeliveryIssue->scheduled_message_id)
            ) {
                $scheduledMessage = ScheduledMessage::query()
                    ->with([
                        'terminalOutboxEvent.deliveryAttempt',
                        'deliveryAttempts',
                    ])
                    ->whereKey($selectedDeliveryIssue->scheduled_message_id)
                    ->where('context_type', $broadcast->getMorphClass())
                    ->where('context_id', $broadcast->getKey())
                    ->first();

                if ($scheduledMessage instanceof ScheduledMessage) {
                    $selectedDeliveryIssueMessages = collect([$scheduledMessage]);
                }
            }
        }

        return view('crm.broadcasts.show', [
            'title' => $broadcast->name,
            'heading' => $broadcast->name,
            'broadcast' => $broadcast,
            'recipients' => $recipients,
            'recipientStatus' => $recipientStatus,
            'recipientFilterContacts' => $this->recipientFilterContacts($broadcast),
            'selectedImportBatches' => $this->selectedImportBatches($broadcast),
            'permissionInvitationPreview' => $this->permissionInvitationPreview($broadcast),
            'deliveryIssues' => $deliveryIssues,
            'selectedDeliveryIssue' => $selectedDeliveryIssue,
            'selectedDeliveryIssueMessages' => $selectedDeliveryIssueMessages,
            'broadcastCta' => $this->broadcastPrimaryCta($broadcast),
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
            'availableBroadcastChannels' => $this->availableRegularBroadcastChannels($broadcast->channel),
            'reusableMessageTemplates' => $this->reusableMessageTemplates->definitions(
                channels: $this->availableRegularBroadcastChannels($broadcast->channel),
                purpose: 'marketing',
                selectionContext: 'broadcasts',
            ),
            'broadcastMessageFields' => $broadcast->isRegularBroadcast()
                ? $this->messageTemplateAuthoringFields->groupsForContext(Broadcast::DEFAULT_DISPATCH_KEY)
                : [],
            'audienceCriteria' => $this->contactFilterCriteria->definitions(),
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
        BroadcastMessageTemplateVersionService $messageTemplates,
    ): RedirectResponse {
        if ($broadcast->status !== Broadcast::STATUS_DRAFT) {
            return redirect()
                ->route('crm.broadcasts.show', $broadcast)
                ->with('error', 'Only draft broadcasts can be edited.');
        }

        DB::transaction(function () use ($request, $broadcast, $messageTemplates): void {
            $broadcast->forceFill($request->broadcastAttributes())->save();
            $messageTemplates->saveDraft(
                broadcast: $broadcast,
                payload: $request->messagePayload(),
                createdBy: $request->user(),
            );
        }, 3);

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

        try {
            $broadcast = $scheduleBroadcastAction->handle($broadcast);
        } catch (InvalidArgumentException $exception) {
            return redirect()
                ->route('crm.broadcasts.edit', $broadcast)
                ->with('error', $exception->getMessage());
        }

        return $this->scheduledBroadcastRedirect($broadcast);
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
    private function selectedImportBatches(Broadcast $broadcast): Collection
    {
        $recipientFilter = $broadcast->recipient_filter ?? [];

        if (($recipientFilter['type'] ?? null) !== 'import_batch') {
            return new Collection();
        }

        $importBatchIds = $this->selectedImportBatchIds($recipientFilter['import_batch_ids'] ?? []);

        if ($importBatchIds === []) {
            return new Collection();
        }

        return ContactImportBatch::query()
            ->whereIn('id', $importBatchIds)
            ->orderByRaw('FIELD(id, '.implode(',', $importBatchIds).')')
            ->get();
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


    private function reusableMessageTemplateContext(
        Broadcast $broadcast,
    ): ReusableMessageTemplateAuthoringContext {
        $channel = (string) $broadcast->channel;

        return new ReusableMessageTemplateAuthoringContext(
            contextKey: 'broadcasts',
            purpose: (string) $broadcast->purpose,
            scope: (string) $broadcast->scope,
            dispatchKey: (string) $broadcast->dispatch_key,
            messageType: is_string($broadcast->message_type) ? $broadcast->message_type : null,
            payloadClass: (string) $broadcast->payload_class,
            queue: is_string($broadcast->queue) ? $broadcast->queue : null,
            moduleKey: 'broadcasts',
            moduleLabel: 'Broadcasts',
            surface: 'broadcasts',
            groupKey: 'saved_broadcast_messages_'.strtolower($channel),
            groupLabel: 'Saved Broadcast Messages — '.($channel === 'sms' ? 'SMS' : 'Email'),
            usageType: 'broadcast_reuse',
            selectionContexts: ['broadcasts', 'campaign_annual_touch'],
            description: 'Reusable CRM-authored Broadcast message.',
        );
    }


    /** @return array{label: string, url: string}|null */
    private function broadcastPrimaryCta(Broadcast $broadcast): ?array
    {
        if (! $broadcast->isRegularBroadcast() || $broadcast->channel !== 'email') {
            return null;
        }

        $cta = $broadcast->messagePayload()['cta'] ?? null;

        if (! is_array($cta)) {
            return null;
        }

        $label = is_string($cta['label'] ?? null) ? trim($cta['label']) : '';
        $url = is_string($cta['url'] ?? null) ? trim($cta['url']) : '';

        if ($label === '' || $url === '') {
            return null;
        }

        return [
            'label' => $label,
            'url' => $url,
        ];
    }


    private function scheduledBroadcastRedirect(Broadcast $broadcast): RedirectResponse
    {
        $redirect = redirect()->route('crm.broadcasts.show', $broadcast);

        if ($broadcast->isPermissionInvitation()) {
            return $redirect->with(
                'success',
                'Opt-in invitation scheduled. Each imported contact can only receive this invitation once.',
            );
        }

        return match (data_get($broadcast->meta, 'scheduling.outcome')) {
            'no_eligible_recipients' => $redirect->with(
                'error',
                'No recipients matched this Broadcast audience.',
            ),
            'no_messages_scheduled' => $redirect->with(
                'error',
                'No messages were scheduled. None of the selected recipients could receive this message. Review the recipient reasons below.',
            ),
            default => $redirect->with(
                'success',
                'Broadcast scheduled. Immediate sends use a 5-minute safety buffer.',
            ),
        };
    }

    /**
     * @return array<int, string>
     */
    private function availableRegularBroadcastChannels(?string $currentChannel = null): array
    {
        $channels = $this->messageChannelAvailability->visibleChannelsForSurface(
            surface: 'broadcasts',
            purpose: 'marketing',
            scope: 'broadcast',
            requireProvider: false,
        );

        if ($channels === []) {
            $channels = ['email'];
        }

        if ($currentChannel !== null && ! in_array($currentChannel, $channels, true)) {
            $channels[] = $currentChannel;
        }

        return array_values(array_unique($channels));
    }
}