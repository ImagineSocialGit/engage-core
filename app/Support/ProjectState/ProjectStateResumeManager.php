<?php

namespace App\Support\ProjectState;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\FlowRoutes\Jobs\ContinueFlowRouteProgressJob;
use App\Modules\FlowRoutes\Jobs\ResumeFlowRouteProgressJob;
use App\Modules\Messaging\Jobs\ProcessMessageChainEnrollmentJob;
use App\Modules\Messaging\Jobs\PublishScheduledMessageOutboxEventsJob;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use App\Modules\Messaging\Services\ScheduledMessageDeliveryPolicy;
use App\Modules\Messaging\Services\ScheduledMessageEventOutbox;
use App\Modules\Webinars\Actions\QueueWebinarRegistrationFinalizationAction;
use App\Modules\Webinars\Data\WebinarRegistrationFinalizationResult;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Support\AutomationEvents\Jobs\PublishAutomationEventOutboxEventsJob;
use App\Support\Queues\QueueContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

class ProjectStateResumeManager
{
    public const STATE_PENDING = 'pending';
    public const STATE_COMPLETED = 'completed';

    public const CATEGORY_MESSAGE_CHAINS = 'message_chain_enrollments';
    public const CATEGORY_BROADCASTS = 'broadcasts';
    public const CATEGORY_FLOW_ROUTES = 'flow_routes';
    public const CATEGORY_WEBINAR_FINALIZATIONS = 'webinar_finalizations';
    public const CATEGORY_SCHEDULED_MESSAGES = 'scheduled_messages';
    public const CATEGORY_MESSAGE_DELIVERIES = 'message_deliveries';
    public const CATEGORY_SCHEDULED_MESSAGE_OUTBOX = 'scheduled_message_outbox';
    public const CATEGORY_AUTOMATION_EVENTS = 'automation_events';

    public function __construct(
        private readonly QueueContract $queueContract,
        private readonly ScheduledMessageDeliveryPolicy $deliveryPolicy,
        private readonly ScheduledMessageEventOutbox $scheduledMessageEventOutbox,
        private readonly QueueWebinarRegistrationFinalizationAction $queueWebinarFinalization,
    ) {}

    /**
     * @return array<string, array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     dependencies: array<int, string>,
     *     pending_count: int,
     *     completed_count: int,
     *     blocked_by: array<int, string>,
     * }>
     */
    public function summary(): array
    {
        $definitions = self::categoryDefinitions();
        $counts = [];

        if (Schema::hasTable('project_state_resume_items')) {
            $rows = DB::table('project_state_resume_items')
                ->select('category', 'state', DB::raw('COUNT(*) as aggregate'))
                ->groupBy('category', 'state')
                ->get();

            foreach ($rows as $row) {
                $counts[(string) $row->category][(string) $row->state] = (int) $row->aggregate;
            }
        }

        $summary = [];

        foreach ($definitions as $key => $definition) {
            $pendingCount = (int) ($counts[$key][self::STATE_PENDING] ?? 0);
            $completedCount = (int) ($counts[$key][self::STATE_COMPLETED] ?? 0);
            $blockedBy = [];

            foreach ($definition['dependencies'] as $dependency) {
                if ((int) ($counts[$dependency][self::STATE_PENDING] ?? 0) > 0) {
                    $blockedBy[] = $dependency;
                }
            }

            $summary[$key] = [
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'dependencies' => $definition['dependencies'],
                'pending_count' => $pendingCount,
                'completed_count' => $completedCount,
                'blocked_by' => $blockedBy,
            ];
        }

        return $summary;
    }

