<?php

namespace App\Support\ModuleIntegrations\Forms\Events;

use App\Modules\Events\Models\Event;
use App\Modules\Forms\Contracts\HostedFormContextResolver;
use App\Modules\Forms\Data\FormSubmissionContext;
use App\Modules\Forms\Data\HostedFormContextReference;

final class EventHostedFormContextResolver implements HostedFormContextResolver
{
    public const KEY = 'event';

    public function key(): string
    {
        return self::KEY;
    }

    public function resolve(
        HostedFormContextReference $reference,
    ): ?FormSubmissionContext {
        if ($reference->key !== self::KEY
            || preg_match('/^[1-9][0-9]*$/D', $reference->reference) !== 1
        ) {
            return null;
        }

        $event = Event::query()->find((int) $reference->reference);

        if (! $event instanceof Event) {
            return null;
        }

        return new FormSubmissionContext(
            reference: $reference,
            subject: $event,
            attributes: [
                'event' => [
                    'id' => (int) $event->getKey(),
                    'type_key' => $event->type_key,
                    'title' => $event->title,
                    'starts_at' => $event->starts_at?->toAtomString(),
                    'timezone' => $event->timezone,
                    'venue_name' => $event->venue_name,
                    'city' => $event->city,
                    'region' => $event->region,
                    'postal_code' => $event->postal_code,
                    'country' => $event->country,
                ],
            ],
        );
    }
}