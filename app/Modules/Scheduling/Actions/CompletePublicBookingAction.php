<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Core\Actions\Contacts\ResolveContactByEmailAction;
use App\Modules\Scheduling\Data\AppointmentBookingData;
use App\Modules\Scheduling\Models\Appointment;
use App\Support\ModuleIntegrations\Scheduling\Contracts\AppointmentCommunications;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CompletePublicBookingAction
{
    public function __construct(
        private readonly ConvertBookingHoldToAppointmentAction $convertHold,
        private readonly ResolveContactByEmailAction $resolveContact,
        private readonly AppointmentCommunications $appointmentCommunications,
    ) {}

    /** @param array<string, string> $disclosure */
    public function handle(
        string $holdId,
        string $firstName,
        string $lastName,
        string $email,
        ?string $phone = null,
        ?string $publicSubmissionAttemptId = null,
        array $disclosure = [],
        ?string $sourceIp = null,
        ?string $userAgent = null,
    ): Appointment {
        $holdId = $this->requiredString($holdId, 'booking hold ID', 36);
        $firstName = $this->requiredString($firstName, 'attendee first name', 120);
        $lastName = $this->requiredString($lastName, 'attendee last name', 120);
        $name = $firstName.' '.$lastName;
        $email = $this->normalizedEmail($email);
        $phone = $this->nullableString($phone, 'attendee phone', 255);
        $publicSubmissionAttemptId = $this->attemptId($publicSubmissionAttemptId);
        $disclosure = $this->disclosure($disclosure);

        $appointment = $this->convertHold->handle(
            holdId: $holdId,
            booking: function () use (
                $firstName,
                $lastName,
                $name,
                $email,
                $phone,
                $publicSubmissionAttemptId,
                $disclosure,
            ): AppointmentBookingData {
                $contact = $this->resolveContact->handle(
                    email: $email,
                    name: $name,
                    phone: $phone,
                    source: 'public_booking',
                    subsource: 'scheduling',
                );

                if ($contact->wasRecentlyCreated) {
                    $contact->forceFill([
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                    ])->save();
                }

                return new AppointmentBookingData(
                    contact: $contact,
                    name: $name,
                    email: $email,
                    phone: $phone,
                    source: 'public_booking',
                    appointmentMeta: [
                        'reporting' => array_filter([
                            'public_submission_attempt_id' => $publicSubmissionAttemptId,
                        ], static fn (mixed $value): bool => $value !== null),
                        'public_booking_disclosure' => [
                            ...$disclosure,
                            'accepted_at' => CarbonImmutable::now('UTC')->toISOString(),
                        ],
                    ],
                );
            },
        );

        $this->appointmentCommunications->publicBookingCompleted(
            appointment: $appointment,
            sourceIp: $sourceIp,
            userAgent: $userAgent,
        );

        return $appointment;
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
            throw new InvalidArgumentException("A non-empty {$label} is required.");
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

    private function attemptId(?string $attemptId): ?string
    {
        if ($attemptId === null || trim($attemptId) === '') {
            return null;
        }

        $attemptId = strtolower(trim($attemptId));

        if (! Str::isUuid($attemptId)) {
            throw new InvalidArgumentException('Public booking Reporting attempt ID must be a UUID.');
        }

        return $attemptId;
    }

    /**
     * @param array<string, string> $disclosure
     * @return array<string, string>
     */
    private function disclosure(array $disclosure): array
    {
        $normalized = [];

        foreach (['key', 'version', 'text_hash'] as $field) {
            $value = $disclosure[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $normalized[$field] = mb_substr(trim($value), 0, 255);
            }
        }

        return $normalized;
    }
}