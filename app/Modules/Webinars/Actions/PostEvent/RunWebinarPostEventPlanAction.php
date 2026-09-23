<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Webinars\Jobs\PostEvent\ProcessWebinarPostEventPlanRuleJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarPostEventPlanService;
use Illuminate\Support\Carbon;

final class RunWebinarPostEventPlanAction
{
    public function __construct(private readonly WebinarPostEventPlanService $plans) {}

    public function execute(Webinar $webinar, string $event): void
    {
        $webinar = $webinar->fresh(['webinarSeries']) ?? $webinar;
        $plan = $this->plans->activeFor($webinar);
        $recordedAt = data_get($webinar->meta, 'normalized.post_event.attendance_recorded_at');

        if ($plan === null || ! is_string($recordedAt) || $recordedAt === '') {
            return;
        }

        if (data_get($webinar->meta, 'post_event_plan.scheduled_activation') === $plan['activated_at']) {
            return;
        }

        $eventAt = now()->utc();

        foreach ($this->plans->rules($plan) as $id => $rule) {
            if (! is_array($rule) || ($rule['enabled'] ?? false) !== true) {
                continue;
            }

            if (is_string($rule['effective_at'] ?? null)
                && $webinar->ends_at->lessThan(Carbon::parse($rule['effective_at']))) {
                continue;
            }

            $trigger = $rule['trigger'] ?? null;
            if ($trigger === 'webinar.recording_completed' && $event !== 'webinar.recording_completed') {
                continue;
            }

            $triggerAt = match ($trigger) {
                'webinar.ended' => $webinar->ends_at,
                'webinar.attendance_reconciled' => Carbon::parse($recordedAt),
                'webinar.recording_completed' => $eventAt,
                default => null,
            };

            if (! $triggerAt) {
                continue;
            }

            $revision = (int) ($rule['revision'] ?? 1);
            $marker = data_get($webinar->meta, 'post_event_plan.scheduled_rules.'.$id);
            if (is_array($marker) && ($marker['activation'] ?? null) === $plan['activated_at']
                && (int) ($marker['revision'] ?? 0) === $revision) {
                continue;
            }

            $dueAt = $this->dueAt(Carbon::parse($triggerAt), $rule);
            WebinarRegistration::query()
                ->where('webinar_id', $webinar->getKey())
                ->whereNull('cancelled_at')
                ->orderBy('id')
                ->chunkById(100, function ($registrations) use ($plan, $id, $revision, $dueAt): void {
                    foreach ($registrations as $registration) {
                        ProcessWebinarPostEventPlanRuleJob::dispatch(
                            (int) $registration->getKey(),
                            (string) $plan['activated_at'],
                            (string) $id,
                            $revision,
                            $dueAt->toIso8601String(),
                        )->delay($dueAt->isFuture() ? $dueAt : now())
                            ->onQueue('post_event');
                    }
                });

            $webinar->refresh();
            $meta = is_array($webinar->meta) ? $webinar->meta : [];
            data_set($meta, 'post_event_plan.scheduled_rules.'.$id, [
                'activation' => $plan['activated_at'],
                'revision' => $revision,
                'scheduled_at' => now()->toIso8601String(),
            ]);
            $webinar->forceFill(['meta' => $meta])->save();
        }
    }

    /** @param array<string, mixed> $rule */
    public function dueAt(Carbon $triggerAt, array $rule): Carbon
    {
        $due = $triggerAt->copy()->timezone((string) config('client.timezone', config('app.timezone', 'UTC')));
        $value = (int) ($rule['delay_value'] ?? 0);

        if (($rule['delay_unit'] ?? null) === 'days') {
            $due->addDays($value);
            $time = $rule['send_time'] ?? null;
            if (is_string($time) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $due->setTime($hour, $minute);
            }
        } else {
            $due->addMinutes($value);
        }

        return $due->utc();
    }
}