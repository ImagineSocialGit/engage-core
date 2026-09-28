<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Data\EventActionContext;
use App\Modules\Events\Data\EventDefinitionContribution;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Exceptions\EventActionBlockedException;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\EventAutomationSignalRecorder;
use App\Modules\Events\Services\EventDefinitionRegistry;
use App\Modules\Events\Services\EventDuplicateDetector;
use Illuminate\Support\Facades\DB;

final class CreateEventAction
{
    public function __construct(
        private readonly EventDefinitionRegistry $definitions,
        private readonly EventDuplicateDetector $duplicates,
        private readonly EventAutomationSignalRecorder $signals,
    ) {}

    /**
     * @param array<string, mixed> $attributes
     */
    public function handle(
        array $attributes,
        EventActionContext $context,
        bool $confirmDuplicate = false,
    ): Event {
        return DB::transaction(function () use (
            $attributes,
            $context,
            $confirmDuplicate,
        ): Event {
            unset(
                $attributes['id'],
                $attributes['status'],
                $attributes['primary_external_reference_id'],
                $attributes['created_at'],
                $attributes['updated_at'],
                $attributes['deleted_at'],
            );

            $event = new Event();
            $event->fill($attributes);
            $event->status = EventStatus::Draft;

            $this->validateTypeKey($event);

            $similar = $this->duplicates->similar($event);

            if ($similar->isNotEmpty() && ! $confirmDuplicate) {
                throw new EventActionBlockedException(
                    blocker: 'duplicate_confirmation_required',
                    context: [
                        'event_ids' => $similar->modelKeys(),
                    ],
                );
            }

            $event->save();
            $this->signals->recordCreated($event, $context);

            return $event->refresh();
        }, 3);
    }

    private function validateTypeKey(Event $event): void
    {
        if ($event->type_key === null) {
            return;
        }

        $typeKey = trim((string) $event->type_key);

        if ($typeKey === '') {
            $event->type_key = null;

            return;
        }

        if (! $this->definitions->has(
            EventDefinitionContribution::CATEGORY_EVENT_TYPE,
            $typeKey,
        )) {
            throw new EventActionBlockedException(
                blocker: 'event_type_unregistered',
                context: ['type_key' => $typeKey],
            );
        }

        $event->type_key = $typeKey;
    }
}