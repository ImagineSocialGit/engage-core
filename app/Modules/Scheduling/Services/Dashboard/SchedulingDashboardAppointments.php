<?php

namespace App\Modules\Scheduling\Services\Dashboard;

use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentAttendee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class SchedulingDashboardAppointments
{
    /**
     * @return array{
     *     count: int,
     *     pending_count: int,
     *     appointments: Collection<int, Appointment>
     * }
     */
    public function forLocalDay(int $daysFromToday, int $limit = 8): array
    {
        [$start, $end] = $this->dayBounds($daysFromToday);
        $query = $this->operationalQuery($start, $end);

        return [
            'count' => (clone $query)->count(),
            'pending_count' => (clone $query)
                ->where('status', Appointment::STATUS_PENDING)
                ->count(),
            'appointments' => $query
                ->orderBy('starts_at')
                ->orderBy('id')
                ->limit(max(1, $limit))
                ->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function item(Appointment $appointment, string $label): array
    {
        $attendee = $this->attendeeName($appointment);
        $service = $this->stringValue($appointment->bookableService?->name);
        $host = $this->stringValue($appointment->schedulingHost?->name);
        $appointmentTitle = $this->stringValue($appointment->title);
        $description = $this->stringValue($appointment->description);

        return [
            'key' => (string) $appointment->getKey(),
            'sort_at' => $appointment->starts_at,
            'priority_reason' => $appointment->status === Appointment::STATUS_PENDING
                ? 'confirmation_needed'
                : 'scheduled',
            'label' => $appointment->status === Appointment::STATUS_PENDING
                ? 'Needs confirmation'
                : $label,
            'tone' => $appointment->status === Appointment::STATUS_PENDING
                ? 'amber'
                : ($appointment->status === Appointment::STATUS_CONFIRMED ? 'emerald' : 'blue'),
            'title' => $attendee ?? $appointmentTitle ?? $service ?? 'Appointment',
            'subtitle' => trim(implode(' · ', array_filter([
                $this->timeLabel($appointment),
                $service,
                $host ? 'With '.$host : null,
            ]))),
            'description' => $description
                ?? (($appointmentTitle !== null && $appointmentTitle !== $attendee) ? $appointmentTitle : null),
            'href' => route('crm.scheduling.appointments.show', $appointment),
            'action_label' => 'Open appointment',
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function dayBounds(int $daysFromToday): array
    {
        $local = now($this->clientTimezone())->addDays($daysFromToday);

        return [
            $local->copy()->startOfDay()->utc(),
            $local->copy()->endOfDay()->utc(),
        ];
    }

    private function operationalQuery(Carbon $start, Carbon $end): Builder
    {
        return Appointment::query()
            ->with([
                'contact',
                'bookableService',
                'schedulingHost',
                'attendees',
            ])
            ->whereIn('status', [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_SCHEDULED,
                Appointment::STATUS_CONFIRMED,
            ])
            ->where('starts_at', '<=', $end)
            ->where('ends_at', '>=', $start);
    }

    private function attendeeName(Appointment $appointment): ?string
    {
        $contactName = $this->contactName($appointment->contact);

        if ($contactName !== null) {
            return $contactName;
        }

        $primary = $appointment->attendees
            ->first(fn (AppointmentAttendee $attendee): bool => $attendee->role === 'primary')
            ?? $appointment->attendees->first();

        return $primary instanceof AppointmentAttendee
            ? ($this->stringValue($primary->name)
                ?? $this->stringValue($primary->email)
                ?? $this->stringValue($primary->phone))
            : null;
    }

    private function contactName(mixed $contact): ?string
    {
        if ($contact === null) {
            return null;
        }

        $name = $this->stringValue($contact->name ?? null);

        if ($name !== null) {
            return $name;
        }

        $firstLast = trim(implode(' ', array_filter([
            $this->stringValue($contact->first_name ?? null),
            $this->stringValue($contact->last_name ?? null),
        ])));

        return $firstLast !== ''
            ? $firstLast
            : $this->stringValue($contact->email ?? null);
    }

    private function timeLabel(Appointment $appointment): string
    {
        $timezone = $this->clientTimezone();
        $startsAt = $appointment->starts_at?->copy()->timezone($timezone);
        $endsAt = $appointment->ends_at?->copy()->timezone($timezone);

        if ($startsAt === null) {
            return 'Time unavailable';
        }

        if ($endsAt === null) {
            return $startsAt->format('g:i A T');
        }

        if ($startsAt->isSameDay($endsAt)) {
            return $startsAt->format('g:i A').'–'.$endsAt->format('g:i A T');
        }

        return $startsAt->format('M j, g:i A').'–'.$endsAt->format('M j, g:i A T');
    }

    private function clientTimezone(): string
    {
        $timezone = config('client.timezone', config('app.timezone', 'UTC'));

        return is_string($timezone) && trim($timezone) !== ''
            ? trim($timezone)
            : 'UTC';
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}