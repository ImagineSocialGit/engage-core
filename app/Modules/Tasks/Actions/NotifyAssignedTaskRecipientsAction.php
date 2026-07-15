<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\Tasks\Data\TaskNotification;
use App\Modules\Tasks\Data\TaskRecipient;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskAssignedRecipientsResolver;
use App\Modules\Tasks\Services\TaskLinkPresentationResolver;
use App\Modules\Tasks\Services\TaskNotificationScheduler;

class NotifyAssignedTaskRecipientsAction
{
    public const NOTIFICATION_TYPE = 'task_assigned';

    public function __construct(
        private readonly TaskAssignedRecipientsResolver $assignedRecipientsResolver,
        private readonly TaskLinkPresentationResolver $linkPresentation,
        private readonly TaskNotificationScheduler $notificationScheduler,
    ) {}

    public function handle(Task $task): void
    {
        $recipients = $this->assignedRecipientsResolver->resolve($task);

        if ($recipients->isEmpty()) {
            return;
        }

        $primaryLink = $this->linkPresentation->primary($task);

        foreach ($recipients as $recipient) {
            $this->notificationScheduler->schedule(new TaskNotification(
                recipient: $recipient,
                notificationType: self::NOTIFICATION_TYPE,
                scope: 'crm_tasks',
                messageType: self::NOTIFICATION_TYPE,
                content: $this->content($task, $recipient, $primaryLink),
                context: $task,
                dedupeKey: $this->dedupeKey($task, $recipient),
            ));
        }
    }

    /**
     * @param array{
     *     link_id: int,
     *     role: string,
     *     role_label: string,
     *     record: \Illuminate\Database\Eloquent\Model|null,
     *     type: ?string,
     *     label: string,
     *     name: string,
     *     url: ?string,
     *     details: array<string, string>
     * }|null $primaryLink
     * @return array<string, mixed>
     */
    private function content(
        Task $task,
        TaskRecipient $recipient,
        ?array $primaryLink,
    ): array {
        $details = [
            'Assigned To' => $recipient->name,
            'Task' => $task->title,
            'Description' => $task->description ?: '—',
            'Due' => $this->dueAt($task),
        ];

        if ($primaryLink) {
            $details[$primaryLink['label']] = $primaryLink['name'];
            $details = [
                ...$details,
                ...$primaryLink['details'],
            ];
        }

        return [
            'subject' => 'New task assigned: '.$task->title,
            'headline' => 'New task assigned',
            'preheader' => 'A new task has been assigned to you.',
            'body' => [
                'A new task has been assigned to you.',
            ],
            'details' => $details,
            'cta' => [
                'label' => 'Open task',
                'url' => route('crm.tasks.show', $task),
            ],
            'sms_message' => $this->smsMessage($task, $primaryLink),
            'meta' => [
                'task_id' => $task->getKey(),
                'primary_link_type' => $primaryLink['type'] ?? null,
                'primary_link_role' => $primaryLink['role'] ?? null,
            ],
        ];
    }

    /**
     * @param array{
     *     link_id: int,
     *     role: string,
     *     role_label: string,
     *     record: \Illuminate\Database\Eloquent\Model|null,
     *     type: ?string,
     *     label: string,
     *     name: string,
     *     url: ?string,
     *     details: array<string, string>
     * }|null $primaryLink
     */
    private function smsMessage(Task $task, ?array $primaryLink): string
    {
        $message = 'New task assigned: '.$task->title;

        if ($primaryLink && $primaryLink['name'] !== '—') {
            $message .= ' for '.$primaryLink['name'];
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
