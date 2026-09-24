<?php

namespace App\Modules\Events\Services;

use App\Modules\Events\Models\Event;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class EventDuplicateDetector
{
    /**
     * @return Collection<int, Event>
     */
    public function similar(Event $event): Collection
    {
        $signature = $this->signature($event);

        if ($signature === null || $event->starts_at === null) {
            return collect();
        }

        $windowStart = $event->starts_at->copy()->subDays(2);
        $windowEnd = $event->starts_at->copy()->addDays(2);

        return Event::query()
            ->whereBetween('starts_at', [$windowStart, $windowEnd])
            ->when(
                $event->exists,
                fn ($query) => $query->whereKeyNot($event->getKey()),
            )
            ->get()
            ->filter(
                fn (Event $candidate): bool => $this->signature($candidate) === $signature,
            )
            ->values();
    }

    /**
     * @return array{title: string, venue_name: string, city: string, local_start: string}|null
     */
    private function signature(Event $event): ?array
    {
        if ($event->starts_at === null
            || ! $this->validTimezone($event->timezone)
        ) {
            return null;
        }

        $title = $this->normalize($event->title);
        $venueName = $this->normalize($event->venue_name);
        $city = $this->normalize($event->city);

        if ($title === '' || $venueName === '' || $city === '') {
            return null;
        }

        return [
            'title' => $title,
            'venue_name' => $venueName,
            'city' => $city,
            'local_start' => $event->starts_at
                ->copy()
                ->setTimezone($event->timezone)
                ->format('Y-m-d H:i'),
        ];
    }

    private function normalize(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return Str::lower(Str::squish($value));
    }

    private function validTimezone(mixed $timezone): bool
    {
        return is_string($timezone)
            && trim($timezone) !== ''
            && in_array(trim($timezone), DateTimeZone::listIdentifiers(), true);
    }
}