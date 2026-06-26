<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\InternalNotifications\Actions\ScheduleInternalNotificationAction;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskAssignedRecipientsResolver;
use App\Modules\Tasks\Services\TaskRelatedSubjectResolver;
use App\Modules\InternalNotifications\Services\InternalNotificationRecipient;

class NotifyAssignedTaskRecipientsAction
{
    public function __construct(
        private readonly TaskAssignedRecipientsResolver $assignedRecipientsResolver,
        private readonly TaskRelatedSubjectResolver $relatedSubjectResolver,
        private readonly ScheduleInternalNotificationAction $scheduleInternalNotification,
    ) {}

    public function handle(Task $task): void
    {
        $recipients = $this->assignedRecipientsResolver->resolve($task);

        if ($recipients->isEmpty()) {
            return;
        }

        $relatedSubject = $this->relatedSubjectResolver->resolve($task);

        foreach ($recipients as $recipient) {
            $this->scheduleInternalNotification->handle(
                recipient: $recipient,
                scope: 'crm_tasks',
                messageType: 'task_assigned',
                content: $this->content($task, $recipient, $relatedSubject),
                context: $task,
                dedupeKey: $this->dedupeKey($task, $recipient),
            );
        }
    }

    /**
     * @param array{
     *     subject: object|null,
     *     type: ?string,
     *     label: string,
     *     name: string,
     *     url: ?string,
     *     details: array<string, string>
     * } $relatedSubject
     * @return array<string, mixed>
     */
    private function content(
        Task $task,
        InternalNotificationRecipient $recipient,
        array $relatedSubject,
    ): array {
        return [
            'subject' => 'New task assigned: '.$task->title,
            'headline' => 'New task assigned',
            'preheader' => 'A new task has been assigned to you.',
            'body' => [
                'A new task has been assigned to you.',
            ],
            'details' => [
                'Assigned To' => $recipient->name,
                'Task' => $task->title,
                'Description' => $task->description ?: '—',
                'Due' => $this->dueAt($task),
                $relatedSubject['label'] => $relatedSubject['name'],
                ...$relatedSubject['details'],
            ],
            'cta' => $relatedSubject['url'] ? [
                'label' => 'View '.$relatedSubject['label'],
                'url' => $relatedSubject['url'],
            ] : [],
            'sms_message' => $this->smsMessage($task, $relatedSubject),
            'meta' => [
                'task_id' => $task->id,
                'related_type' => $relatedSubject['type'],
            ],
        ];
    }

    private function smsMessage(Task $task, array $relatedSubject): string
    {
        $message = 'New task assigned: '.$task->title;

        if ($relatedSubject['name'] !== '—') {
            $message .= ' for '.$relatedSubject['name'];
        }

        if ($task->due_at) {
            $message .= '. Due '.$task->due_at
                ->timezone(config('app.timezone'))
                ->format('M j, g:i A');
        }

        return $message;
    }

    private function dueAt(Task $task): string
    {
        return $task->due_at
            ? $task->due_at
                ->timezone(config('app.timezone'))
                ->format('M j, Y g:i A T')
            : '—';
    }

    private function dedupeKey(Task $task, InternalNotificationRecipient $recipient): string
    {
        return implode(':', [
            'internal_notification',
            'task_assigned',
            $recipient->source->getMorphClass(),
            $recipient->source->getKey(),
            $task->getMorphClass(),
            $task->getKey(),
        ]);
    }
}