    /**
     * @return array{
     *     category: string,
     *     label: string,
     *     processed: int,
     *     outcomes: array<string, int>,
     *     pending_count: int,
     * }
     */
    public function resume(string $category): array
    {
        $category = trim($category);
        $definitions = self::categoryDefinitions();

        if (! array_key_exists($category, $definitions)) {
            throw new InvalidArgumentException(
                "Unknown project-state resume category [{$category}].",
            );
        }

        if (! Schema::hasTable('project_state_resume_items')) {
            throw new RuntimeException(
                'Project-state resume tracking is unavailable until its migration has been applied.',
            );
        }

        $summary = $this->summary();
        $blockedBy = $summary[$category]['blocked_by'];

        if ($blockedBy !== []) {
            $labels = array_map(
                fn (string $dependency): string => $definitions[$dependency]['label'],
                $blockedBy,
            );

            throw new InvalidArgumentException(sprintf(
                'Resume [%s] after completing: %s.',
                $definitions[$category]['label'],
                implode(', ', $labels),
            ));
        }

        try {
            $outcomes = match ($category) {
                self::CATEGORY_MESSAGE_CHAINS => $this->resumeMessageChains(),
                self::CATEGORY_BROADCASTS => $this->resumeBroadcasts(),
                self::CATEGORY_FLOW_ROUTES => $this->resumeFlowRoutes(),
                self::CATEGORY_WEBINAR_FINALIZATIONS => $this->resumeWebinarFinalizations(),
                self::CATEGORY_SCHEDULED_MESSAGES => $this->resumeScheduledMessages(),
                self::CATEGORY_MESSAGE_DELIVERIES => $this->resumeMessageDeliveries(),
                self::CATEGORY_SCHEDULED_MESSAGE_OUTBOX => $this->resumeScheduledMessageOutbox(),
                self::CATEGORY_AUTOMATION_EVENTS => $this->resumeAutomationEvents(),
            };
        } catch (Throwable $exception) {
            throw new RuntimeException(
                sprintf(
                    'Project-state resume [%s] failed: %s',
                    $definitions[$category]['label'],
                    $exception->getMessage(),
                ),
                previous: $exception,
            );
        }

        $processed = array_sum($outcomes);
        $pendingCount = (int) DB::table('project_state_resume_items')
            ->where('category', $category)
            ->where('state', self::STATE_PENDING)
            ->count();

        return [
            'category' => $category,
            'label' => $definitions[$category]['label'],
            'processed' => $processed,
            'outcomes' => $outcomes,
            'pending_count' => $pendingCount,
        ];
    }

    /** @return array<string, int> */
    private function resumeMessageChains(): array
    {
        $outcomes = [];

        foreach ($this->pendingItems(self::CATEGORY_MESSAGE_CHAINS) as $item) {
            $dispatchId = DB::transaction(function () use ($item, &$outcomes): ?int {
                $enrollment = MessageChainEnrollment::query()
                    ->lockForUpdate()
                    ->find($this->recordId($item));

                if (! $enrollment instanceof MessageChainEnrollment) {
                    $this->completeItem($item->id, 'missing');
                    $this->increment($outcomes, 'missing');

                    return null;
                }

                if ($enrollment->status === MessageChainEnrollment::STATUS_PAUSED) {
                    $enrollment->forceFill([
                        'status' => MessageChainEnrollment::STATUS_ACTIVE,
                        'paused_at' => null,
                        'resumed_at' => now(),
                    ])->save();
                } elseif ($enrollment->status !== MessageChainEnrollment::STATUS_ACTIVE) {
                    $this->completeItem($item->id, 'no_longer_resumable');
                    $this->increment($outcomes, 'no_longer_resumable');

                    return null;
                }

                $isDue = $enrollment->status === MessageChainEnrollment::STATUS_ACTIVE
                    && $enrollment->next_action_at?->lessThanOrEqualTo(now());

                if (! $isDue) {
                    $this->completeItem($item->id, 'activated');
                    $this->increment($outcomes, 'activated');

                    return null;
                }

                return (int) $enrollment->getKey();
            });

            if ($dispatchId === null) {
                continue;
            }

            try {
                Bus::dispatch(new ProcessMessageChainEnrollmentJob($dispatchId));
                $this->completeItem($item->id, 'activated_and_queued');
                $this->increment($outcomes, 'activated_and_queued');
            } catch (Throwable $exception) {
                report($exception);
                $this->increment($outcomes, 'queue_failed');
            }
        }

        return $outcomes;
    }

