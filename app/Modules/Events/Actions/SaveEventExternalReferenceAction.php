<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Data\EventDefinitionContribution;
use App\Modules\Events\Exceptions\EventActionBlockedException;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventExternalReference;
use App\Modules\Events\Services\EventDefinitionRegistry;
use Illuminate\Support\Facades\DB;

final class SaveEventExternalReferenceAction
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
        ?EventExternalReference $reference = null,
    ): EventExternalReference {
        $providerKey = $this->requiredString($attributes['provider_key'] ?? null);
        $referenceType = $this->requiredString($attributes['reference_type'] ?? null);
        $externalId = $this->nullableString($attributes['external_id'] ?? null);
        $url = $this->nullableString($attributes['url'] ?? null);
        $label = $this->nullableString($attributes['label'] ?? null);

        if (! $this->definitions->has(
            EventDefinitionContribution::CATEGORY_EXTERNAL_REFERENCE_PROVIDER,
            $providerKey,
        )) {
            throw new EventActionBlockedException(
                blocker: 'external_reference_provider_unregistered',
                context: ['provider_key' => $providerKey],
            );
        }

        if (! $this->definitions->has(
            EventDefinitionContribution::CATEGORY_EXTERNAL_REFERENCE_TYPE,
            $referenceType,
        )) {
            throw new EventActionBlockedException(
                blocker: 'external_reference_type_unregistered',
                context: ['reference_type' => $referenceType],
            );
        }

        if ($externalId === null && $url === null) {
            throw new EventActionBlockedException(
                blocker: 'external_reference_target_missing',
            );
        }

        return DB::transaction(function () use (
            $event,
            $reference,
            $providerKey,
            $referenceType,
            $externalId,
            $url,
            $label,
        ): EventExternalReference {
            $currentEvent = Event::query()
                ->lockForUpdate()
                ->findOrFail($event->getKey());

            $currentReference = $reference !== null
                ? EventExternalReference::query()
                    ->lockForUpdate()
                    ->findOrFail($reference->getKey())
                : null;

            if ($currentReference instanceof EventExternalReference
                && (int) $currentReference->event_id !== (int) $currentEvent->getKey()
            ) {
                throw new EventActionBlockedException(
                    blocker: 'external_reference_event_mismatch',
                );
            }

            if ($externalId !== null) {
                $conflict = EventExternalReference::withTrashed()
                    ->where('provider_key', $providerKey)
                    ->where('reference_type', $referenceType)
                    ->where('external_id', $externalId)
                    ->when(
                        $currentReference instanceof EventExternalReference,
                        fn ($query) => $query->where('id', '<>', $currentReference->getKey()),
                    )
                    ->exists();

                if ($conflict) {
                    throw new EventActionBlockedException(
                        blocker: 'external_reference_identity_conflict',
                    );
                }
            }

            $currentReference ??= new EventExternalReference([
                'event_id' => $currentEvent->getKey(),
            ]);

            $currentReference->fill([
                'provider_key' => $providerKey,
                'reference_type' => $referenceType,
                'external_id' => $externalId,
                'url' => $url,
                'label' => $label,
            ]);
            $currentReference->save();

            return $currentReference->refresh();
        }, 3);
    }

    private function requiredString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
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