<?php

namespace App\Modules\Scheduling\Services\Contacts\Filters;

use App\Modules\Core\Contracts\Contacts\ContactFilterCriterion;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Database\Eloquent\Builder;

final class AppointmentStateContactFilterCriterion implements ContactFilterCriterion
{
    private const UPCOMING = 'upcoming';
    private const AWAITING_CONFIRMATION = 'awaiting_confirmation';

    public function key(): string
    {
        return 'appointment_state';
    }

    public function sortOrder(): int
    {
        return 70;
    }

    public function label(): string
    {
        return 'Appointments';
    }

    public function help(): ?string
    {
        return 'Find contacts with an upcoming appointment or an appointment still awaiting confirmation.';
    }

    public function options(): array
    {
        return [
            ['value' => self::UPCOMING, 'label' => 'Has upcoming appointment'],
            ['value' => self::AWAITING_CONFIRMATION, 'label' => 'Awaiting confirmation'],
        ];
    }

    public function normalize(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $allowed = [self::UPCOMING, self::AWAITING_CONFIRMATION];

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): ?string => is_string($value) && in_array(trim($value), $allowed, true)
                ? trim($value)
                : null,
            $values,
        ))));
    }

    public function apply(Builder $query, array $values): void
    {
        $includeAnyUpcoming = in_array(self::UPCOMING, $values, true);
        $includeAwaitingConfirmation = in_array(self::AWAITING_CONFIRMATION, $values, true);

        $query->whereExists(function ($subquery) use ($includeAnyUpcoming, $includeAwaitingConfirmation): void {
            $subquery
                ->selectRaw('1')
                ->from('appointments')
                ->whereColumn('appointments.contact_id', 'contacts.id')
                ->whereNull('appointments.deleted_at')
                ->where('appointments.starts_at', '>=', now());

            if ($includeAnyUpcoming) {
                $subquery->whereIn('appointments.status', [
                    Appointment::STATUS_PENDING,
                    Appointment::STATUS_SCHEDULED,
                    Appointment::STATUS_CONFIRMED,
                ]);

                return;
            }

            if ($includeAwaitingConfirmation) {
                $subquery->where('appointments.status', Appointment::STATUS_PENDING);
            }
        });
    }
}