    /** @return array<string, int> */
    private function resumeBroadcasts(): array
    {
        $outcomes = [];

        foreach ($this->pendingItems(self::CATEGORY_BROADCASTS) as $item) {
            DB::transaction(function () use ($item, &$outcomes): void {
                $broadcast = Broadcast::query()
                    ->lockForUpdate()
                    ->find($this->recordId($item));

                if (! $broadcast instanceof Broadcast) {
                    $this->completeItem($item->id, 'missing');
                    $this->increment($outcomes, 'missing');

                    return;
                }

                if ($broadcast->status === 'paused') {
                    $broadcast->forceFill([
                        'status' => Broadcast::STATUS_SCHEDULED,
                    ])->save();
                } elseif ($broadcast->status !== Broadcast::STATUS_SCHEDULED) {
                    $this->completeItem($item->id, 'no_longer_resumable');
                    $this->increment($outcomes, 'no_longer_resumable');

                    return;
                }

                $this->completeItem($item->id, 'restored_scheduled');
                $this->increment($outcomes, 'restored_scheduled');
            });
        }

        return $outcomes;
    }

    /** @return array<string, int> */
    private function resumeScheduledMessages(): array
    {
        $outcomes = [];

        foreach ($this->pendingItems(self::CATEGORY_SCHEDULED_MESSAGES) as $item) {
            $dispatch = DB::transaction(function () use ($item, &$outcomes): ?array {
                $message = ScheduledMessage::query()
                    ->lockForUpdate()
                    ->find($this->recordId($item));

                if (! $message instanceof ScheduledMessage) {
                    $this->completeItem($item->id, 'missing');
                    $this->increment($outcomes, 'missing');

                    return null;
                }

                if (! in_array($message->status, ['paused', ScheduledMessage::STATUS_PENDING], true)) {
                    $this->completeItem($item->id, 'no_longer_pending');
                    $this->increment($outcomes, 'no_longer_pending');

                    return null;
                }

                $queue = $this->queueContract->assertDispatchable($message->queue);

                if ($message->status === 'paused') {
                    $message->forceFill([
                        'status' => ScheduledMessage::STATUS_PENDING,
                    ])->save();
                }

                return [
                    'id' => (int) $message->getKey(),
                    'queue' => $queue,
                    'delay' => $message->send_at?->isFuture()
                        ? $message->send_at
                        : null,
                ];
            });

            if (! is_array($dispatch)) {
                continue;
            }

            try {
                $this->dispatchScheduledMessage($dispatch);
                $this->completeItem($item->id, 'queued');
                $this->increment($outcomes, 'queued');
            } catch (Throwable $exception) {
                report($exception);
                $this->increment($outcomes, 'queue_failed');
            }
        }

        return $outcomes;
    }

