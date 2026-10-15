<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Data\EventDefinitionContribution;
use App\Modules\Events\Exceptions\EventActionBlockedException;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventStakeholder;
use App\Modules\Events\Services\EventDefinitionRegistry;
use Illuminate\Support\Facades\DB;

final class SaveEventStakeholderAction
{
    public function __construct(
        private readonly EventDefinitionRegistry $definitions,
    ) {}

    /**
     * @param array<string, mixed> $attributes
     */
    public function handle(
        Event $event,
        array $attributes,
        ?EventStakeholder $stakeholder = null,
    ): EventStakeholder {
        $roleKey = is_string($attributes['role_key'] ?? null)
            ? trim((string) $attributes['role_key'])
            : '';

        if (! $this->definitions->has(
            EventDefinitionContribution::CATEGORY_STAKEHOLDER_ROLE,
            $roleKey,
        )) {
            throw new EventActionBlockedException(
                blocker: 'stakeholder_role_unregistered',
                context: ['role_key' => $roleKey],
            );
        }

        return DB::transaction(function () use (
            $event,
            $stakeholder,
            $attributes,
            $roleKey,
        ): EventStakeholder {
            $currentEvent = Event::query()
                ->lockForUpdate()
                ->findOrFail($event->getKey());
            $currentStakeholder = $stakeholder !== null
                ? EventStakeholder::query()
                    ->lockForUpdate()
                    ->findOrFail($stakeholder->getKey())
                : null;

            if ($currentStakeholder instanceof EventStakeholder
                && (int) $currentStakeholder->event_id !== (int) $currentEvent->getKey()
            ) {
                throw new EventActionBlockedException(
                    blocker: 'stakeholder_event_mismatch',
                );
            }

            $currentStakeholder ??= new EventStakeholder([
                'event_id' => $currentEvent->getKey(),
            ]);

            $currentStakeholder->fill([
                'role_key' => $roleKey,
                'name' => trim((string) ($attributes['name'] ?? '')),
                'organization' => $this->nullableString($attributes['organization'] ?? null),
                'email' => $this->nullableString($attributes['email'] ?? null),
                'phone' => $this->nullableString($attributes['phone'] ?? null),
                'notes' => $this->nullableString($attributes['notes'] ?? null),
            ]);
            $currentStakeholder->save();

            return $currentStakeholder->refresh();
        }, 3);
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