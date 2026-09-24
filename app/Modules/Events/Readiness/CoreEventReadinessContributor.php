<?php

namespace App\Modules\Events\Readiness;

use App\Modules\Events\Contracts\EventReadinessContributor;
use App\Modules\Events\Data\EventReadinessFinding;
use App\Modules\Events\Enums\EventAttendanceMode;
use App\Modules\Events\Models\Event;
use DateTimeZone;

final class CoreEventReadinessContributor implements EventReadinessContributor
{
    public const CAPABILITY = 'core';

    private const REGION_REQUIRED_COUNTRIES = [
        'US',
        'CA',
    ];

    public function capability(): string
    {
        return self::CAPABILITY;
    }

    public function findings(Event $event): iterable
    {
        if (! $this->filled($event->title)) {
            yield new EventReadinessFinding(
                code: 'title_missing',
                message: 'Event title is required.',
                field: 'title',
            );
        }

        $attendanceMode = $this->attendanceMode($event);

        if ($attendanceMode === null) {
            yield new EventReadinessFinding(
                code: 'attendance_mode_invalid',
                message: 'Event attendance mode must be a supported universal value.',
                field: 'attendance_mode',
            );
        }

        if ($event->starts_at === null) {
            yield new EventReadinessFinding(
                code: 'starts_at_missing',
                message: 'Event start date and time are required.',
                field: 'starts_at',
            );
        }

        if (! $this->validTimezone($event->timezone)) {
            yield new EventReadinessFinding(
                code: 'timezone_invalid',
                message: 'Event timezone must be a valid IANA timezone.',
                field: 'timezone',
            );
        }

        if (in_array(
            $attendanceMode,
            [EventAttendanceMode::Physical, EventAttendanceMode::Hybrid],
            true,
        )) {
            yield from $this->physicalLocationFindings($event);
        }

        if ($attendanceMode === EventAttendanceMode::Virtual
            && ! $this->hasLivestreamReference($event)
        ) {
            yield new EventReadinessFinding(
                code: 'livestream_reference_missing',
                message: 'Virtual Events require a structured livestream external reference.',
                field: 'external_references',
            );
        }
    }

    /**
     * @return iterable<int, EventReadinessFinding>
     */
    private function physicalLocationFindings(Event $event): iterable
    {
        if (! $this->filled($event->venue_name)) {
            yield new EventReadinessFinding(
                code: 'venue_name_missing',
                message: 'Physical and hybrid Events require a venue name.',
                field: 'venue_name',
            );
        }

        if (! $this->filled($event->city)) {
            yield new EventReadinessFinding(
                code: 'city_missing',
                message: 'Physical and hybrid Events require a city.',
                field: 'city',
            );
        }

        $country = strtoupper(trim((string) $event->country));

        if ($country === '') {
            yield new EventReadinessFinding(
                code: 'country_missing',
                message: 'Physical and hybrid Events require a country.',
                field: 'country',
            );

            return;
        }

        if (in_array($country, self::REGION_REQUIRED_COUNTRIES, true)
            && ! $this->filled($event->region)
        ) {
            yield new EventReadinessFinding(
                code: 'region_missing',
                message: 'This Event country requires a region.',
                field: 'region',
            );
        }
    }

    private function attendanceMode(Event $event): ?EventAttendanceMode
    {
        $value = $event->getAttributes()['attendance_mode'] ?? null;

        if (! is_string($value)) {
            return null;
        }

        return EventAttendanceMode::tryFrom($value);
    }

    private function validTimezone(mixed $timezone): bool
    {
        if (! is_string($timezone) || trim($timezone) === '') {
            return false;
        }

        return in_array(trim($timezone), DateTimeZone::listIdentifiers(), true);
    }

    private function hasLivestreamReference(Event $event): bool
    {
        if (! $event->exists) {
            return false;
        }

        return $event->externalReferences()
            ->where('reference_type', 'livestream')
            ->where(function ($query): void {
                $query
                    ->where(function ($query): void {
                        $query
                            ->whereNotNull('external_id')
                            ->where('external_id', '<>', '');
                    })
                    ->orWhere(function ($query): void {
                        $query
                            ->whereNotNull('url')
                            ->where('url', '<>', '');
                    });
            })
            ->exists();
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}