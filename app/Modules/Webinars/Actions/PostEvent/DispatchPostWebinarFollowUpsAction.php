<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Jobs\PostEvent\RoutePostWebinarRegistrationJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Messaging\Services\ConditionChecker;

class DispatchPostWebinarFollowUpsAction
{
    public function __construct(
        private readonly ConditionChecker $conditionChecker,
    ) {}

    public function execute(
        WebinarProvider $provider,
        Webinar $webinar,
        string $event,
    ): bool {
        if (! config('webinars.post_event.outcome_messages.enabled', true)) {
            return true;
        }

        $conditions = config('webinars.post_event.outcome_messages.conditions', []);

        if (
            is_array($conditions)
            && ! $this->conditionChecker->passes($conditions, $this->conditionContext($webinar, $event))
        ) {
            return false;
        }

        if (data_get($webinar->meta, 'normalized.post_event.follow_ups_dispatched_at')) {
            return true;
        }

        $webinar->registrations()
            ->pluck('id')
            ->each(function ($registrationId) use ($event) {
                RoutePostWebinarRegistrationJob::dispatch(
                    registrationId: $registrationId,
                    event: $event,
                )->onQueue('post_event');
            });

        $webinar->forceFill([
            'meta' => array_replace_recursive($webinar->fresh()->meta ?? [], [
                'normalized' => [
                    'post_event' => [
                        'follow_ups_dispatched_at' => now()->toIso8601String(),
                    ],
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