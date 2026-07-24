<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Core\Actions\Contacts\ResolveContactByEmailAction;
use App\Modules\Scheduling\Data\AppointmentBookingData;
use App\Modules\Scheduling\Models\Appointment;
use InvalidArgumentException;

class CompletePublicBookingAction
{
    public function __construct(
        private readonly ConvertBookingHoldToAppointmentAction $convertHold,
        private readonly ResolveContactByEmailAction $resolveContact,
    ) {}

    public function handle(
        string $holdId,
        string $name,
        string $email,
        ?string $phone = null,
    ): Appointment {
        $holdId = $this->requiredString($holdId, 'booking hold ID', 36);
        $name = $this->requiredString($name, 'attendee name', 255);
        $email = $this->normalizedEmail($email);
        $phone = $this->nullableString($phone, 'attendee phone', 255);

        return $this->convertHold->handle(
            holdId: $holdId,
            booking: function () use ($name, $email, $phone): AppointmentBookingData {
                $contact = $this->resolveContact->handle(
                    email: $email,
                    name: $name,
                    phone: $phone,
                    source: 'public_booking',
                    subsource: 'scheduling',
                );

                return new AppointmentBookingData(
                    contact: $contact,
                    name: $name,
                    email: $email,
                    phone: $phone,
                    source: 'public_booking',
                );
            },
        );
    }

    private function normalizedEmail(string $email): string
    {
        $email = strtolower(trim($email));

        if ($email === '' || mb_strlen($email) > 255) {
            throw new InvalidArgumentException(
                'Public booking email must be a non-empty value no longer than 255 characters.',
            );
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(
                "Public booking email [{$email}] is invalid.",
            );
        }

        return $email;
    }

    private function requiredString(
        string $value,
        string $label,
        int $maximumLength,
    ): string {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(
                "A non-empty {$label} is required.",
            );
        }

        if (mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException(
                "The {$label} cannot exceed {$maximumLength} characters.",
            );
        }

        return $value;
    }

    private function nullableString(
        ?string $value,
        string $label,
        int $maximumLength,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException(
                "The {$label} cannot exceed {$maximumLength} characters.",
            );
        }

        return $value;
    }
}