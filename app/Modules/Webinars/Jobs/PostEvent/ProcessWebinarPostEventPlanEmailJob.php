<?php

namespace App\Modules\Webinars\Jobs\PostEvent;

use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarPostEventPlanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Handles delayed jobs queued by the earlier fixed post-event plan. */
final class ProcessWebinarPostEventPlanEmailJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $registrationId, public string $activation) {}

    public function handle(WebinarPostEventPlanService $plans): void
    {
        $registration = WebinarRegistration::query()->with('webinar.webinarSeries')->find($this->registrationId);
        $webinar = $registration?->webinar;
        $plan = $webinar ? $plans->activeFor($webinar) : null;

        if (! is_array($plan) || ($plan['activated_at'] ?? null) !== $this->activation) {
            return;
        }

        foreach ($plans->rules($plan) as $id => $rule) {
            if (($rule['enabled'] ?? false) !== true || ($rule['channel'] ?? null) !== 'email'
                || ($rule['trigger'] ?? null) !== 'webinar.ended') {
                continue;
            }

            ProcessWebinarPostEventPlanRuleJob::dispatch(
                $this->registrationId,
                $this->activation,
                (string) $id,
                (int) ($rule['revision'] ?? 1),
                now()->toIso8601String(),
            )->onQueue('post_event');
        }
    }
}