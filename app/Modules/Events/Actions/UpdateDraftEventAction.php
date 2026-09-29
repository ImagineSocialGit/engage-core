<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Data\EventDefinitionContribution;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Exceptions\EventActionBlockedException;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\EventDefinitionRegistry;
use App\Modules\Events\Services\EventDuplicateDetector;
use Illuminate\Support\Facades\DB;

final class UpdateDraftEventAction
{
    public function __construct(
        private readonly EventDefinitionRegistry $definitions,
        private readonly EventDuplicateDetector $duplicates,
    ) {}

    /**
     * @param array<string, mixed> $attributes
     */
    public function handle(
        Event $event,
        array $attributes,
        bool $confirmDuplicate = false,
    ): Event {
        return DB::transaction(function () use (
            $event,
            $attributes,
            $confirmDuplicate,
        ): Event {
            $current = Event::query()
                ->lockForUpdate()
                ->findOrFail($event->getKey());

            if ($current->status !== EventStatus::Draft) {
                throw new EventActionBlockedException(
                    blocker: 'event_not_draft_editable',
                    context: [
                        'status' => $current->status->value,
                    ],
                );
            }

            unset(
                $attributes['id'],
                $attributes['status'],
                $attributes['primary_external_reference_id'],
                $attributes['created_at'],
                $attributes['updated_at'],
                $attributes['deleted_at'],
            );

            $current->fill($attributes);
            $this->validateTypeKey($current);

            $similar = $this->duplicates->similar($current);

            if ($similar->isNotEmpty() && ! $confirmDuplicate) {
                throw new EventActionBlockedException(
                    blocker: 'duplicate_confirmation_required',
                    context: [
                        'event_ids' => $similar->modelKeys(),
                    ],
                );
            }

            $current->save();

            return $current->refresh();
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