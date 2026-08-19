<?php

namespace App\Modules\Campaigns\Services;

use App\Models\User;
use App\Modules\Campaigns\Actions\EnrollContactInCampaignAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ProcessMessageChainEnrollmentAction;
use App\Modules\Messaging\Data\Delivery\ScheduledMessageTerminalResult;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Support\TestingTools\TestingToolGuard;
use App\Support\TestingTools\TestingToolRuntime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CampaignSimulationService
{
    public const TOOL_KEY = 'campaign_simulator';

    public const MESSAGE_CHAIN_SURFACE = 'testing:campaigns';

    private const MAX_PROCESS_ITERATIONS = 250;

    public function __construct(
        private readonly TestingToolGuard $guard,
        private readonly TestingToolRuntime $runtime,
        private readonly EnrollContactInCampaignAction $enrollContact,
        private readonly ProcessMessageChainEnrollmentAction $processMessageChain,
    ) {}

    public function start(
        Campaign $campaign,
        Contact $contact,
        CarbonInterface|string $fakeNow,
        ?User $user = null,
    ): CampaignEnrollment {
        $this->guard->assertAvailable();
        $fakeNow = $this->parseTime($fakeNow);

        $this->assertNoOpenEnrollmentCollision($campaign, $contact);

        $runId = (string) Str::uuid();

        return $this->runtime->runAt(
            $fakeNow,
            function () use ($campaign, $contact, $user, $runId, $fakeNow): CampaignEnrollment {
                return DB::transaction(function () use (
                    $campaign,
                    $contact,
                    $user,
                    $runId,
                    $fakeNow,
                ): CampaignEnrollment {
                    $enrollment = $this->enrollContact->handle(
                        contact: $contact,
                        campaignKey: (string) $campaign->key,
                        meta: [
                            'testing_tool' => [
                                'key' => self::TOOL_KEY,
                                'run_id' => $runId,
                                'created_by_user_id' => $user?->getKey(),
                                'started_at' => $fakeNow->copy()->utc()->toISOString(),
                                'current_at' => $fakeNow->copy()->utc()->toISOString(),
                            ],
                        ],
                    );

                    $chainEnrollment = MessageChainEnrollment::query()
                        ->lockForUpdate()
                        ->find($enrollment->message_chain_enrollment_id);

                    if (! $chainEnrollment instanceof MessageChainEnrollment) {
                        throw new RuntimeException(
                            'Campaign Simulator could not resolve the MessageChainEnrollment created by Campaign enrollment.',
                        );
                    }

                    $chainEnrollment->forceFill([
                        'surface' => self::MESSAGE_CHAIN_SURFACE,
                    ])->save();

                    $enrollment->setRelation('messageChainEnrollment', $chainEnrollment);

                    return $enrollment->refresh()->load('messageChainEnrollment');
                }, 3);
            },
        );
    }

    public function process(CampaignEnrollment $simulation): CampaignEnrollment
    {
        $simulation = $this->requireSimulation($simulation);
        $fakeNow = $this->currentAt($simulation);

        $this->guard->assertDevSinkDelivery();

        return $this->runtime->runAt(
            $fakeNow,
            function () use ($simulation, $fakeNow): CampaignEnrollment {
                $this->processFixedPoint((int) $simulation->message_chain_enrollment_id);
                $this->recordCurrentAt($simulation, $fakeNow);

                return $simulation->refresh()->load('messageChainEnrollment');
            },
        );
    }

    public function advance(
        CampaignEnrollment $simulation,
        CarbonInterface|string $target,
    ): CampaignEnrollment {
        $simulation = $this->requireSimulation($simulation);
        $target = $this->parseTime($target);
        $current = $this->currentAt($simulation);

        if ($target->lt($current)) {
            throw new RuntimeException(
                'Campaign Simulator time can only move forward. Reset the run to test an earlier timeline.',
            );
        }

        $this->recordCurrentAt($simulation, $target);

        return $simulation->refresh()->load('messageChainEnrollment');
    }

    public function advanceAndProcess(
        CampaignEnrollment $simulation,
        CarbonInterface|string $target,
    ): CampaignEnrollment {
        $simulation = $this->advance($simulation, $target);

        return $this->process($simulation);
    }

    public function nextEventAt(CampaignEnrollment $simulation): ?Carbon
    {
        $simulation = $this->requireSimulation($simulation);
        $current = $this->currentAt($simulation);
        $chainEnrollment = $simulation->messageChainEnrollment;

        if (! $chainEnrollment instanceof MessageChainEnrollment) {
            return null;
        }

        $candidates = collect();

        if ($chainEnrollment->status === MessageChainEnrollment::STATUS_ACTIVE
            && $chainEnrollment->next_action_at !== null
        ) {
            $candidates->push($chainEnrollment->next_action_at->copy());
        }

        ScheduledMessage::query()
            ->where('message_chain_enrollment_id', $chainEnrollment->getKey())
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->whereNotNull('send_at')
            ->pluck('send_at')
            ->each(fn (mixed $time) => $candidates->push(Carbon::parse($time)));

        if ($candidates->isEmpty()) {
            return null;
        }

        $dueNow = $candidates->first(
            fn (CarbonInterface $candidate): bool => $candidate->lte($current),
        );

        if ($dueNow instanceof CarbonInterface) {
            return $current->copy();
        }

        /** @var CarbonInterface|null $next */
        $next = $candidates->sortBy(fn (CarbonInterface $candidate): int => $candidate->getTimestamp())->first();

        return $next instanceof CarbonInterface
            ? Carbon::instance($next)
            : null;
    }

    /**
     * @return Collection<int, CampaignEnrollment>
     */
    public function runs(): Collection
    {
        $this->guard->assertAvailable();

        return CampaignEnrollment::query()
            ->with(['campaign', 'contact', 'messageChainEnrollment'])
            ->where('meta->testing_tool->key', self::TOOL_KEY)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(CampaignEnrollment $simulation): array
    {
        $simulation = $this->requireSimulation($simulation);
        $simulation->loadMissing(['campaign', 'contact', 'messageChainEnrollment']);

        $chainEnrollment = $simulation->messageChainEnrollment;

        if (! $chainEnrollment instanceof MessageChainEnrollment) {
            throw new RuntimeException('Campaign Simulator run has no linked MessageChainEnrollment.');
        }

        $chainEnrollment->load([
            'messageChainVersion.messageChain',
            'messageChainVersion.steps.variants.messageTemplateVersion.messageTemplate',
            'currentMessageChainStep',
            'scheduledMessages.messageChainStepVariant.messageChainStep',
            'scheduledMessages.latestDeliveryAttempt',
            'scheduledMessages.terminalOutboxEvent.deliveryAttempt',
        ]);

        $messages = $chainEnrollment->scheduledMessages
            ->map(fn (ScheduledMessage $message): array => $this->messageSnapshot($message))
            ->values();

        $messagesByVariant = $messages
            ->filter(fn (array $message): bool => $message['variant_id'] !== null)
            ->keyBy(fn (array $message): int => (int) $message['variant_id']);

        $steps = $chainEnrollment->messageChainVersion?->steps
            ?->map(function ($step) use ($messagesByVariant, $chainEnrollment): array {
                return [
                    'id' => (int) $step->getKey(),
                    'key' => (string) $step->key,
                    'name' => $step->name,
                    'sort_order' => (int) $step->sort_order,
                    'timing_type' => (string) $step->timing_type,
                    'offset_seconds' => (int) $step->offset_seconds,
                    'variant_strategy' => (string) $step->variant_strategy,
                    'advance_policy' => (string) $step->advance_policy,
                    'conditions' => is_array($step->conditions) ? $step->conditions : null,
                    'is_current' => (int) $chainEnrollment->current_message_chain_step_id === (int) $step->getKey(),
                    'variants' => $step->variants->map(function ($variant) use ($messagesByVariant): array {
                        return [
                            'id' => (int) $variant->getKey(),
                            'key' => (string) $variant->key,
                            'channel' => (string) $variant->channel,
                            'purpose' => (string) $variant->purpose,
                            'scope' => (string) $variant->scope,
                            'message_type' => (string) $variant->message_type,
                            'dependency_policy' => is_array($variant->dependency_policy)
                                ? $variant->dependency_policy
                                : null,
                            'conditions' => is_array($variant->conditions)
                                ? $variant->conditions
                                : null,
                            'materialized_message' => $messagesByVariant->get((int) $variant->getKey()),
                        ];
                    })->values()->all(),
                ];
            })
            ->values()
            ->all() ?? [];

        $currentAt = $this->currentAt($simulation);
        $nextEventAt = $this->nextEventAt($simulation);

        return [
            'run_id' => data_get($simulation->meta, 'testing_tool.run_id'),
            'campaign_enrollment_id' => (int) $simulation->getKey(),
            'campaign' => [
                'id' => (int) $simulation->campaign?->getKey(),
                'key' => $simulation->campaign?->key,
                'name' => $simulation->campaign?->name,
            ],
            'contact' => [
                'id' => (int) $simulation->contact?->getKey(),
                'name' => $this->contactLabel($simulation->contact),
                'email' => $simulation->contact?->email,
                'phone' => $simulation->contact?->phone,
            ],
            'fake_started_at' => $this->startedAt($simulation)->toISOString(),
            'fake_current_at' => $currentAt->toISOString(),
            'next_event_at' => $nextEventAt?->toISOString(),
            'chain' => [
                'enrollment_id' => (int) $chainEnrollment->getKey(),
                'surface' => (string) $chainEnrollment->surface,
                'status' => (string) $chainEnrollment->status,
                'version' => (int) ($chainEnrollment->messageChainVersion?->version ?? 0),
                'message_chain_name' => $chainEnrollment->messageChainVersion?->messageChain?->name,
                'current_step_id' => $chainEnrollment->current_message_chain_step_id,
                'current_step_name' => $chainEnrollment->currentMessageChainStep?->name
                    ?? $chainEnrollment->currentMessageChainStep?->key,
                'next_action_at' => $chainEnrollment->next_action_at?->toISOString(),
                'exit_reason_code' => $chainEnrollment->exit_reason_code,
                'completed_at' => $chainEnrollment->completed_at?->toISOString(),
                'cancelled_at' => $chainEnrollment->cancelled_at?->toISOString(),
                'exited_at' => $chainEnrollment->exited_at?->toISOString(),
            ],
            'steps' => $steps,
            'messages' => $messages->all(),
        ];
    }

    public function reset(CampaignEnrollment $simulation): void
    {
        $simulation = $this->requireSimulation($simulation);
        $chainEnrollmentId = (int) $simulation->message_chain_enrollment_id;

        DB::transaction(function () use ($simulation, $chainEnrollmentId): void {
            $lockedSimulation = CampaignEnrollment::query()
                ->lockForUpdate()
                ->find($simulation->getKey());

            if (! $lockedSimulation instanceof CampaignEnrollment
                || data_get($lockedSimulation->meta, 'testing_tool.key') !== self::TOOL_KEY
            ) {
                throw new RuntimeException('Campaign Simulator run is no longer available for reset.');
            }

            ScheduledMessage::query()
                ->where('message_chain_enrollment_id', $chainEnrollmentId)
                ->delete();

            $lockedSimulation->delete();

            MessageChainEnrollment::query()
                ->whereKey($chainEnrollmentId)
                ->where('surface', self::MESSAGE_CHAIN_SURFACE)
                ->delete();
        }, 3);
    }

    public function currentAt(CampaignEnrollment $simulation): Carbon
    {
        $value = data_get($simulation->meta, 'testing_tool.current_at');

        return is_string($value) && trim($value) !== ''
            ? Carbon::parse($value)->utc()
            : $this->startedAt($simulation);
    }

    public function startedAt(CampaignEnrollment $simulation): Carbon
    {
        $value = data_get($simulation->meta, 'testing_tool.started_at');

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value)->utc();
        }

        if ($simulation->started_at !== null) {
            return Carbon::instance($simulation->started_at)->utc();
        }

        throw new RuntimeException('Campaign Simulator run has no fake start time.');
    }

    public function parseTime(CarbonInterface|string $value): Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value)->utc();
        }

        $timezone = (string) config('client.timezone', config('app.timezone', 'UTC'));

        return Carbon::parse($value, $timezone)->utc();
    }

    private function processFixedPoint(int $chainEnrollmentId): void
    {
        for ($iteration = 1; $iteration <= self::MAX_PROCESS_ITERATIONS; $iteration++) {
            $changed = false;
            $chainEnrollment = MessageChainEnrollment::query()->find($chainEnrollmentId);

            if (! $chainEnrollment instanceof MessageChainEnrollment) {
                throw new RuntimeException('Campaign Simulator MessageChainEnrollment disappeared during processing.');
            }

            if ($chainEnrollment->status === MessageChainEnrollment::STATUS_ACTIVE
                && $chainEnrollment->next_action_at !== null
                && $chainEnrollment->next_action_at->lte(now())
            ) {
                $before = $this->chainStateFingerprint($chainEnrollment);
                $this->processMessageChain->handle($chainEnrollmentId);
                $after = MessageChainEnrollment::query()->findOrFail($chainEnrollmentId);

                $changed = $before !== $this->chainStateFingerprint($after);
            }

            $dueMessageIds = ScheduledMessage::query()
                ->where('message_chain_enrollment_id', $chainEnrollmentId)
                ->where('status', ScheduledMessage::STATUS_PENDING)
                ->where('send_at', '<=', now())
                ->orderBy('send_at')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            foreach ($dueMessageIds as $messageId) {
                $job = new SendScheduledMessageJob($messageId);

                try {
                    app()->call([$job, 'handle']);
                } catch (Throwable $exception) {
                    $message = ScheduledMessage::query()->find($messageId);

                    if (! $message instanceof ScheduledMessage
                        || ! in_array($message->status, [
                            ScheduledMessage::STATUS_SENT,
                            ScheduledMessage::STATUS_SKIPPED,
                            ScheduledMessage::STATUS_FAILED,
                        ], true)
                    ) {
                        throw $exception;
                    }
                }

                $changed = true;
            }

            if (! $changed) {
                return;
            }
        }

        throw new RuntimeException(sprintf(
            'Campaign Simulator exceeded %d synchronous processing iterations. Check the MessageChain for a zero-time progression loop.',
            self::MAX_PROCESS_ITERATIONS,
        ));
    }

    private function recordCurrentAt(
        CampaignEnrollment $simulation,
        CarbonInterface $currentAt,
    ): void {
        $meta = is_array($simulation->meta) ? $simulation->meta : [];
        $tool = is_array($meta['testing_tool'] ?? null) ? $meta['testing_tool'] : [];
        $tool['current_at'] = Carbon::instance($currentAt)->utc()->toISOString();
        $meta['testing_tool'] = $tool;

        CampaignEnrollment::query()
            ->whereKey($simulation->getKey())
            ->update([
                'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

        $simulation->forceFill(['meta' => $meta]);
    }

    private function requireSimulation(
        CampaignEnrollment $simulation,
    ): CampaignEnrollment {
        $this->guard->assertAvailable();

        $simulation = CampaignEnrollment::query()
            ->with('messageChainEnrollment')
            ->find($simulation->getKey());

        if (! $simulation instanceof CampaignEnrollment
            || data_get($simulation->meta, 'testing_tool.key') !== self::TOOL_KEY
            || ! $simulation->messageChainEnrollment instanceof MessageChainEnrollment
            || $simulation->messageChainEnrollment->surface !== self::MESSAGE_CHAIN_SURFACE
        ) {
            throw new RuntimeException('The selected enrollment is not a Campaign Simulator run.');
        }

        return $simulation;
    }

    private function assertNoOpenEnrollmentCollision(
        Campaign $campaign,
        Contact $contact,
    ): void {
        $existing = CampaignEnrollment::query()
            ->with('messageChainEnrollment')
            ->where('campaign_id', $campaign->getKey())
            ->where('contact_id', $contact->getKey())
            ->whereHas(
                'messageChainEnrollment',
                fn (Builder $query): Builder => $query->whereIn('status', [
                    MessageChainEnrollment::STATUS_ACTIVE,
                    MessageChainEnrollment::STATUS_PAUSED,
                ]),
            )
            ->latest('id')
            ->first();

        if (! $existing instanceof CampaignEnrollment) {
            return;
        }

        $kind = data_get($existing->meta, 'testing_tool.key') === self::TOOL_KEY
            ? 'another simulator run'
            : 'a real open Campaign enrollment';

        throw new RuntimeException(sprintf(
            'Contact [%d] already has %s for Campaign [%s]. Reset/cancel it before starting this simulation.',
            (int) $contact->getKey(),
            $kind,
            (string) $campaign->key,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function messageSnapshot(ScheduledMessage $message): array
    {
        $terminal = null;

        if (in_array($message->status, [
            ScheduledMessage::STATUS_SENT,
            ScheduledMessage::STATUS_SKIPPED,
            ScheduledMessage::STATUS_FAILED,
        ], true)) {
            $result = ScheduledMessageTerminalResult::fromScheduledMessage($message);
            $terminal = [
                'occurred_at' => $result->occurredAt->toISOString(),
                'provider' => $result->provider,
                'provider_message_id' => $result->providerMessageId,
                'reason_code' => $result->reasonCode,
                'reason' => $result->reason,
                'attempt_number' => $result->attemptNumber,
            ];
        }

        return [
            'id' => (int) $message->getKey(),
            'variant_id' => $message->message_chain_step_variant_id !== null
                ? (int) $message->message_chain_step_variant_id
                : null,
            'step_key' => $message->messageChainStepVariant?->messageChainStep?->key,
            'variant_key' => $message->messageChainStepVariant?->key,
            'channel' => (string) $message->channel,
            'purpose' => (string) $message->purpose,
            'scope' => (string) $message->scope,
            'message_type' => (string) $message->message_type,
            'send_at' => $message->send_at?->toISOString(),
            'status' => (string) $message->status,
            'terminal' => $terminal,
        ];
    }

    /** @return array<string, mixed> */
    private function chainStateFingerprint(MessageChainEnrollment $enrollment): array
    {
        return [
            'status' => $enrollment->status,
            'step' => $enrollment->current_message_chain_step_id,
            'next' => $enrollment->next_action_at?->toISOString(),
            'messages' => ScheduledMessage::query()
                ->where('message_chain_enrollment_id', $enrollment->getKey())
                ->orderBy('id')
                ->pluck('status', 'id')
                ->all(),
        ];
    }

    private function contactLabel(?Contact $contact): ?string
    {
        if (! $contact instanceof Contact) {
            return null;
        }

        foreach ([$contact->name, trim($contact->first_name.' '.$contact->last_name), $contact->email, $contact->phone] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return 'Contact #'.$contact->getKey();
    }
}