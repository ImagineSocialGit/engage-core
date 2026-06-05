<?php

namespace App\Integrations\Webinars\Zoom\Mappers;

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

    protected function mapParticipant(array $participant): array
    {
        $email = $participant['user_email'] ?? $participant['email'] ?? null;

        return [
            'registrant_id' => $participant['registrant_id'] ?? null,
            'email' => filled($email) ? mb_strtolower(trim($email)) : null,
            'status' => 'attended',
            'duration' => $participant['duration'] ?? null,
            'join_time' => $this->parseDateTime($participant['join_time'] ?? null),
            'leave_time' => $this->parseDateTime($participant['leave_time'] ?? null),
            'raw' => $this->filterEmptyValues($participant),
        ];
    }

    protected function parseDateTime(?string $value): ?Carbon
    {
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
