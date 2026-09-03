<?php

namespace App\Support\ModuleIntegrations\Scheduling\Simple;

use App\Modules\Core\Contracts\Contacts\UpdatesContactStatus;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Support\ModuleIntegrations\Scheduling\Contracts\AppointmentAfterBookingWorkspace;
use App\Support\Modules\ModuleManager;
use Illuminate\Validation\ValidationException;

final class SimpleAppointmentAfterBookingWorkspace implements AppointmentAfterBookingWorkspace
{
    public const META_KEY = 'after_booking';

    public function __construct(
        private readonly ModuleManager $modules,
    ) {}

    public function read(): array
    {
        $enabled = $this->modules->enabledKeysWithDependencies();
        $workflowAvailable = in_array('workflow', $enabled, true)
            && app()->bound(UpdatesContactStatus::class);
        $tasksAvailable = in_array('tasks', $enabled, true);

        return [
            'mode' => 'simple',
            'workflow_available' => $workflowAvailable,
            'tasks_available' => $tasksAvailable,
            'status_options' => $workflowAvailable
                ? ContactStatus::query()
                    ->active()
                    ->ordered()
                    ->get(['key', 'name'])
                    ->map(fn (ContactStatus $status): array => [
                        'value' => (string) $status->key,
                        'label' => (string) $status->name,
                    ])
                    ->values()
                    ->all()
                : [],
            'task_template_options' => $tasksAvailable
                ? TaskTemplate::query()
                    ->active()
                    ->orderBy('name')
                    ->orderBy('title')
                    ->get(['key', 'name', 'title'])
                    ->map(fn (TaskTemplate $template): array => [
                        'value' => (string) $template->key,
                        'label' => (string) ($template->name ?: $template->title),
                    ])
                    ->values()
                    ->all()
                : [],
            'services' => BookableService::query()
                ->where('status', BookableService::STATUS_ACTIVE)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (BookableService $service): array => [
                    'service' => $service,
                    'configuration' => $this->configuration($service),
                ])
                ->values()
                ->all(),
        ];
    }

    public function update(
        BookableService $service,
        array $input,
    ): void {
        if ($service->status !== BookableService::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'after_booking' => 'Choose an active appointment type.',
            ]);
        }

        $mode = trim((string) ($input['mode'] ?? ''));

        if (! in_array($mode, ['manual', 'simple'], true)) {
            throw ValidationException::withMessages([
                'mode' => 'Choose manual follow-up or simple automatic follow-up.',
            ]);
        }

        $meta = is_array($service->meta) ? $service->meta : [];

        if ($mode === 'manual') {
            unset($meta[self::META_KEY]);

            $service->forceFill([
                'meta' => $meta !== [] ? $meta : null,
            ])->save();

            return;
        }

        $enabled = $this->modules->enabledKeysWithDependencies();
        $tag = $this->nullableString($input['tag'] ?? null);
        $statusKey = $this->statusKey(
            value: $input['contact_status_key'] ?? null,
            workflowAvailable: in_array('workflow', $enabled, true)
                && app()->bound(UpdatesContactStatus::class),
        );
        $taskTemplateKey = $this->taskTemplateKey(
            value: $input['task_template_key'] ?? null,
            tasksAvailable: in_array('tasks', $enabled, true),
        );

        if ($tag === null && $statusKey === null && $taskTemplateKey === null) {
            throw ValidationException::withMessages([
                'after_booking' => 'Choose at least one simple follow-up action.',
            ]);
        }

        $meta[self::META_KEY] = array_filter([
            'version' => 1,
            'tag' => $tag,
            'contact_status_key' => $statusKey,
            'task_template_key' => $taskTemplateKey,
        ], static fn (mixed $value): bool => $value !== null);

        $service->forceFill([
            'meta' => $meta,
        ])->save();
    }

    /**
     * @return array{
     *     mode: string,
     *     tag: string|null,
     *     contact_status_key: string|null,
     *     task_template_key: string|null
     * }
     */
    private function configuration(BookableService $service): array
    {
        $configuration = data_get($service->meta, self::META_KEY, []);
        $configuration = is_array($configuration) ? $configuration : [];

        $tag = $this->nullableString($configuration['tag'] ?? null);
        $statusKey = $this->nullableString($configuration['contact_status_key'] ?? null);
        $taskTemplateKey = $this->nullableString($configuration['task_template_key'] ?? null);

        return [
            'mode' => $tag !== null || $statusKey !== null || $taskTemplateKey !== null
                ? 'simple'
                : 'manual',
            'tag' => $tag,
            'contact_status_key' => $statusKey,
            'task_template_key' => $taskTemplateKey,
        ];
    }

    private function statusKey(
        mixed $value,
        bool $workflowAvailable,
    ): ?string {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        if (! $workflowAvailable) {
            throw ValidationException::withMessages([
                'contact_status_key' => 'Workflow is not available.',
            ]);
        }

        $status = ContactStatus::query()
            ->active()
            ->where('key', $value)
            ->first();

        if (! $status instanceof ContactStatus) {
            throw ValidationException::withMessages([
                'contact_status_key' => 'Choose an active Contact status.',
            ]);
        }

        return (string) $status->key;
    }

    private function taskTemplateKey(
        mixed $value,
        bool $tasksAvailable,
    ): ?string {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        if (! $tasksAvailable) {
            throw ValidationException::withMessages([
                'task_template_key' => 'Tasks is not available.',
            ]);
        }

        $template = TaskTemplate::query()
            ->active()
            ->forKey($value)
            ->first();

        if (! $template instanceof TaskTemplate) {
            throw ValidationException::withMessages([
                'task_template_key' => 'Choose an active Task Template.',
            ]);
        }

        return (string) $template->key;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}