<?php

namespace App\Modules\Scheduling\Data;

use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class AppointmentBookingData
{
    /**
     * @param array<string, mixed> $appointmentMeta
     * @param array<string, mixed> $attendeeMeta
     */
    public function __construct(
        public ?Contact $contact = null,
        public ?Model $primaryAttendee = null,
        ?string $name = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $title = null,
        ?string $description = null,
        public ?Model $sourceContext = null,
        public ?Model $createdBy = null,
        string $source = 'public_booking',
        public array $appointmentMeta = [],
        public array $attendeeMeta = [],
    ) {
        $this->assertPersisted($contact, 'contact');
        $this->assertPersisted($primaryAttendee, 'primary attendee');
        $this->assertPersisted($sourceContext, 'source context');
        $this->assertPersisted($createdBy, 'creator');

        $this->name = $this->nullableString($name, 'name', 255);
        $this->email = $this->nullableString($email, 'email', 255);
        $this->phone = $this->nullableString($phone, 'phone', 255);
        $this->title = $this->nullableString($title, 'title', 255);
        $this->description = $this->nullableString($description, 'description', 10000);
        $this->source = $this->requiredString($source, 'source', 100);

        if ($this->email !== null && filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(
                "Appointment booking email [{$this->email}] is invalid.",
            );
        }

        if ($this->primaryAttendee() === null
            && $this->name === null
            && $this->email === null
            && $this->phone === null
        ) {
            throw new InvalidArgumentException(
                'Appointment booking data requires a contact, a primary attendee, or an attendee snapshot.',
            );
        }
    }

    public ?string $name;
    public ?string $email;
    public ?string $phone;
    public ?string $title;
    public ?string $description;
    public string $source;

    public function primaryAttendee(): ?Model
    {
        return $this->primaryAttendee ?? $this->contact;
    }

    public function attendeeName(): ?string
    {
        return $this->name
            ?? $this->modelString($this->primaryAttendee(), ['name', 'title'])
            ?? $this->modelString($this->contact, ['name', 'email']);
    }

    public function attendeeEmail(): ?string
    {
        return $this->email
            ?? $this->modelString($this->contact, ['email'])
            ?? $this->modelString($this->primaryAttendee(), ['email']);
    }

    public function attendeePhone(): ?string
    {
        return $this->phone
            ?? $this->modelString($this->contact, ['phone'])
            ?? $this->modelString($this->primaryAttendee(), ['phone']);
    }

    private function assertPersisted(?Model $model, string $label): void
    {
        if ($model === null) {
            return;
        }

        if (! $model->exists || $model->getKey() === null) {
            throw new InvalidArgumentException(
                "Appointment booking {$label} must be persisted.",
            );
        }
    }

    /**
     * @param array<int, string> $attributes
     */
    private function modelString(?Model $model, array $attributes): ?string
    {
        if ($model === null) {
            return null;
        }

        foreach ($attributes as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function requiredString(
        string $value,
        string $label,
        int $maximumLength,
    ): string {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(
                "Appointment booking {$label} cannot be empty.",
            );
        }

        if (mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException(
                "Appointment booking {$label} cannot exceed {$maximumLength} characters.",
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
                "Appointment booking {$label} cannot exceed {$maximumLength} characters.",
            );
        }

        return $value;
    }
}