    /** @return array<string, int> */
    private function resumeMessageDeliveries(): array
    {
        $outcomes = [];

        foreach ($this->pendingItems(self::CATEGORY_MESSAGE_DELIVERIES) as $item) {
            $dispatch = DB::transaction(function () use ($item, &$outcomes): ?array {
                $message = ScheduledMessage::query()
                    ->lockForUpdate()
                    ->find($this->recordId($item));

                if (! $message instanceof ScheduledMessage) {
                    $this->completeItem($item->id, 'missing');
                    $this->increment($outcomes, 'missing');

                    return null;
                }

                if ($message->status === ScheduledMessage::STATUS_PENDING) {
                    return $this->messageDispatchPayload($message);
                }

                if (! in_array($message->status, ['paused', ScheduledMessage::STATUS_SENDING], true)) {
                    $this->completeItem($item->id, 'no_longer_in_flight');
                    $this->increment($outcomes, 'no_longer_in_flight');

                    return null;
                }

                $attempt = ScheduledMessageDeliveryAttempt::query()
                    ->where('scheduled_message_id', $message->getKey())
                    ->orderByDesc('attempt_number')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if (! $attempt instanceof ScheduledMessageDeliveryAttempt) {
                    $resolvedAt = now();
                    $attempt = ScheduledMessageDeliveryAttempt::query()->create([
                        'scheduled_message_id' => $message->getKey(),
                        'attempt_number' => max(
                            1,
                            ((int) ScheduledMessageDeliveryAttempt::query()
                                ->where('scheduled_message_id', $message->getKey())
                                ->max('attempt_number')) + 1,
                        ),
                        'claim_token' => (string) Str::uuid(),
                        'status' => ScheduledMessageDeliveryAttempt::STATUS_FAILED,
                        'claimed_at' => $resolvedAt,
                        'lease_expires_at' => $resolvedAt,
                        'completed_at' => $resolvedAt,
                        'reason_code' => 'project_state_import_missing_delivery_attempt',
                        'reason' => 'Imported sending message had no delivery attempt and was not resent automatically.',
                    ]);

                    $message->forceFill([
                        'status' => ScheduledMessage::STATUS_FAILED,
                    ])->save();

                    $this->scheduledMessageEventOutbox->record(
                        scheduledMessage: $message,
                        eventType: ScheduledMessage::STATUS_FAILED,
                        occurredAt: $attempt->completed_at,
                        deliveryAttempt: $attempt,
                        reasonCode: $attempt->reason_code,
                        reason: $attempt->reason,
                    );

                    $this->completeItem($item->id, 'failed_missing_attempt');
                    $this->increment($outcomes, 'failed_missing_attempt');

                    return null;
                }

                $attempt->setRelation('scheduledMessage', $message);
                $canRetry = $this->deliveryPolicy
                    ->canSafelyRetryProviderSubmission($attempt);
                $resolvedAt = now();

                if (! $canRetry) {
                    $reason = 'Delivery outcome is unknown after project-state import; automatic resend was blocked by the provider idempotency policy.';

                    $message->forceFill([
                        'status' => ScheduledMessage::STATUS_FAILED,
                    ])->save();

                    $attempt->forceFill([
                        'status' => ScheduledMessageDeliveryAttempt::STATUS_FAILED,
                        'completed_at' => $resolvedAt,
                        'reason_code' => 'project_state_import_provider_outcome_unknown',
                        'reason' => $reason,
                    ])->save();

                    $this->scheduledMessageEventOutbox->record(
                        scheduledMessage: $message,
                        eventType: ScheduledMessage::STATUS_FAILED,
                        occurredAt: $resolvedAt,
                        deliveryAttempt: $attempt,
                        reasonCode: $attempt->reason_code,
                        reason: $reason,
                    );

                    $this->completeItem($item->id, 'failed_ambiguous_submission');
                    $this->increment($outcomes, 'failed_ambiguous_submission');

                    return null;
                }

                $message->forceFill([
                    'status' => ScheduledMessage::STATUS_PENDING,
                ])->save();

                $attempt->forceFill([
                    'status' => ScheduledMessageDeliveryAttempt::STATUS_RECOVERED,
                    'completed_at' => $resolvedAt,
                    'reason_code' => 'project_state_import_claim_recovered',
                    'reason' => 'Imported delivery claim was safely recovered for retry.',
                ])->save();

                return $this->messageDispatchPayload($message);
            });

            if (! is_array($dispatch)) {
                continue;
            }

            try {
                $this->dispatchScheduledMessage($dispatch);
                $this->completeItem($item->id, 'requeued');
                $this->increment($outcomes, 'requeued');
            } catch (Throwable $exception) {
                report($exception);
                $this->increment($outcomes, 'queue_failed');
            }
        }

        return $outcomes;
    }

