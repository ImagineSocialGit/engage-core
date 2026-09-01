<?php

namespace App\Support\ModuleIntegrations\Scheduling\Automation;

use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ReconcileAppointmentTasks
{
    public function handle(AutomationEventRecorded $recorded): void
    {
        if ($recorded->event->eventKey === 'appointment.rescheduled') {
            $this->rebase(
                (int) ($recorded->event->payload['original_appointment_id'] ?? 0),
                (int) ($recorded->event->payload['replacement_appointment_id'] ?? 0),
            );
        }

        if ($recorded->event->eventKey === 'appointment.canceled') {
            $this->cancel((int) ($recorded->event->payload['appointment_id'] ?? 0));
        }
    }

    private function rebase(int $originalId, int $replacementId): void
    {
        if ($originalId < 1 || $replacementId < 1) {
            return;
        }

        $replacement = Appointment::query()->with('schedulingHost.hostable')->find($replacementId);

        if (! $replacement instanceof Appointment || $replacement->starts_at === null) {
            return;
        }

        DB::transaction(function () use ($originalId, $replacement): void {
            $this->taskLinks($originalId)->lockForUpdate()->get()
                ->each(function (TaskLink $link) use ($replacement): void {
                    $task = $link->task;

                    if (! $task instanceof Task || ! $task->isOpen()) {
                        return;
                    }

                    $automation = data_get($task->meta, 'appointment_automation', []);
                    $offset = (int) ($automation['offset_minutes'] ?? 0);
                    $assignToHost = (bool) ($automation['assign_to_host'] ?? false);
                    $assignee = $assignToHost ? $replacement->schedulingHost?->hostable : null;
                    $meta = is_array($task->meta) ? $task->meta : [];
                    data_set($meta, 'appointment_automation.appointment_id', (int) $replacement->getKey());

                    $task->forceFill(array_filter([
                        'due_at' => CarbonImmutable::instance($replacement->starts_at)->utc()->addMinutes($offset),
                        'assigned_to_type' => $assignToHost && $assignee instanceof Model ? $assignee->getMorphClass() : null,
                        'assigned_to_id' => $assignToHost && $assignee instanceof Model ? $assignee->getKey() : null,
                        'meta' => $meta,
                    ], fn (mixed $value, string $key): bool => ! in_array($key, ['assigned_to_type', 'assigned_to_id'], true) || $assignToHost, ARRAY_FILTER_USE_BOTH))->save();

                    $link->forceFill(['linkable_id' => $replacement->getKey()])->save();
                });
        });
    }

    private function cancel(int $appointmentId): void
    {
        if ($appointmentId < 1) {
            return;
        }

        DB::transaction(function () use ($appointmentId): void {
            $this->taskLinks($appointmentId)->lockForUpdate()->get()
                ->each(function (TaskLink $link): void {
                    $task = $link->task;

                    if ($task instanceof Task && $task->isOpen()) {
                        $task->forceFill([
                            'status' => Task::STATUS_CANCELED,
                            'canceled_at' => now(),
                            'canceled_reason' => 'Appointment canceled',
                        ])->save();
                    }
                });
        });
    }

    private function taskLinks(int $appointmentId): Builder
    {
        return TaskLink::query()
            ->with('task')
            ->where('linkable_type', (new Appointment())->getMorphClass())
            ->where('linkable_id', $appointmentId)
            ->where('role', TaskLink::ROLE_SUBJECT)
            ->whereHas('task', fn (Builder $query): Builder => $query
                ->where('status', Task::STATUS_OPEN)
                ->where('meta->appointment_automation->kind', 'appointment_task'));
    }
}