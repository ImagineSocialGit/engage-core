<?php

namespace App\Integrations\Webinars\Zoom\Mappers;

use App\Modules\Webinars\Data\WebinarAttendanceRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ZoomAttendanceMapper
{
    public function map(Collection $participants): Collection
    {
        return $participants
            ->map(fn (array $participant) => $this->mapParticipant($participant))
            ->values();
    }

    protected function mapParticipant(array $participant): WebinarAttendanceRecord
    {
        $email = $participant['user_email'] ?? $participant['email'] ?? null;

        return new WebinarAttendanceRecord(
            registrantId: isset($participant['registrant_id']) ? (string) $participant['registrant_id'] : null,
            email: filled($email) ? mb_strtolower(trim((string) $email)) : null,
            status: 'attended',
            duration: isset($participant['duration']) ? (int) $participant['duration'] : null,
            joinTime: $this->parseDateTime($participant['join_time'] ?? null),
            leaveTime: $this->parseDateTime($participant['leave_time'] ?? null),
            raw: $this->filterEmptyValues($participant),
        );
    }

    protected function parseDateTime(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (! filled($value)) {
            return null;
        }

        return Carbon::parse($value);
    }

    protected function filterEmptyValues(array $values): array
    {
        return collect($values)
            ->reject(fn ($value) => $value === '' || $value === null)
            ->all();
    }
}