    /** @return array<string, int> */
    private function resumeScheduledMessageOutbox(): array
    {
        $outcomes = [];
        $resumedItemIds = [];

        DB::transaction(function () use (&$outcomes, &$resumedItemIds): void {
            foreach ($this->pendingItems(self::CATEGORY_SCHEDULED_MESSAGE_OUTBOX, lock: true) as $item) {
                $event = DB::table('scheduled_message_outbox_events')
                    ->where('id', $this->recordId($item))
                    ->lockForUpdate()
                    ->first();

                if (! is_object($event)) {
                    $this->completeItem($item->id, 'missing');
                    $this->increment($outcomes, 'missing');
                    continue;
                }

                if ($event->status === 'paused') {
                    DB::table('scheduled_message_outbox_events')
                        ->where('id', $event->id)
                        ->update([
                            'status' => 'pending',
                            'claim_token' => null,
                            'claim_expires_at' => null,
                            'updated_at' => now(),
                        ]);
                } elseif ($event->status !== 'pending') {
                    $this->completeItem($item->id, 'no_longer_paused');
                    $this->increment($outcomes, 'no_longer_paused');
                    continue;
                }

                $resumedItemIds[] = (int) $item->id;
            }
        });

        if ($resumedItemIds !== []) {
            try {
                Bus::dispatch(new PublishScheduledMessageOutboxEventsJob());

                DB::table('project_state_resume_items')
                    ->whereIn('id', $resumedItemIds)
                    ->update([
                        'state' => self::STATE_COMPLETED,
                        'result_code' => 'released_for_publication',
                        'resumed_at' => now(),
                        'updated_at' => now(),
                    ]);

                $outcomes['released_for_publication'] = count($resumedItemIds);
            } catch (Throwable $exception) {
                report($exception);
                $outcomes['queue_failed'] = count($resumedItemIds);
            }
        }

        return $outcomes;
    }

    /** @return array<string, int> */
    private function resumeAutomationEvents(): array
    {
        $outcomes = [];
        $resumedItemIds = [];

        DB::transaction(function () use (&$outcomes, &$resumedItemIds): void {
            foreach ($this->pendingItems(self::CATEGORY_AUTOMATION_EVENTS, lock: true) as $item) {
                $event = DB::table('automation_event_outbox_events')
                    ->where('id', $this->recordId($item))
                    ->lockForUpdate()
                    ->first();

                if (! is_object($event)) {
                    $this->completeItem($item->id, 'missing');
                    $this->increment($outcomes, 'missing');
                    continue;
                }

                if ($event->status === 'paused') {
                    DB::table('automation_event_outbox_events')
                        ->where('id', $event->id)
                        ->update([
                            'status' => 'pending',
                            'claim_token' => null,
                            'claim_expires_at' => null,
                            'updated_at' => now(),
                        ]);
                } elseif ($event->status !== 'pending') {
                    $this->completeItem($item->id, 'no_longer_paused');
                    $this->increment($outcomes, 'no_longer_paused');
                    continue;
                }

                $resumedItemIds[] = (int) $item->id;
            }
        });

        if ($resumedItemIds !== []) {
            try {
                Bus::dispatch(new PublishAutomationEventOutboxEventsJob());

                DB::table('project_state_resume_items')
                    ->whereIn('id', $resumedItemIds)
                    ->update([
                        'state' => self::STATE_COMPLETED,
                        'result_code' => 'released_for_publication',
                        'resumed_at' => now(),
                        'updated_at' => now(),
                    ]);

                $outcomes['released_for_publication'] = count($resumedItemIds);
            } catch (Throwable $exception) {
                report($exception);
                $outcomes['queue_failed'] = count($resumedItemIds);
            }
        }

        return $outcomes;
    }

