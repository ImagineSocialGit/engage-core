<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Jobs\ProcessMessageChainEnrollmentJob;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Services\MessageChainExecutionContextResolver;
use App\Modules\Messaging\Services\MessageChainTimingResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

class StartMessageChainEnrollmentAction
{
    public function __construct(
        private readonly MessageChainExecutionContextResolver $contextResolver,
        private readonly MessageChainTimingResolver $timingResolver,
    ) {}

    public function handle(
        MessageChain $messageChain,
        Model $recipient,
        string $dedupeKey,
        ?Model $context = null,
        ?Model $origin = null,
        Carbon|string|null $startedAt = null,
    ): MessageChainEnrollment {
        $dedupeKey = $this->dedupeKey($dedupeKey);
        $startedAt = ($startedAt ? Carbon::parse($startedAt) : now())->utc();

        $result = DB::transaction(function () use (
            $messageChain,
            $recipient,
            $dedupeKey,
            $context,
            $origin,
            $startedAt,
        ): array {
            $chain = MessageChain::query()
                ->with('currentVersion.steps')
                ->whereKey($messageChain->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $chain->isActive()) {
                throw new RuntimeException(
                    "MessageChain [{$chain->key}] is not active.",
                );
            }

            $version = $chain->requireCurrentVersion();

            if (! $version->isPublished()) {
                throw new RuntimeException(
                    "MessageChain [{$chain->key}] current version is not published.",
                );
            }

            $existing = MessageChainEnrollment::query()
                ->where('dedupe_key', $dedupeKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof MessageChainEnrollment) {
                $this->assertExistingEnrollmentMatches(
                    enrollment: $existing,
                    chain: $chain,
                    recipient: $recipient,
                    context: $context,
                    origin: $origin,
                );

                return [
                    'enrollment' => $existing,
                    'dispatch' => false,
                ];
            }

            $firstStep = $this->firstActiveStep($version);

            $enrollment = MessageChainEnrollment::query()->create([
                'message_chain_version_id' => $version->getKey(),
                'recipient_type' => $recipient->getMorphClass(),
                'recipient_id' => $recipient->getKey(),
                'context_type' => $context?->getMorphClass(),
                'context_id' => $context?->getKey(),
                'origin_type' => $origin?->getMorphClass(),
                'origin_id' => $origin?->getKey(),
                'current_message_chain_step_id' => $firstStep?->getKey(),
                'next_action_at' => null,
                'status' => $firstStep instanceof MessageChainStep
                    ? MessageChainEnrollment::STATUS_ACTIVE
                    : MessageChainEnrollment::STATUS_COMPLETED,
                'dedupe_key' => $dedupeKey,
                'started_at' => $startedAt,
                'completed_at' => $firstStep instanceof MessageChainStep
                    ? null
                    : $startedAt,
            ]);

            $enrollment->setRelation('messageChainVersion', $version);
            $enrollment->setRelation('recipient', $recipient);
            $enrollment->setRelation('context', $context);
            $enrollment->setRelation('origin', $origin);
            $enrollment->setRelation('currentMessageChainStep', $firstStep);

            if ($firstStep instanceof MessageChainStep) {
                $enrollment->forceFill([
                    'next_action_at' => $this->timingResolver->resolve(
                        step: $firstStep,
                        context: $this->contextResolver->resolve($enrollment),
                        baseAt: $startedAt,
                    ),
                ])->save();
            }

            return [
                'enrollment' => $enrollment,
                'dispatch' => $enrollment->isActive()
                    && $enrollment->next_action_at !== null,
            ];
        }, 3);

        /** @var MessageChainEnrollment $enrollment */
        $enrollment = $result['enrollment'];

        if ($result['dispatch'] === true) {
            $this->dispatch($enrollment);
        }

        return $enrollment;
    }

    private function firstActiveStep(
        MessageChainVersion $version,
    ): ?MessageChainStep {
        $steps = $version->relationLoaded('steps')
            ? $version->getRelation('steps')
            : $version->steps()->get();

        return $steps
            ->first(
                fn (MessageChainStep $step): bool =>
                    (bool) $step->is_active,
            );
    }

    private function assertExistingEnrollmentMatches(
        MessageChainEnrollment $enrollment,
        MessageChain $chain,
        Model $recipient,
        ?Model $context,
        ?Model $origin,
    ): void {
        $version = $enrollment->messageChainVersion()
            ->with('messageChain')
            ->first();

        if (! $version instanceof MessageChainVersion
            || (int) $version->message_chain_id !== (int) $chain->getKey()
        ) {
            throw new LogicException(
                "Message-chain enrollment dedupe key [{$enrollment->dedupe_key}] belongs to a different chain.",
            );
        }

        foreach ([
            'recipient' => $recipient,
            'context' => $context,
            'origin' => $origin,
        ] as $relationship => $model) {
            $typeColumn = "{$relationship}_type";
            $idColumn = "{$relationship}_id";

            $expectedType = $model?->getMorphClass();
            $expectedId = $model?->getKey();

            if (
                $enrollment->{$typeColumn} !== $expectedType
                || (string) ($enrollment->{$idColumn} ?? '') !== (string) ($expectedId ?? '')
            ) {
                throw new LogicException(
                    "Message-chain enrollment dedupe key [{$enrollment->dedupe_key}] has conflicting {$relationship} identity.",
                );
            }
        }
    }

    private function dispatch(MessageChainEnrollment $enrollment): void
    {
        ProcessMessageChainEnrollmentJob::dispatch(
            enrollmentId: (int) $enrollment->getKey(),
        )->delay($enrollment->next_action_at);
    }

    private function dedupeKey(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(
                'Message-chain enrollment dedupe key is required.',
            );
        }

        if (mb_strlen($value) > 191) {
            throw new InvalidArgumentException(
                'Message-chain enrollment dedupe key cannot exceed 191 characters.',
            );
        }

        return $value;
    }
}