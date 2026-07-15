<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\Tasks\Data\TaskNotification;
use App\Modules\Tasks\Data\TaskRecipient;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskAssignedRecipientsResolver;
use App\Modules\Tasks\Services\TaskNotificationScheduler;
use App\Modules\Tasks\Services\TaskRelatedSubjectResolver;

class NotifyAssignedTaskRecipientsAction
{
    public const NOTIFICATION_TYPE = 'task_assigned';

    public function __construct(
        private readonly TaskAssignedRecipientsResolver $assignedRecipientsResolver,
        private readonly TaskRelatedSubjectResolver $relatedSubjectResolver,
        private readonly TaskNotificationScheduler $notificationScheduler,
    ) {}

    public function handle(Task $task): void
    {
        $recipients = $this->assignedRecipientsResolver->resolve($task);

        if ($recipients->isEmpty()) {
            return;
        }

        $relatedSubject = $this->relatedSubjectResolver->resolve($task);

        foreach ($recipients as $recipient) {
            $this->notificationScheduler->schedule(new TaskNotification(
                recipient: $recipient,
                notificationType: self::NOTIFICATION_TYPE,
                scope: 'crm_tasks',
                messageType: self::NOTIFICATION_TYPE,
                content: $this->content($task, $recipient, $relatedSubject),
                context: $task,
                dedupeKey: $this->dedupeKey($task, $recipient),
            ));
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
        TaskRecipient $recipient,
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

    /**
     * @param array{
     *     subject: object|null,
     *     type: ?string,
     *     label: string,
     *     name: string,
     *     url: ?string,
     *     details: array<string, string>
     * } $relatedSubject
     */
    private function smsMessage(Task $task, array $relatedSubject): string
    {
        $message = 'New task assigned: '.$task->title;

        if ($relatedSubject['name'] !== '—') {
            $message .= ' for '.$relatedSubject['name'];
        }

        if ($task->due_at) {
            $message .= '. Due '.$task->due_at
                ->timezone(config('client.timezone', config('app.timezone', 'UTC')))
                ->format('M j, g:i A');
        }

        return $message;
    }

    private function dueAt(Task $task): string
    {
        return $task->due_at
            ? $task->due_at
                ->timezone(config('client.timezone', config('app.timezone', 'UTC')))
                ->format('M j, Y g:i A T')
            : '—';
    }

    private function dedupeKey(Task $task, TaskRecipient $recipient): string
    {
        return implode(':', [
            'task_notification',
            self::NOTIFICATION_TYPE,
            $recipient->source->getMorphClass(),
            $recipient->source->getKey(),
            $task->getMorphClass(),
            $task->getKey(),
        ]);
    }
}