    /** @return array<string, int> */
    private function resumeWebinarFinalizations(): array
    {
        $outcomes = [];

        foreach ($this->pendingItems(self::CATEGORY_WEBINAR_FINALIZATIONS) as $item) {
            $registrationId = $this->recordId($item);
            $ready = DB::transaction(function () use ($item, $registrationId, &$outcomes): bool {
                $registration = WebinarRegistration::query()
                    ->lockForUpdate()
                    ->find($registrationId);

                if (! $registration instanceof WebinarRegistration) {
                    $this->completeItem($item->id, 'missing');
                    $this->increment($outcomes, 'missing');

                    return false;
                }

                $meta = is_array($registration->meta) ? $registration->meta : [];
                $state = data_get(
                    $meta,
                    WebinarRegistrationFinalizationResult::META_KEY,
                );

                if (! is_array($state)) {
                    $this->completeItem($item->id, 'finalization_missing');
                    $this->increment($outcomes, 'finalization_missing');

                    return false;
                }

                if (($state['status'] ?? null) === 'paused') {
                    $resumedAt = now()->toISOString();
                    $state = array_replace($state, [
                        'status' => 'pending',
                        'queue_token' => null,
                        'queued_at' => null,
                        'processing_started_at' => null,
                        'next_retry_at' => null,
                        'last_state_changed_at' => $resumedAt,
                        'project_state_resumed_at' => $resumedAt,
                    ]);

                    data_set(
                        $meta,
                        WebinarRegistrationFinalizationResult::META_KEY,
                        $state,
                    );

                    $registration->forceFill(['meta' => $meta])->save();
                }

                return true;
            });

            if (! $ready) {
                continue;
            }

            try {
                $result = $this->queueWebinarFinalization->handle($registrationId);

                if ($result->inProgress()) {
                    $this->completeItem($item->id, 'queued');
                    $this->increment($outcomes, 'queued');
                    continue;
                }

                if ($result->complete()
                    || $result->status === WebinarRegistrationFinalizationResult::STATUS_FAILED
                    || $result->requiresReconciliation()
                ) {
                    $this->completeItem($item->id, 'no_longer_resumable');
                    $this->increment($outcomes, 'no_longer_resumable');
                    continue;
                }

                $this->increment($outcomes, 'queue_deferred');
            } catch (Throwable $exception) {
                report($exception);
                $this->increment($outcomes, 'queue_failed');
            }
        }

        return $outcomes;
    }

    /** @return array<string, int> */
    private function resumeFlowRoutes(): array
    {
        $outcomes = [];

        foreach ($this->pendingItems(self::CATEGORY_FLOW_ROUTES) as $item) {
            $dispatch = DB::transaction(function () use ($item, &$outcomes): ?array {
                $progressId = $this->recordId($item);
                $progress = DB::table('contact_flow_route_progress')
                    ->where('id', $progressId)
                    ->lockForUpdate()
                    ->first();

                if (! is_object($progress)) {
                    $this->completeItem($item->id, 'missing');
                    $this->increment($outcomes, 'missing');

                    return null;
                }

                $originalStatus = (string) $item->original_status;

                if (! in_array($originalStatus, ['active', 'waiting'], true)) {
                    $this->completeItem($item->id, 'unsupported_original_status');
                    $this->increment($outcomes, 'unsupported_original_status');

                    return null;
                }

                if (! in_array((string) $progress->status, ['paused', $originalStatus], true)) {
                    $meta = $this->decodedJson($progress->meta ?? null);
                    $this->forgetProjectStateOriginalStatus($meta);

                    DB::table('contact_flow_route_progress')
                        ->where('id', $progressId)
                        ->update([
                            'meta' => $this->encodedJson($meta),
                            'updated_at' => now(),
                        ]);

                    $this->completeItem($item->id, 'no_longer_resumable');
                    $this->increment($outcomes, 'no_longer_resumable');

                    return null;
                }

                $this->restoreFlowRouteTableRows(
                    table: 'contact_flow_route_plans',
                    progressId: $progressId,
                );
                $this->restoreFlowRouteTableRows(
                    table: 'contact_flow_route_plan_items',
                    progressId: $progressId,
                );
                $this->restoreFlowRouteTableRows(
                    table: 'contact_flow_route_progress_items',
                    progressId: $progressId,
                );

                $meta = $this->decodedJson($progress->meta ?? null);
                $this->forgetProjectStateOriginalStatus($meta);

                DB::table('contact_flow_route_progress')
                    ->where('id', $progressId)
                    ->update([
                        'status' => $originalStatus,
                        'meta' => $this->encodedJson($meta),
                        'updated_at' => now(),
                    ]);

                if ($originalStatus === 'active') {
                    return [
                        'type' => 'continue',
                        'id' => $progressId,
                    ];
                }

                $resumeAt = $this->nullableCarbon($progress->resume_at ?? null);

                if ($resumeAt === null) {
                    $this->completeItem($item->id, 'restored_event_wait');
                    $this->increment($outcomes, 'restored_event_wait');

                    return null;
                }

                return [
                    'type' => 'resume',
                    'id' => $progressId,
                    'delay' => $resumeAt->isFuture() ? $resumeAt : null,
                ];
            });

            if (! is_array($dispatch)) {
                continue;
            }

            try {
                if ($dispatch['type'] === 'continue') {
                    Bus::dispatch(new ContinueFlowRouteProgressJob($dispatch['id']));
                    $this->completeItem($item->id, 'restored_and_queued');
                    $this->increment($outcomes, 'restored_and_queued');
                    continue;
                }

                $job = new ResumeFlowRouteProgressJob($dispatch['id']);

                if ($dispatch['delay'] !== null) {
                    $job->delay($dispatch['delay']);
                }

                Bus::dispatch($job);
                $this->completeItem($item->id, 'restored_wait_and_queued');
                $this->increment($outcomes, 'restored_wait_and_queued');
            } catch (Throwable $exception) {
                report($exception);
                $this->increment($outcomes, 'queue_failed');
            }
        }

        return $outcomes;
    }

