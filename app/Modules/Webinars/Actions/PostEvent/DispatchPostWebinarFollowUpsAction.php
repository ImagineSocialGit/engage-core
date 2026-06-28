<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Messaging\Services\ConditionChecker;
use App\Modules\Webinars\Actions\EmitWebinarAutomationEventAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Models\Webinar;

class DispatchPostWebinarFollowUpsAction
{
    public function __construct(
        private readonly ConditionChecker $conditionChecker,
        private readonly EmitWebinarAutomationEventAction $emitWebinarAutomationEvent,
    ) {}

    public function execute(
        WebinarProvider $provider,
        Webinar $webinar,
        string $event,
    ): bool {
        $conditions = config('webinars.post_event.outcome_messages.conditions', []);

        if (
            is_array($conditions)
            && ! $this->conditionChecker->passes($conditions, $this->conditionContext($webinar, $event))
        ) {
            return false;
        }

        if (data_get($webinar->meta, 'automation_events.webinar_ended_recorded_at')) {
            return true;
        }

        $this->emitWebinarAutomationEvent->forWebinar(
            eventKey: 'webinar.ended',
            webinar: $webinar,
            occurredAt: $webinar->ends_at ?? now(),
            payload: [
                'provider' => [
                    'key' => $provider->key(),
                ],
                'post_event' => [
                    'event' => $event,
                ],
            ],
        );

        $freshWebinar = $webinar->fresh();

        $freshWebinar?->forceFill([
            'meta' => array_replace_recursive($freshWebinar->meta ?? [], [
                'automation_events' => [
                    'webinar_ended_recorded_at' => now()->toIso8601String(),
                ],
            ]),
        ])->save();

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function conditionContext(Webinar $webinar, string $event): array
    {
        return [
            'event' => [
                'name' => $event,
            ],
            'webinar' => $webinar->toArray(),
        ];
    }
}