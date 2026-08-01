<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ProcessMessageChainEnrollmentAction;
use App\Modules\Messaging\Actions\StartMessageChainEnrollmentAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Services\MessageChainTimingResolver;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarScheduleProfileChainBinding;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use App\Modules\Webinars\Services\WebinarMessageAreaRegistry;
use App\Modules\Webinars\Services\WebinarMessageChainExecutionContextProvider;
use App\Modules\Webinars\Services\WebinarScheduleProfileResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

class StartWebinarMessageChainEnrollmentAction
{
    public function __construct(
        private readonly StartMessageChainEnrollmentAction $startEnrollment,
        private readonly ProcessMessageChainEnrollmentAction $processEnrollment,
        private readonly WebinarScheduleProfileResolver $scheduleProfileResolver,
        private readonly WebinarMessageAreaRegistry $messageAreaRegistry,
        private readonly WebinarMessageChainExecutionContextProvider $executionContextProvider,
        private readonly MessageChainTimingResolver $timingResolver,
    ) {}

    public function handle(
        Webinar $webinar,
        string $messageAreaKey,
        Contact $recipient,
        WebinarRegistration|WebinarWaitlistSignup $context,
        Carbon|string|null $startedAt = null,
        bool $required = true,
    ): ?MessageChainEnrollment {
        $messageArea = $this->messageAreaRegistry->get($messageAreaKey);

        if (! $messageArea?->enabled || ! $messageArea->isTemplate()) {
            return $this->unavailable(
                required: $required,
                message: "Webinar message area [{$messageAreaKey}] is not enabled for chain enrollment.",
            );
        }

        $this->assertContextMatchesWebinar(
            context: $context,
            webinar: $webinar,
        );
        $startedAt = ($startedAt ? Carbon::parse($startedAt) : now())->utc();

        $profile = $this->scheduleProfileResolver->resolveForWebinar($webinar);

        if ($profile === null) {
            return $this->unavailable(
                required: $required,
                message: "Webinar [{$webinar->getKey()}] has no active schedule profile.",
            );
        }

        $binding = WebinarScheduleProfileChainBinding::query()
            ->with('messageChain.currentVersion.steps.variants')
            ->where('webinar_schedule_profile_id', $profile->getKey())
            ->where('message_area_key', $messageArea->key)
            ->where('is_active', true)
            ->first();

        if (! $binding instanceof WebinarScheduleProfileChainBinding) {
            return $this->unavailable(
                required: $required,
                message: sprintf(
                    'Webinar schedule profile [%s] has no active chain binding for message area [%s].',
                    $profile->key,
                    $messageArea->key,
                ),
            );
        }

        $chain = $binding->messageChain;

        if (! $chain instanceof MessageChain || ! $chain->isActive()) {
            return $this->unavailable(
                required: $required,
                message: sprintf(
                    'Webinar schedule profile [%s] message area [%s] has no active MessageChain.',
                    $profile->key,
                    $messageArea->key,
                ),
            );
        }

        $version = $chain->requireCurrentVersion();

        if (! $version->isPublished()) {
            throw new RuntimeException(
                "MessageChain [{$chain->key}] current version is not published.",
            );
        }

        $startStep = $this->startStepForArea(
            version: $version,
            areaKey: $messageArea->key,
            messageType: $messageArea->messageType,
            required: $required,
            skipPastSteps: $messageArea->key === 'reminders',
            contextValues: $this->executionContextProvider->valuesFor(
                webinar: $webinar,
                context: $context,
            ),
            baseAt: $startedAt,
            notBefore: now()->utc(),
        );

        if (! $startStep instanceof MessageChainStep) {
            return null;
        }

        $enrollment = $this->startEnrollment->handle(
            messageChain: $chain,
            recipient: $recipient,
            dedupeKey: $this->dedupeKey(
                webinar: $webinar,
                messageAreaKey: $messageArea->key,
                context: $context,
            ),
            context: $context,
            origin: $webinar,
            startedAt: $startedAt,
            surface: $binding->surface,
            startStepKey: $startStep->key,
        );

        if (
            $enrollment->isActive()
            && $enrollment->next_action_at !== null
            && ! $enrollment->next_action_at->isFuture()
        ) {
            $enrollment = $this->processEnrollment->handle($enrollment);
        }

        return $enrollment->fresh([
            'messageChainVersion.messageChain',
            'currentMessageChainStep.variants',
            'scheduledMessages',
        ]) ?? $enrollment;
    }

    private function startStepForArea(
        MessageChainVersion $version,
        string $areaKey,
        string $messageType,
        bool $required,
        bool $skipPastSteps,
        array $contextValues,
        Carbon $baseAt,
        Carbon $notBefore,
    ): ?MessageChainStep {
        $version->loadMissing('steps.variants');

        $step = $version->steps->first(function (
            MessageChainStep $candidate,
        ) use (
            $areaKey,
            $messageType,
            $skipPastSteps,
            $contextValues,
            $baseAt,
            $notBefore,
        ): bool {
            $belongsToArea = str_starts_with(
                $candidate->key,
                $areaKey.'_',
            ) || $candidate->variants->contains(
                fn (MessageChainStepVariant $variant): bool =>
                    (bool) $variant->is_active
                    && $variant->message_type === $messageType,
            );

            if (! $candidate->is_active || ! $belongsToArea) {
                return false;
            }

            if (! $skipPastSteps) {
                return true;
            }

            return $this->timingResolver->resolve(
                step: $candidate,
                context: $contextValues,
                baseAt: $baseAt,
            )->greaterThanOrEqualTo($notBefore);
        });

        if (! $step instanceof MessageChainStep) {
            return $this->unavailable(
                required: $required,
                message: sprintf(
                    'MessageChainVersion [%s] has no active step for Webinar message type [%s].',
                    $version->getKey(),
                    $messageType,
                ),
            );
        }

        return $step;
    }

    private function assertContextMatchesWebinar(
        WebinarRegistration|WebinarWaitlistSignup $context,
        Webinar $webinar,
    ): void {
        if ($context instanceof WebinarRegistration) {
            if ((int) $context->webinar_id !== (int) $webinar->getKey()) {
                throw new RuntimeException(
                    "WebinarRegistration [{$context->getKey()}] does not belong to Webinar [{$webinar->getKey()}].",
                );
            }

            return;
        }

        if ((int) $context->webinar_series_id !== (int) $webinar->webinar_series_id) {
            throw new RuntimeException(
                "WebinarWaitlistSignup [{$context->getKey()}] does not belong to Webinar series [{$webinar->webinar_series_id}].",
            );
        }
    }

    private function unavailable(
        bool $required,
        string $message,
    ): null {
        if ($required) {
            throw new RuntimeException($message);
        }

        return null;
    }

    private function dedupeKey(
        Webinar $webinar,
        string $messageAreaKey,
        Model $context,
    ): string {
        $material = implode('|', [
            $messageAreaKey,
            $context->getMorphClass(),
            $context->getKey(),
            $webinar->getMorphClass(),
            $webinar->getKey(),
        ]);

        return 'webinar_message_chain:'
            .$messageAreaKey
            .':'
            .hash('sha256', $material);
    }
}