    /** @return array<int, string> */
    public static function supportedCategoryKeys(): array
    {
        return array_keys(self::categoryDefinitions());
    }

    /**
     * @return array<string, array{
     *     label: string,
     *     description: string,
     *     dependencies: array<int, string>,
     * }>
     */
    private static function categoryDefinitions(): array
    {
        return [
            self::CATEGORY_MESSAGE_CHAINS => [
                'label' => 'Message-chain enrollments',
                'description' => 'Reactivate imported enrollments and queue only those already due.',
                'dependencies' => [],
            ],
            self::CATEGORY_BROADCASTS => [
                'label' => 'Interrupted Broadcasts',
                'description' => 'Restore Broadcasts that were mid-send to their supported scheduled state before requeuing recipient messages.',
                'dependencies' => [],
            ],
            self::CATEGORY_FLOW_ROUTES => [
                'label' => 'FlowRoute progress',
                'description' => 'Restore imported Route, plan, and item states and recreate continuation or timed-wait jobs.',
                'dependencies' => [
                    self::CATEGORY_MESSAGE_CHAINS,
                ],
            ],
            self::CATEGORY_WEBINAR_FINALIZATIONS => [
                'label' => 'Webinar registration finalization',
                'description' => 'Reset stale queue claims and explicitly queue provider synchronization and message planning.',
                'dependencies' => [
                    self::CATEGORY_MESSAGE_CHAINS,
                ],
            ],
            self::CATEGORY_SCHEDULED_MESSAGES => [
                'label' => 'Pending scheduled messages',
                'description' => 'Restore pending messages and recreate their delayed Horizon jobs, including Broadcast messages.',
                'dependencies' => [
                    self::CATEGORY_MESSAGE_CHAINS,
                    self::CATEGORY_BROADCASTS,
                ],
            ],
            self::CATEGORY_MESSAGE_DELIVERIES => [
                'label' => 'Interrupted message deliveries',
                'description' => 'Recover safe claims and block ambiguous provider submissions from blind resending.',
                'dependencies' => [
                    self::CATEGORY_MESSAGE_CHAINS,
                    self::CATEGORY_BROADCASTS,
                ],
            ],
            self::CATEGORY_SCHEDULED_MESSAGE_OUTBOX => [
                'label' => 'Scheduled-message terminal events',
                'description' => 'Release imported terminal outbox events only after their message-chain and Broadcast owners are active.',
                'dependencies' => [
                    self::CATEGORY_MESSAGE_CHAINS,
                    self::CATEGORY_BROADCASTS,
                ],
            ],
            self::CATEGORY_AUTOMATION_EVENTS => [
                'label' => 'Automation events',
                'description' => 'Release imported automation-event envelopes after FlowRoute waiting state has been restored.',
                'dependencies' => [
                    self::CATEGORY_MESSAGE_CHAINS,
                    self::CATEGORY_FLOW_ROUTES,
                ],
            ],
        ];
    }

