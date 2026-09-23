<?php

namespace App\Modules\Webinars\Jobs\PostEvent;

use App\Modules\Webinars\Actions\PostEvent\ScheduleWebinarPlanMessageAction;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarPostEventPlanService;
use App\Modules\Webinars\Services\WebinarPostEventSendConditionRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

final class ProcessWebinarPostEventPlanRuleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 48;

    public function __construct(
        public int $registrationId,
        public string $activation,
        public string $ruleId,
        public int $revision,
        public string $dueAt,
    ) {}

    public function retryUntil(): Carbon
    {
        return Carbon::parse($this->dueAt)->addDays(2);
    }

    public function handle(
        WebinarPostEventPlanService $plans,
        WebinarPostEventSendConditionRegistry $conditions,
        ScheduleWebinarPlanMessageAction $schedule,
    ): void {
        $registration = WebinarRegistration::query()->with('webinar.webinarSeries', 'contact')
            ->find($this->registrationId);
        $webinar = $registration?->webinar;

        if (! $webinar || ! $registration->contact || $registration->status === 'cancelled'
            || filled($registration->cancelled_at)) {
            return;
        }

        $plan = $plans->activeFor($webinar);
        $rule = is_array($plan) ? $plans->rule($plan, $this->ruleId) : null;

        if ($plan === null || ($plan['activated_at'] ?? null) !== $this->activation
            || $rule === null || ($rule['enabled'] ?? false) !== true
            || (int) ($rule['revision'] ?? 1) !== $this->revision) {
            return;
        }

        if (! data_get($webinar->meta, 'normalized.post_event.attendance_recorded_at')) {
            $this->release(300);

            return;
        }

        $lock = Cache::lock('webinar:plan:rule:'.$this->registrationId.':'.$this->ruleId, 120);

        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $outcome = filled($registration->attended_at) ? 'attended' : 'missed';
            if (($rule['outcome'] ?? 'any') !== 'any' && $rule['outcome'] !== $outcome) {
                return;
            }

            $matches = $conditions->matches(
                (string) $rule['send_condition'],
                $registration,
                $this->activation,
                $this->dueAt,
            );
            $templateKey = $plans->selectedTemplateKey($rule, $matches);

            if ($templateKey === null) {
                return;
            }

            $registration->setRelation('webinar', $webinar);
            $schedule->handle(
                registration: $registration,
                plan: $plan,
                ruleId: $this->ruleId,
                rule: $rule,
                templateKey: $templateKey,
                sendAt: now()->utc(),
            );
        } finally {
            $lock->release();
        }
    }
}