    /** @return Collection<int, object> */
    private function pendingItems(string $category, bool $lock = false): Collection
    {
        $query = DB::table('project_state_resume_items')
            ->where('category', $category)
            ->where('state', self::STATE_PENDING)
            ->orderBy('id')
            ->limit($this->batchSize());

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    private function completeItem(int|string $itemId, string $resultCode): void
    {
        DB::table('project_state_resume_items')
            ->where('id', $itemId)
            ->where('state', self::STATE_PENDING)
            ->update([
                'state' => self::STATE_COMPLETED,
                'result_code' => $resultCode,
                'resumed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function recordId(object $item): int
    {
        if (! is_numeric($item->source_record_id ?? null)) {
            throw new RuntimeException(sprintf(
                'Project-state resume item [%s] has a non-numeric record ID.',
                (string) ($item->id ?? 'unknown'),
            ));
        }

        return (int) $item->source_record_id;
    }

    /** @param array{id: int, queue: string, delay: mixed} $dispatch */
    private function dispatchScheduledMessage(array $dispatch): void
    {
        $job = (new SendScheduledMessageJob($dispatch['id']))
            ->onQueue($dispatch['queue']);

        if ($dispatch['delay'] !== null) {
            $job->delay($dispatch['delay']);
        }

        Bus::dispatch($job);
    }

    /** @return array{id: int, queue: string, delay: mixed} */
    private function messageDispatchPayload(ScheduledMessage $message): array
    {
        return [
            'id' => (int) $message->getKey(),
            'queue' => $this->queueContract->assertDispatchable($message->queue),
            'delay' => $message->send_at?->isFuture()
                ? $message->send_at
                : null,
        ];
    }

    private function restoreFlowRouteTableRows(
        string $table,
        int $progressId,
    ): void {
        $rows = DB::table($table)
            ->where('contact_flow_route_progress_id', $progressId)
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            $meta = $this->decodedJson($row->meta ?? null);
            $originalStatus = Arr::get(
                $meta,
                'project_state.original_status',
            );

            if (! is_string($originalStatus) || trim($originalStatus) === '') {
                continue;
            }

            $this->forgetProjectStateOriginalStatus($meta);
            $updates = [
                'meta' => $this->encodedJson($meta),
                'updated_at' => now(),
            ];

            if ((string) ($row->status ?? '') === 'paused') {
                $updates['status'] = $originalStatus;
            }

            DB::table($table)
                ->where('id', $row->id)
                ->update($updates);
        }
    }

    /** @param array<string, mixed> $meta */
    private function forgetProjectStateOriginalStatus(array &$meta): void
    {
        Arr::forget($meta, 'project_state.original_status');

        if (Arr::get($meta, 'project_state') === []) {
            Arr::forget($meta, 'project_state');
        }
    }

    /** @return array<string, mixed> */
    private function decodedJson(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        try {
            $decoded = json_decode(
                (string) $value,
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'Imported project-state JSON could not be resumed.',
                previous: $exception,
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $value */
    private function encodedJson(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR,
        );
    }

    private function nullableCarbon(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function batchSize(): int
    {
        return min(
            5000,
            max(1, (int) config('project_state.resume_batch_size', 500)),
        );
    }

    /** @param array<string, int> $outcomes */
    private function increment(array &$outcomes, string $key): void
    {
        $outcomes[$key] = ($outcomes[$key] ?? 0) + 1;
    }
}